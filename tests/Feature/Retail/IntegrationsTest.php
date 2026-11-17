<?php

namespace Tests\Feature\Retail;

use App\Enums\OrderStatus;
use App\Jobs\PushToBitrix24;
use App\Jobs\SendToTelegram;
use App\Models\Category;
use App\Models\ExchangeIp;
use App\Models\ExchangeToken;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CurrentCart;
use App\Services\Integrations\Bitrix24;
use App\Services\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Заказ и заявка → Telegram, Битрикс24; заказы для 1С (GET /orders/new, confirm, reject).
 */
class IntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'TEST',
            'services.telegram.chat_id' => '-100',
            'services.bitrix24.webhook_url' => 'https://petrovich.bitrix24.by/rest/1/abc/',
        ]);

        $category = Category::create(['name' => 'Профнастил', 'slug' => 'profnastil']);
        $this->product = Product::create([
            'name' => 'Профнастил С8', 'sku' => 'BP-00001', 'price' => 30, 'stock' => 10, 'vat_rate' => 20,
            'category_id' => $category->id, 'is_active' => true, 'is_published' => true,
        ]);
        $this->product->forceFill(['unit' => 'м²'])->save();
    }

    private function placeGuestOrder(): Order
    {
        $token = $this->post("/order/add/{$this->product->id}", ['qty' => 3])->getCookie(CurrentCart::COOKIE)->getValue();

        $this->withCookie(CurrentCart::COOKIE, $token)->post('/order/checkout', [
            'checkout_token' => 't1', 'name' => 'Пётр', 'phone' => '+375291505605', 'email' => 'p@example.com',
            'delivery_method' => 'pickup_minsk', 'payment_method' => 'on_receipt', 'consent' => '1',
        ])->assertRedirect();

        return Order::with('items')->sole();
    }

    public function test_order_is_queued_to_telegram_and_bitrix(): void
    {
        Queue::fake();

        $order = $this->placeGuestOrder();

        Queue::assertPushed(SendToTelegram::class, fn ($job) => $job->subject->is($order));
        Queue::assertPushed(PushToBitrix24::class, fn ($job) => $job->subject->is($order));
    }

    public function test_nothing_queued_when_not_configured(): void
    {
        config(['services.telegram.bot_token' => null, 'services.bitrix24.webhook_url' => null]);
        Queue::fake();

        $this->placeGuestOrder();

        Queue::assertNothingPushed();
    }

    public function test_telegram_message_and_bitrix_lead_with_rows(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'petrovich.bitrix24.by/rest/1/abc/crm.lead.add.json' => Http::response(['result' => 777]),
            'petrovich.bitrix24.by/rest/1/abc/crm.lead.productrows.set.json' => Http::response(['result' => true]),
        ]);

        // Очередь sync: задачи выполняются сразу
        $order = $this->placeGuestOrder();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'botTEST/sendMessage')
            && $r['chat_id'] === '-100'
            && str_contains($r['text'], $order->number)
            && str_contains($r['text'], 'Профнастил С8')
            && str_contains($r['text'], 'Самовывоз — Минск'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'crm.lead.add.json')
            && $r['fields']['TITLE'] === 'Заказ '.$order->number.' с сайта'
            && $r['fields']['PHONE'][0]['VALUE'] === '+375291505605'
            && $r['fields']['OPPORTUNITY'] == 90.0
            && $r['fields']['CURRENCY_ID'] === 'BYN');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'crm.lead.productrows.set.json')
            && $r['id'] === '777'
            && $r['rows'][0]['QUANTITY'] == 3
            && $r['rows'][0]['PRICE'] == 30.0
            && $r['rows'][0]['MEASURE_NAME'] === 'м²');

        $this->assertSame('777', $order->fresh()->crm_id);
    }

    public function test_lead_goes_to_bitrix_once(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
            '*crm.lead.add.json' => Http::response(['result' => 55]),
        ]);

        $this->post(route('lead.store'), ['type' => 'callback', 'name' => 'Анна', 'phone' => '+375291112233', 'consent' => '1']);

        $lead = Lead::sole();
        $this->assertSame('55', $lead->crm_id);

        // Повтор задачи не создаёт второй лид
        (new PushToBitrix24($lead))->handle(app(Bitrix24::class));
        Http::assertSentCount(2); // Telegram + один crm.lead.add
    }

    public function test_bitrix_error_fails_job_for_retry(): void
    {
        Http::fake(['*crm.lead.add.json' => Http::response(['error' => 'INVALID_CREDENTIALS', 'error_description' => 'Неверный вебхук'], 401)]);
        $lead = Lead::create(['type' => 'callback', 'name' => 'А', 'phone' => '+375291112233']);

        $this->expectExceptionMessage('Неверный вебхук');
        (new PushToBitrix24($lead))->handle(app(Bitrix24::class));
    }

    public function test_1c_gets_confirmed_retail_orders_and_confirms(): void
    {
        config(['services.telegram.bot_token' => null, 'services.bitrix24.webhook_url' => null]);
        $order = $this->placeGuestOrder();
        ExchangeIp::create(['ip' => '127.0.0.1']);
        [, $plain] = ExchangeToken::issue('1С', null);
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$plain, 'REMOTE_ADDR' => '127.0.0.1'];

        // «Новый» — ещё не проверен менеджером, 1С его не видит
        $this->call('GET', '/api/1c/v1/orders/new', [], [], [], $headers)->assertOk()->assertJsonCount(0, 'orders');

        app(OrderStatusService::class)->change($order, OrderStatus::Confirmed, User::factory()->admin()->create());

        $json = $this->call('GET', '/api/1c/v1/orders/new', [], [], [], $headers)->assertOk()->json('orders.0');
        $this->assertSame($order->number, $json['number']);
        $this->assertNull($json['organization']);
        $this->assertSame(['name' => 'Пётр', 'phone' => '+375291505605', 'email' => 'p@example.com'], $json['customer']);
        $this->assertSame('pickup_minsk', $json['delivery']['method']);
        $this->assertSame('on_receipt', $json['payment_method']);
        $this->assertTrue($json['prices_include_vat']);
        $this->assertSame(90.0, $json['total']);
        $this->assertSame(15.0, $json['vat']);
        $this->assertSame(['sku' => 'BP-00001', 'qty' => 3, 'unit' => 'м²', 'price' => 30.0, 'total' => 90.0], array_intersect_key($json['items'][0], array_flip(['sku', 'qty', 'unit', 'price', 'total'])));

        $this->call('POST', "/api/1c/v1/orders/{$order->number}/confirm", ['guid_1c' => 'g-1', 'number_1c' => 'ЗК-1', 'registered_at' => '2026-11-17T12:00:00+03:00'], [], [], $headers)->assertOk();
        $this->assertSame(OrderStatus::SentTo1c, $order->fresh()->status);
        $this->call('GET', '/api/1c/v1/orders/new', [], [], [], $headers)->assertJsonCount(0, 'orders');

        $this->call('POST', "/api/1c/v1/orders/{$order->number}/confirm", ['guid_1c' => 'other'], [], [], $headers)
            ->assertStatus(409)->assertJson(['error' => 'guid_conflict']);
        $this->call('POST', '/api/1c/v1/orders/BP-0000/reject', ['reason' => 'нет'], [], [], $headers)->assertNotFound();
    }
}
