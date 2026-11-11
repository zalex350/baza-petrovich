<?php

namespace Tests\Feature;

use App\Enums\OrderExchangeState;
use App\Enums\OrderStatus;
use App\Exceptions\OrderExchangeException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderExchange;
use App\Services\OrderStatusService;
use App\Services\StockReserve;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Резерв сайта (docs/1C_EXCHANGE_SPEC.md, раздел 5, правила 4–5): stock = stock_1c − резерв;
 * оформление, отмена, подтверждение и отказ 1С, новый остаток из 1С; поля обмена заказа.
 */
class StockReserveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-11 10:00:00');

        $this->admin = User::factory()->admin()->create();
        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->product = Product::create([
            'name' => 'Grohe Start', 'sku' => 'GR-001', 'price' => 100, 'stock' => 10,
            'is_active' => true, 'is_published' => true,
        ]);
        $this->product->forceFill(['guid' => 'p-guid-1'])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function place(int $qty = 2): Order
    {
        $this->actingAs($this->client)->post("/order/add/{$this->product->id}", ['qty' => $qty]);
        $this->actingAs($this->client)->post('/order/checkout', $this->checkoutData($this->client))->assertRedirectContains('/placed');

        return Order::where('user_id', $this->client->id)->latest('id')->firstOrFail();
    }

    /** @return array{0: int, 1: int} доступный и остаток 1С */
    private function stock(): array
    {
        $p = $this->product->fresh();

        return [$p->stock, $p->stock_1c];
    }

    private function exchange(): OrderExchange
    {
        return app(OrderExchange::class);
    }

    // --- Оформление и отмена ---

    public function test_checkout_reserves_without_touching_1c_stock_and_snapshots_item(): void
    {
        $this->assertSame([10, 10], $this->stock());

        $order = $this->place(2);

        $this->assertSame(OrderExchangeState::Pending, $order->exchange_state);
        $this->assertSame([8, 10], $this->stock());

        $item = $order->items()->sole();
        $this->assertSame('p-guid-1', $item->product_guid);
        $this->assertSame('шт', $item->unit);

        // Корзина видит доступный остаток: больше 8 не оформить
        $this->actingAs($this->client)->post("/order/add/{$this->product->id}", ['qty' => 9]);
        $this->actingAs($this->client)->post('/order/checkout', $this->checkoutData($this->client));
        $this->assertSame(1, Order::count());
    }

    public function test_cancel_releases_reserve(): void
    {
        $order = $this->place(2);
        $this->place(3);
        $this->assertSame([5, 10], $this->stock());

        app(OrderStatusService::class)->change($order, OrderStatus::Cancelled, $this->admin);

        $this->assertSame([7, 10], $this->stock());
    }

    // --- Подтверждение 1С и новый остаток ---

    public function test_confirm_keeps_reserve_until_newer_1c_stock_arrives(): void
    {
        $order = $this->place(2);
        $registeredAt = Carbon::parse('2026-11-11 10:05:00');

        $order = $this->exchange()->confirm($order, 'o-guid-1', 'ЗК-000512', $registeredAt);

        $this->assertSame(OrderStatus::SentTo1c, $order->status);
        $this->assertSame(OrderExchangeState::Sent, $order->exchange_state);
        $this->assertSame(['o-guid-1', 'ЗК-000512'], [$order->guid_1c, $order->number_1c]);
        $this->assertTrue($registeredAt->eq($order->registered_at));

        $history = $order->statusHistory()->get()->last();
        $this->assertSame([OrderStatus::SentTo1c, OrderStatusService::SOURCE_1C], [$history->to_status, $history->source]);
        $this->assertTrue($registeredAt->eq($history->changed_at_1c));

        // Остаток из 1С ещё не загружался — резерв держится
        $this->assertSame([8, 10], $this->stock());

        // Остаток снят до регистрации заказа: 1С заказ ещё не учла — резерв держится
        $this->product->fresh()->forceFill(['stock_1c' => 10, 'stock_synced_at' => '2026-11-11 10:04:00'])->save();
        $this->assertSame([8, 10], $this->stock());

        // Остаток снят после регистрации: заказ уже в нём — резерв снят
        $this->product->fresh()->forceFill(['stock_1c' => 8, 'stock_synced_at' => '2026-11-11 10:06:00'])->save();
        $this->assertSame([8, 8], $this->stock());

        // Новый заказ резервирует от нового остатка 1С
        $this->place(3);
        $this->assertSame([5, 8], $this->stock());
    }

    public function test_confirm_is_idempotent_and_rejects_conflicts(): void
    {
        $order = $this->place(1);
        $at = Carbon::parse('2026-11-11 10:05:00');

        $this->exchange()->confirm($order, 'o-guid-1', 'ЗК-1', $at);
        $again = $this->exchange()->confirm($order, 'o-guid-1', 'ЗК-1', $at);
        $this->assertSame(OrderStatus::SentTo1c, $again->status);
        $this->assertSame(2, $order->statusHistory()->count());

        try {
            $this->exchange()->confirm($order, 'other-guid', 'ЗК-2', $at);
            $this->fail('Ожидался guid_conflict');
        } catch (OrderExchangeException $e) {
            $this->assertSame(OrderExchangeException::GUID_CONFLICT, $e->error);
        }
    }

    public function test_confirm_of_cancelled_order_is_order_cancelled(): void
    {
        $order = $this->place(1);
        app(OrderStatusService::class)->change($order, OrderStatus::Cancelled, $this->admin);

        try {
            $this->exchange()->confirm($order, 'o-guid-1', 'ЗК-1', now());
            $this->fail('Ожидался order_cancelled');
        } catch (OrderExchangeException $e) {
            $this->assertSame(OrderExchangeException::ORDER_CANCELLED, $e->error);
        }

        $this->assertNull($order->fresh()->guid_1c);
        $this->assertSame([10, 10], $this->stock());
    }

    public function test_reject_marks_order_and_releases_reserve(): void
    {
        $order = $this->place(2);

        $order = $this->exchange()->reject($order, ' Контрагент не найден по УНП ');

        $this->assertSame(OrderExchangeState::Rejected, $order->exchange_state);
        $this->assertSame('Контрагент не найден по УНП', $order->reject_reason);
        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertSame([10, 10], $this->stock());
    }

    public function test_manual_sent_to_1c_keeps_reserve(): void
    {
        $order = $this->place(2);
        $status = app(OrderStatusService::class);
        $status->change($order, OrderStatus::Confirmed, $this->admin);
        $order = $status->change($order, OrderStatus::SentTo1c, $this->admin);

        $this->assertSame(OrderExchangeState::Sent, $order->exchange_state);
        $this->assertNotNull($order->registered_at);
        $this->assertSame(OrderStatusService::SOURCE_SITE, $order->statusHistory()->get()->last()->source);
        $this->assertSame([8, 10], $this->stock());
    }

    // --- Запись остатка напрямую ---

    public function test_direct_stock_write_sets_1c_stock_above_reserve(): void
    {
        $this->place(2);

        // Админка / сиды задают доступный остаток
        $this->product->fresh()->update(['stock' => 5]);

        $this->assertSame([5, 7], $this->stock());
    }

    public function test_new_products_start_with_1c_stock_equal_to_stock(): void
    {
        $product = Product::create(['name' => 'X', 'sku' => 'X-1', 'price' => 1, 'stock' => 4]);
        $this->assertSame([4, 4], [$product->fresh()->stock, $product->fresh()->stock_1c]);

        $from1c = new Product(['name' => 'Y', 'sku' => 'Y-1', 'price' => 1]);
        $from1c->stock_1c = 6;
        $from1c->save();
        $this->assertSame([6, 6], [$from1c->fresh()->stock, $from1c->fresh()->stock_1c]);
    }

    public function test_available_never_negative(): void
    {
        $this->place(8);
        $this->product->fresh()->forceFill(['stock_1c' => 3])->save();

        $this->assertSame([0, 3], $this->stock());
    }

    // --- Переходы 1С ---

    public function test_1c_transitions_go_forward_with_skips_and_cancel_from_any_open(): void
    {
        $this->assertTrue(OrderStatus::SentTo1c->canTransitionFrom1c(OrderStatus::Shipped));
        $this->assertTrue(OrderStatus::New->canTransitionFrom1c(OrderStatus::SentTo1c));
        $this->assertTrue(OrderStatus::Shipped->canTransitionFrom1c(OrderStatus::Cancelled));
        $this->assertFalse(OrderStatus::Shipped->canTransitionFrom1c(OrderStatus::InProgress));
        $this->assertFalse(OrderStatus::SentTo1c->canTransitionFrom1c(OrderStatus::SentTo1c));
        $this->assertFalse(OrderStatus::Completed->canTransitionFrom1c(OrderStatus::Cancelled));
        $this->assertFalse(OrderStatus::Cancelled->canTransitionFrom1c(OrderStatus::Shipped));
    }

    // --- Миграции данных ---

    public function test_migrations_backfill_exchange_state_unit_and_1c_stock(): void
    {
        $orders = (require database_path('migrations/2026_11_11_110000_add_exchange_fields_to_orders.php'));
        $stock = (require database_path('migrations/2026_11_11_110001_add_stock_1c_to_products.php'));

        $deleted = Product::create(['name' => 'D', 'sku' => 'D-1', 'price' => 1, 'stock' => 0]);
        $make = function (OrderStatus $status, Product $product, int $qty) {
            $order = Order::create(['user_id' => $this->client->id, 'status' => $status]);
            OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $qty, 'price' => 1]);

            return $order->id;
        };
        // Состояние до миграций: остаток уже уменьшен оформлением (10 − 2 − 3)
        $new = $make(OrderStatus::New, $this->product, 2);
        $confirmed = $make(OrderStatus::Confirmed, $this->product, 3);
        $cancelled = $make(OrderStatus::Cancelled, $this->product, 4);
        $shipped = $make(OrderStatus::Shipped, $this->product, 1);
        $orphan = $make(OrderStatus::Completed, $deleted, 1);
        $deleted->forceDelete();

        $stock->down();
        $orders->down();
        DB::table('products')->where('id', $this->product->id)->update(['stock' => 5]);

        $orders->up();
        $stock->up();

        $state = fn (int $id) => DB::table('orders')->where('id', $id)->value('exchange_state');
        $this->assertSame(['pending', 'pending', 'pending', 'sent', 'sent'],
            [$state($new), $state($confirmed), $state($cancelled), $state($shipped), $state($orphan)]);
        $this->assertSame(['шт'], DB::table('order_items')->distinct()->pluck('unit')->all());

        // Доступный остаток не изменился: stock_1c = 5 + резерв ждущих передачи (2 + 3)
        $this->assertSame([5, 10], $this->stock());
        app(StockReserve::class)->recalculate([$this->product->id]);
        $this->assertSame([5, 10], $this->stock());
    }
}
