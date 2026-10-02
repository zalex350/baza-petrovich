<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Статусы заказа: переходы, история, возврат остатка, отмена клиентом (SRS §10.7, BR-133).
 */
class OrderStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Мария Менеджер']);

        $this->client = User::factory()->create([
            'name'            => 'Иван Клиентов',
            'organization_id' => Organization::factory()->create()->id,
        ]);

        $this->product = Product::create([
            'name'         => 'Grohe Start',
            'sku'          => 'GR-001',
            'price'        => 100,
            'stock'        => 10,
            'is_active'    => true,
            'is_published' => true,
        ]);
    }

    /**
     * Заказ, оформленный через корзину: остаток списан, первая запись истории создана.
     */
    private function placeOrder(int $qty = 2, ?User $user = null): Order
    {
        $user ??= $this->client;

        $this->actingAs($user)->post("/order/add/{$this->product->id}", ['qty' => $qty]);
        $this->actingAs($user)->post('/order/checkout')->assertRedirect('/orders');

        return Order::where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    private function orderIn(OrderStatus $status): Order
    {
        $order = $this->placeOrder();

        DB::table('orders')->where('id', $order->id)->update(['status' => $status->value]);

        return $order->fresh();
    }

    private function changeStatus(Order $order, string $status, ?string $comment = null)
    {
        return $this->actingAs($this->admin)
            ->from("/admin/order/{$order->id}")
            ->post("/admin/order/{$order->id}/status", array_filter(['status' => $status, 'comment' => $comment]));
    }

    // --- Enum ---

    public function test_labels(): void
    {
        $this->assertSame(
            [
                'new'         => 'Новый',
                'confirmed'   => 'Подтверждён',
                'sent_to_1c'  => 'Передан в 1С',
                'in_progress' => 'В работе',
                'shipped'     => 'Отгружен',
                'completed'   => 'Выполнен',
                'cancelled'   => 'Отменён',
            ],
            collect(OrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()
        );

        foreach (OrderStatus::cases() as $status) {
            $this->assertNotSame('', $status->color());
        }
    }

    public function test_transition_matrix(): void
    {
        $allowed = [
            'new'         => ['confirmed', 'cancelled'],
            'confirmed'   => ['sent_to_1c', 'cancelled'],
            'sent_to_1c'  => ['in_progress'],
            'in_progress' => ['shipped'],
            'shipped'     => ['completed'],
            'completed'   => [],
            'cancelled'   => [],
        ];

        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $to) {
                $this->assertSame(
                    in_array($to->value, $allowed[$from->value], true),
                    $from->canTransitionTo($to),
                    "{$from->value} → {$to->value}"
                );
            }
        }
    }

    // --- Смена статуса в админке ---

    public function test_admin_moves_order_through_full_flow(): void
    {
        $order = $this->placeOrder();

        foreach (['confirmed', 'sent_to_1c', 'in_progress', 'shipped', 'completed'] as $status) {
            $this->changeStatus($order, $status)
                ->assertRedirect("/admin/order/{$order->id}")
                ->assertSessionHas('success');

            $this->assertSame($status, $order->fresh()->status->value);
        }

        $this->assertSame(
            [null, 'new', 'confirmed', 'sent_to_1c', 'in_progress', 'shipped'],
            $order->statusHistory()->get()->map(fn ($r) => $r->from_status?->value)->all()
        );
        $this->assertSame(
            ['new', 'confirmed', 'sent_to_1c', 'in_progress', 'shipped', 'completed'],
            $order->statusHistory()->get()->map(fn ($r) => $r->to_status->value)->all()
        );

        // Выполненный заказ остаток не возвращает
        $this->assertSame(8, $this->product->fresh()->stock);
    }

    public static function invalidTransitions(): array
    {
        return [
            'перескок через шаг'           => [OrderStatus::New, 'sent_to_1c'],
            'сразу в отгружен'             => [OrderStatus::New, 'shipped'],
            'назад'                        => [OrderStatus::Confirmed, 'new'],
            'тот же статус'                => [OrderStatus::Confirmed, 'confirmed'],
            'отмена после передачи в 1С'   => [OrderStatus::SentTo1c, 'cancelled'],
            'отмена в работе'              => [OrderStatus::InProgress, 'cancelled'],
            'отмена отгруженного'          => [OrderStatus::Shipped, 'cancelled'],
            'из отменённого'               => [OrderStatus::Cancelled, 'new'],
            'из отменённого в подтверждён' => [OrderStatus::Cancelled, 'confirmed'],
            'из выполненного'              => [OrderStatus::Completed, 'shipped'],
        ];
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transition_is_rejected(OrderStatus $from, string $to): void
    {
        $order = $this->orderIn($from);
        $history = $order->statusHistory()->count();

        $this->changeStatus($order, $to)
            ->assertRedirect("/admin/order/{$order->id}")
            ->assertSessionHas('error');

        $this->assertSame($from, $order->fresh()->status);
        $this->assertSame($history, $order->statusHistory()->count());
        $this->assertSame(8, $this->product->fresh()->stock);
    }

    public function test_unknown_or_missing_status_is_validation_error(): void
    {
        $order = $this->placeOrder();

        $this->changeStatus($order, 'draft')->assertSessionHasErrors('status');
        $this->changeStatus($order, 'deleted')->assertSessionHasErrors('status');
        $this->changeStatus($order, '')->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    public function test_client_cannot_change_status_via_admin_route(): void
    {
        $order = $this->placeOrder();

        $this->actingAs($this->client)
            ->post("/admin/order/{$order->id}/status", ['status' => 'confirmed'])
            ->assertForbidden();

        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    public function test_payment_flag_does_not_touch_status(): void
    {
        $order = $this->placeOrder();

        // Старая форма слала статус вместе с оплатой — теперь он здесь игнорируется
        $this->actingAs($this->admin)
            ->post("/admin/order/{$order->id}", ['is_paid' => 1, 'status' => 'shipped'])
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertTrue((bool) $order->is_paid);
        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertSame(1, $order->statusHistory()->count());
    }

    public function test_admin_card_offers_only_allowed_transitions(): void
    {
        $order = $this->placeOrder();

        $response = $this->actingAs($this->admin)->get("/admin/order/{$order->id}")->assertOk();

        $response->assertSee('value="confirmed"', false)
            ->assertSee('value="cancelled"', false)
            ->assertDontSee('value="shipped"', false)
            ->assertDontSee('value="new"', false);

        $cancelled = $this->orderIn(OrderStatus::Cancelled);

        $this->actingAs($this->admin)->get("/admin/order/{$cancelled->id}")
            ->assertOk()
            ->assertSee('статус больше не меняется')
            ->assertDontSee("/admin/order/{$cancelled->id}/status", false);
    }

    public function test_manual_status_change_can_be_disabled(): void
    {
        config(['shop.orders.manual_status' => false]);

        $order = $this->placeOrder();

        $this->changeStatus($order, 'confirmed')
            ->assertSessionHas('error', 'Ручная смена статусов отключена');

        $this->assertSame(OrderStatus::New, $order->fresh()->status);

        $this->actingAs($this->admin)->get("/admin/order/{$order->id}")
            ->assertOk()
            ->assertSee('Ручная смена статусов отключена')
            ->assertDontSee("/admin/order/{$order->id}/status", false);
    }

    public function test_manual_status_is_enabled_by_default(): void
    {
        $this->assertTrue(config('shop.orders.manual_status'));
    }

    // --- История ---

    public function test_checkout_writes_first_history_record(): void
    {
        $order = $this->placeOrder();

        $record = $order->statusHistory()->sole();

        $this->assertNull($record->from_status);
        $this->assertSame(OrderStatus::New, $record->to_status);
        $this->assertSame($this->client->id, $record->user_id);
        $this->assertNull($record->comment);
        $this->assertNotNull($record->created_at);
    }

    public function test_status_change_is_recorded_with_author_and_comment(): void
    {
        $order = $this->placeOrder();

        $this->changeStatus($order, 'confirmed', '  Согласовано по телефону  ')->assertSessionHas('success');
        $this->changeStatus($order, 'sent_to_1c')->assertSessionHas('success');

        [$placed, $confirmed, $sent] = $order->statusHistory()->get()->all();

        $this->assertSame(OrderStatus::New, $confirmed->from_status);
        $this->assertSame(OrderStatus::Confirmed, $confirmed->to_status);
        $this->assertSame($this->admin->id, $confirmed->user_id);
        $this->assertSame('Согласовано по телефону', $confirmed->comment);

        // Комментарий необязателен
        $this->assertNull($sent->comment);
        $this->assertSame($this->client->id, $placed->user_id);
    }

    public function test_history_is_shown_in_admin_and_client_cards(): void
    {
        $order = $this->placeOrder();
        $this->changeStatus($order, 'confirmed', 'Согласовано по телефону');

        $this->actingAs($this->admin)->get("/admin/order/{$order->id}")
            ->assertOk()
            ->assertSee('История статусов')
            ->assertSeeInOrder(['Новый', 'Подтверждён'])
            ->assertSee('Согласовано по телефону')
            ->assertSee('Кто изменил')
            ->assertSee('Мария Менеджер');

        $this->actingAs($this->client)->get("/order/{$order->id}")
            ->assertOk()
            ->assertSee('История статусов')
            ->assertSee('Подтверждён')
            ->assertSee('Согласовано по телефону')
            // клиенту не показываем, кто из менеджеров менял статус
            ->assertDontSee('Мария Менеджер');
    }

    public function test_history_survives_deleted_author(): void
    {
        $order = $this->placeOrder();
        $this->changeStatus($order, 'confirmed');

        $this->admin->delete();

        $this->assertSame(2, $order->statusHistory()->count());
        $this->assertNull($order->statusHistory()->get()->last()->user_id);

        $this->actingAs(User::factory()->admin()->create())
            ->get("/admin/order/{$order->id}")
            ->assertOk();
    }

    // --- Отмена и остаток ---

    public function test_cancel_returns_stock(): void
    {
        $order = $this->orderIn(OrderStatus::Confirmed);
        $this->assertSame(8, $this->product->fresh()->stock);

        $this->changeStatus($order, 'cancelled', 'Клиент передумал')->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertNotNull($order->stock_returned_at);
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_stock_is_returned_exactly_once(): void
    {
        $order = $this->placeOrder();
        $service = app(OrderStatusService::class);

        $service->change($order, OrderStatus::Cancelled, $this->admin);
        $this->assertSame(10, $this->product->fresh()->stock);

        // Повторная отмена недопустима и остаток не трогает
        try {
            $service->change($order->fresh(), OrderStatus::Cancelled, $this->admin);
            $this->fail('Повторная отмена должна быть отклонена');
        } catch (InvalidStatusTransition) {
        }

        $this->changeStatus($order, 'cancelled')->assertSessionHas('error');

        $this->assertSame(10, $this->product->fresh()->stock);

        // Даже если статус вернули в обход приложения — отметка о возврате не даст вернуть дважды
        DB::table('orders')->where('id', $order->id)->update(['status' => 'new']);

        $service->change($order->fresh(), OrderStatus::Cancelled, $this->admin);

        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_cancel_returns_stock_of_every_item(): void
    {
        $second = Product::create(['name' => 'Второй', 'sku' => 'B-1', 'price' => 50, 'stock' => 5, 'is_active' => true]);

        $this->actingAs($this->client)->post("/order/add/{$this->product->id}", ['qty' => 3]);
        $this->actingAs($this->client)->post("/order/add/{$second->id}", ['qty' => 5]);
        $this->actingAs($this->client)->post('/order/checkout');

        $this->assertSame(7, $this->product->fresh()->stock);
        $this->assertSame(0, $second->fresh()->stock);

        $this->changeStatus(Order::sole(), 'cancelled');

        $this->assertSame(10, $this->product->fresh()->stock);
        $this->assertSame(5, $second->fresh()->stock);
    }

    public function test_cancel_works_when_product_is_deleted(): void
    {
        $soft = $this->placeOrder(2);
        $this->product->delete();

        $this->changeStatus($soft, 'cancelled')->assertSessionHas('success');

        // Удалённому (soft delete) товару остаток возвращается — его могут восстановить
        $this->assertSame(10, Product::withTrashed()->find($this->product->id)->stock);

        $this->product->restore();
        $hard = $this->placeOrder(2);
        $this->product->forceDelete();

        $this->changeStatus($hard, 'cancelled')->assertSessionHas('success');

        $this->assertSame(OrderStatus::Cancelled, $hard->fresh()->status);
        $this->assertNotNull($hard->fresh()->stock_returned_at);
    }

    // --- Клиент: отмена вместо удаления (BR-133) ---

    public function test_client_cannot_delete_order(): void
    {
        $order = $this->placeOrder();

        $response = $this->actingAs($this->client)->post("/order/{$order->id}/delete");

        $this->assertContains($response->status(), [404, 405]);
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
        $this->assertSame(1, Order::count());
    }

    public function test_client_cancels_own_new_order(): void
    {
        $order = $this->placeOrder();

        $this->actingAs($this->client)
            ->post("/order/{$order->id}/cancel")
            ->assertRedirect('/orders')
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(10, $this->product->fresh()->stock);

        $record = $order->statusHistory()->get()->last();
        $this->assertSame(OrderStatus::New, $record->from_status);
        $this->assertSame(OrderStatus::Cancelled, $record->to_status);
        $this->assertSame($this->client->id, $record->user_id);
        $this->assertSame('Отменён клиентом', $record->comment);

        // Отменённый заказ остаётся виден клиенту
        $this->actingAs($this->client)->get('/orders')
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee('Отменён');
    }

    public function test_client_cannot_cancel_order_that_is_not_new(): void
    {
        foreach ([OrderStatus::Confirmed, OrderStatus::SentTo1c, OrderStatus::Shipped, OrderStatus::Cancelled] as $status) {
            $order = $this->orderIn($status);
            $stock = $this->product->fresh()->stock;

            $this->actingAs($this->client)
                ->from('/orders')
                ->post("/order/{$order->id}/cancel")
                ->assertRedirect('/orders')
                ->assertSessionHas('error', 'Отменить можно только заказ в статусе «Новый»');

            $this->assertSame($status, $order->fresh()->status);
            $this->assertSame($stock, $this->product->fresh()->stock);
        }
    }

    public function test_client_cannot_cancel_colleagues_or_foreign_order(): void
    {
        $order = $this->placeOrder();

        $colleague = User::factory()->create(['organization_id' => $this->client->organization_id]);
        $stranger = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($colleague)->post("/order/{$order->id}/cancel")->assertForbidden();
        $this->actingAs($stranger)->post("/order/{$order->id}/cancel")->assertNotFound();

        $this->assertSame(OrderStatus::New, $order->fresh()->status);
        $this->assertSame(8, $this->product->fresh()->stock);
    }

    public function test_cancel_button_is_shown_with_confirmation_only_for_own_new_order(): void
    {
        $order = $this->placeOrder();
        $colleague = User::factory()->create(['organization_id' => $this->client->organization_id]);

        foreach (['/orders', "/order/{$order->id}"] as $url) {
            $this->actingAs($this->client)->get($url)
                ->assertOk()
                ->assertSee("/order/{$order->id}/cancel", false)
                ->assertSee("confirm('Отменить заказ {$order->number}?')", false);

            $this->actingAs($colleague)->get($url)
                ->assertOk()
                ->assertDontSee("/order/{$order->id}/cancel", false);
        }

        $this->changeStatus($order, 'confirmed');

        $this->actingAs($this->client)->get('/orders')
            ->assertDontSee("/order/{$order->id}/cancel", false);
    }

    // --- Статусы в интерфейсе ---

    public function test_statuses_are_shown_by_label_everywhere(): void
    {
        $order = $this->orderIn(OrderStatus::SentTo1c);

        foreach (['/orders', "/order/{$order->id}"] as $url) {
            $this->actingAs($this->client)->get($url)
                ->assertOk()
                ->assertSee('Передан в 1С')
                ->assertDontSee('sent_to_1c');
        }

        $this->actingAs($this->admin)->get('/admin/orders')
            ->assertOk()
            ->assertSee('Передан в 1С');

        $this->actingAs($this->admin)->get("/admin/order/{$order->id}")
            ->assertOk()
            ->assertSee('Передан в 1С');
    }

    public function test_my_orders_split_active_and_archive(): void
    {
        $active = $this->orderIn(OrderStatus::Shipped);
        $completed = $this->orderIn(OrderStatus::Completed);
        $cancelled = $this->orderIn(OrderStatus::Cancelled);

        $response = $this->actingAs($this->client)->get('/orders')->assertOk();

        $this->assertSame([$active->id], $response->viewData('activeOrders')->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$completed->id, $cancelled->id],
            $response->viewData('archivedOrders')->pluck('id')->all()
        );
    }
}
