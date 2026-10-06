<?php

namespace Tests\Feature;

use App\Enums\OrderHistoryEvent;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Отмена заказа клиентом — только запросом; решает администратор.
 */
class OrderCancelRequestTest extends TestCase
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
     * Заказ на 2 шт.: остаток 10 → 8.
     */
    private function placeOrder(): Order
    {
        $this->actingAs($this->client)->post("/order/add/{$this->product->id}", ['qty' => 2]);
        $this->actingAs($this->client)->post('/order/checkout', $this->checkoutData($this->client));

        return Order::latest('id')->firstOrFail();
    }

    private function setStatus(Order $order, OrderStatus $status): Order
    {
        DB::table('orders')->where('id', $order->id)->update(['status' => $status->value]);

        return $order->fresh();
    }

    private function request(Order $order, ?string $reason = 'Ошиблись с количеством', ?User $user = null)
    {
        return $this->actingAs($user ?? $this->client)
            ->from("/order/{$order->id}")
            ->post("/order/{$order->id}/cancel-request", array_filter(['cancel_reason' => $reason]));
    }

    private function approve(Order $order, ?string $comment = null)
    {
        return $this->actingAs($this->admin)
            ->from("/admin/order/{$order->id}")
            ->post("/admin/order/{$order->id}/cancel-request/approve", array_filter(['comment' => $comment]));
    }

    private function reject(Order $order, ?string $comment = 'Заказ уже собран')
    {
        return $this->actingAs($this->admin)
            ->from("/admin/order/{$order->id}")
            ->post("/admin/order/{$order->id}/cancel-request/reject", array_filter(['reject_comment' => $comment]));
    }

    // --- Запрос клиента ---

    public function test_client_requests_cancel_status_does_not_change(): void
    {
        $order = $this->placeOrder();

        $this->request($order)
            ->assertRedirect("/order/{$order->id}")
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertNotNull($order->cancel_requested_at);
        $this->assertSame($this->client->id, $order->cancel_requested_by);
        $this->assertSame('Ошиблись с количеством', $order->cancel_reason);
        $this->assertSame(8, $this->product->fresh()->stock);

        $record = $order->statusHistory()->get()->last();
        $this->assertSame(OrderHistoryEvent::CancelRequested, $record->event);
        $this->assertSame(OrderStatus::New, $record->to_status);
        $this->assertSame('Ошиблись с количеством', $record->comment);
        $this->assertSame($this->client->id, $record->user_id);
    }

    public function test_request_is_allowed_for_confirmed_order(): void
    {
        $order = $this->setStatus($this->placeOrder(), OrderStatus::Confirmed);

        $this->request($order)->assertSessionHas('success');

        $this->assertTrue($order->fresh()->hasOpenCancelRequest());
    }

    public function test_reason_is_required(): void
    {
        $order = $this->placeOrder();

        $this->request($order, null)->assertSessionHasErrors(['cancel_reason' => 'Укажите причину отмены']);
        $this->request($order, '   ')->assertSessionHasErrors('cancel_reason');

        $this->assertFalse($order->fresh()->hasOpenCancelRequest());
    }

    public function test_repeat_request_while_open_is_refused(): void
    {
        $order = $this->placeOrder();

        $this->request($order)->assertSessionHas('success');
        $this->request($order, 'Ещё раз')
            ->assertSessionHas('error', 'Запрос на отмену уже отправлен и ждёт решения');

        $this->assertSame('Ошиблись с количеством', $order->fresh()->cancel_reason);
        $this->assertSame(1, $order->statusHistory()->where('event', 'cancel_requested')->count());
    }

    public function test_request_is_refused_after_sent_to_1c(): void
    {
        foreach ([OrderStatus::SentTo1c, OrderStatus::InProgress, OrderStatus::Shipped, OrderStatus::Completed, OrderStatus::Cancelled] as $status) {
            $order = $this->setStatus($this->placeOrder(), $status);

            $this->request($order)
                ->assertSessionHas('error', 'Запросить отмену можно только для заказа в статусе «Новый» или «Подтверждён»');

            $this->assertFalse($order->fresh()->hasOpenCancelRequest());

            $this->actingAs($this->client)->get("/order/{$order->id}")
                ->assertOk()
                ->assertDontSee("/order/{$order->id}/cancel-request", false);
        }
    }

    public function test_only_author_can_request(): void
    {
        $order = $this->placeOrder();

        $colleague = User::factory()->create(['organization_id' => $this->client->organization_id]);
        $stranger = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->request($order, user: $colleague)->assertForbidden();
        $this->request($order, user: $stranger)->assertNotFound();

        $this->assertFalse($order->fresh()->hasOpenCancelRequest());

        $this->actingAs($this->client)->get("/order/{$order->id}")
            ->assertSee("/order/{$order->id}/cancel-request", false)
            ->assertSee('Запросить отмену');

        $this->actingAs($colleague)->get("/order/{$order->id}")
            ->assertOk()
            ->assertDontSee("/order/{$order->id}/cancel-request", false);
    }

    public function test_client_sees_open_request_until_decision(): void
    {
        $order = $this->placeOrder();
        $this->request($order);

        $this->actingAs($this->client)->get("/order/{$order->id}")
            ->assertOk()
            ->assertSee('Запрошена отмена')
            ->assertSee('Причина: Ошиблись с количеством')
            ->assertDontSee("action=\"/order/{$order->id}/cancel-request\"", false);

        // Запрос — не отмена: заказ остаётся среди активных
        $response = $this->actingAs($this->client)->get('/orders')->assertOk()->assertSee('Запрошена отмена');
        $this->assertSame([$order->id], $response->viewData('activeOrders')->pluck('id')->all());
        $this->assertCount(0, $response->viewData('archivedOrders'));
    }

    // --- Решение администратора ---

    public function test_admin_list_shows_badge_and_filters_requests(): void
    {
        $requested = $this->placeOrder();
        $this->request($requested);
        $other = $this->placeOrder();

        $this->actingAs($this->admin)->get('/admin/orders')
            ->assertOk()
            ->assertSee('Запрос на отмену')
            ->assertSee($other->number);

        $response = $this->actingAs($this->admin)->get('/admin/orders?cancel_request=1')->assertOk();
        $this->assertSame([$requested->id], $response->viewData('orders')->pluck('id')->all());
    }

    public function test_admin_card_shows_reason_and_actions(): void
    {
        $order = $this->placeOrder();
        $this->request($order);

        $this->actingAs($this->admin)->get("/admin/order/{$order->id}")
            ->assertOk()
            ->assertSee('Причина: Ошиблись с количеством')
            ->assertSee('Иван Клиентов')
            ->assertSee('Подтвердить отмену')
            ->assertSee('Отклонить')
            ->assertSee("/admin/order/{$order->id}/cancel-request/approve", false)
            ->assertSee("/admin/order/{$order->id}/cancel-request/reject", false);
    }

    public function test_approve_cancels_and_returns_stock_once(): void
    {
        $order = $this->placeOrder();
        $this->request($order);

        $this->approve($order, 'Согласовано')->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertFalse($order->hasOpenCancelRequest());
        $this->assertNotNull($order->stock_returned_at);
        $this->assertSame(10, $this->product->fresh()->stock);

        $record = $order->statusHistory()->get()->last();
        $this->assertSame(OrderStatus::Cancelled, $record->to_status);
        $this->assertNull($record->event);
        $this->assertSame($this->admin->id, $record->user_id);
        $this->assertSame('Отмена по запросу клиента: Согласовано', $record->comment);

        // Повторное подтверждение — запроса уже нет, остаток не возвращается второй раз
        $this->approve($order)->assertSessionHas('error', 'По заказу нет открытого запроса на отмену');
        $this->assertSame(10, $this->product->fresh()->stock);

        // Клиент видит результат в истории, заказ — в архиве
        $this->actingAs($this->client)->get("/order/{$order->id}")
            ->assertOk()
            ->assertSeeInOrder(['Запрошена отмена', 'Ошиблись с количеством', 'Отменён', 'Отмена по запросу клиента']);

        $response = $this->actingAs($this->client)->get('/orders');
        $this->assertSame([$order->id], $response->viewData('archivedOrders')->pluck('id')->all());
    }

    public function test_stock_is_not_returned_twice_after_manual_cancel(): void
    {
        $order = $this->placeOrder();
        $this->request($order);

        // Администратор отменил заказ обычной сменой статуса — запрос исполнен
        $this->actingAs($this->admin)
            ->post("/admin/order/{$order->id}/status", ['status' => 'cancelled'])
            ->assertSessionHas('success');

        $this->assertFalse($order->fresh()->hasOpenCancelRequest());
        $this->assertSame(10, $this->product->fresh()->stock);

        $this->approve($order)->assertSessionHas('error');
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_reject_requires_comment_and_removes_request(): void
    {
        $order = $this->placeOrder();
        $this->request($order);

        $this->reject($order, null)->assertSessionHasErrors(['reject_comment' => 'Укажите причину отклонения']);
        $this->assertTrue($order->fresh()->hasOpenCancelRequest());

        $this->reject($order)->assertSessionHas('success', 'Запрос на отмену отклонён');

        $order->refresh();
        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertFalse($order->hasOpenCancelRequest());
        $this->assertNull($order->cancel_reason);
        $this->assertSame(8, $this->product->fresh()->stock);

        $record = $order->statusHistory()->get()->last();
        $this->assertSame(OrderHistoryEvent::CancelRejected, $record->event);
        $this->assertSame('Заказ уже собран', $record->comment);
        $this->assertSame($this->admin->id, $record->user_id);

        // Клиент видит отказ в истории и может запросить отмену снова
        $this->actingAs($this->client)->get("/order/{$order->id}")
            ->assertOk()
            ->assertSeeInOrder(['Запрос на отмену отклонён', 'Заказ уже собран'])
            ->assertSee("/order/{$order->id}/cancel-request", false);

        $this->request($order, 'Всё-таки отменить')->assertSessionHas('success');
    }

    public function test_request_stays_with_admin_after_sent_to_1c(): void
    {
        $order = $this->placeOrder();
        $this->request($order);

        $this->actingAs($this->admin)->post("/admin/order/{$order->id}/status", ['status' => 'confirmed']);
        $this->actingAs($this->admin)->post("/admin/order/{$order->id}/status", ['status' => 'sent_to_1c']);

        $order->refresh();
        $this->assertSame(OrderStatus::SentTo1c, $order->status);
        $this->assertTrue($order->hasOpenCancelRequest());

        // Подтвердить нельзя — только отклонить
        $this->actingAs($this->admin)->get("/admin/order/{$order->id}")
            ->assertOk()
            ->assertSee('отменить его уже нельзя, запрос можно только отклонить')
            ->assertDontSee("/admin/order/{$order->id}/cancel-request/approve", false)
            ->assertSee("/admin/order/{$order->id}/cancel-request/reject", false);

        $this->approve($order)->assertSessionHas('error', 'Заказ уже передан в 1С — отменить его нельзя, запрос можно только отклонить');
        $this->assertSame(OrderStatus::SentTo1c, $order->fresh()->status);
        $this->assertSame(8, $this->product->fresh()->stock);

        $this->reject($order)->assertSessionHas('success');
        $this->assertFalse($order->fresh()->hasOpenCancelRequest());

        // Новый запрос после передачи в 1С создать нельзя
        $this->request($order)->assertSessionHas('error');
        $this->assertFalse($order->fresh()->hasOpenCancelRequest());
    }

    public function test_client_cannot_use_admin_decision_routes(): void
    {
        $order = $this->placeOrder();
        $this->request($order);

        $this->actingAs($this->client)->post("/admin/order/{$order->id}/cancel-request/approve")->assertForbidden();
        $this->actingAs($this->client)->post("/admin/order/{$order->id}/cancel-request/reject", ['reject_comment' => 'x'])->assertForbidden();

        $this->assertSame(OrderStatus::New, $order->fresh()->status);
        $this->assertTrue($order->fresh()->hasOpenCancelRequest());
    }

    public function test_direct_cancel_route_is_gone(): void
    {
        $order = $this->placeOrder();

        $response = $this->actingAs($this->client)->post("/order/{$order->id}/cancel");

        $this->assertContains($response->status(), [404, 405]);
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
        $this->assertSame(8, $this->product->fresh()->stock);
    }
}
