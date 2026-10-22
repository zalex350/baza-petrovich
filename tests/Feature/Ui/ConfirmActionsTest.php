<?php

namespace Tests\Feature\Ui;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * x-confirm на необратимых действиях (docs/UI_INVENTORY.md §4): удаление позиции корзины,
 * отклонение запроса на отмену, сброс пароля. Сами действия не изменились — только подтверждение.
 */
class ConfirmActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->client = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $this->product = Product::create([
            'name' => 'Grohe Start', 'sku' => 'GR-001', 'price' => 100, 'stock' => 10,
            'is_active' => true, 'is_published' => true,
        ]);
    }

    public function test_cart_item_delete_asks_confirmation(): void
    {
        $this->actingAs($this->client)->post("/order/add/{$this->product->id}", ['qty' => 1]);
        $item = $this->client->cart()->firstOrFail()->items()->firstOrFail();

        $html = $this->actingAs($this->client)->get('/order')->assertOk()->getContent();

        $this->assertConfirm($html, "/order/delete/{$item->id}", 'Удалить товар из корзины?');
        $this->assertStringContainsString('«Grohe Start» будет удалён из корзины.', $html);
        // Прежней формы с кнопкой submit «Удалить» без подтверждения нет
        $this->assertDoesNotMatchRegularExpression('#<form\s+method="POST"\s+action="/order/delete/\d+"\s*>#', $html);

        // Само удаление — как раньше
        $this->actingAs($this->client)->post("/order/delete/{$item->id}")->assertSessionHas('success', 'Товар удалён из корзины');
    }

    public function test_reject_cancel_request_asks_reason_in_modal(): void
    {
        $order = $this->orderWithCancelRequest();

        $html = $this->actingAs($this->admin)->get("/admin/order/{$order->id}")->assertOk()->getContent();

        $this->assertConfirm($html, "/admin/order/{$order->id}/cancel-request/reject", 'Отклонить запрос на отмену?');
        $this->assertMatchesRegularExpression('#<textarea[^>]*name="reject_comment"[^>]*required#s', $html);
        $this->assertStringContainsString('open: false', $html);

        // Пустая причина — ошибка, окно после возврата открыто, ошибка у поля
        $this->actingAs($this->admin)->from("/admin/order/{$order->id}")
            ->post("/admin/order/{$order->id}/cancel-request/reject", [])
            ->assertSessionHasErrors('reject_comment');

        $this->actingAs($this->admin)->get("/admin/order/{$order->id}")
            ->assertSee('open: true', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('Укажите причину отклонения');
    }

    public function test_reset_password_asks_confirmation(): void
    {
        $html = $this->actingAs($this->admin)->get("/admin/user/{$this->client->id}")->assertOk()->getContent();

        $this->assertConfirm($html, "/admin/user/{$this->client->id}/reset-password", 'Сбросить пароль?');
        $this->assertStringContainsString("На {$this->client->email} уйдёт письмо", $html);
        $this->assertStringContainsString('Отправить ссылку', $html);
    }

    private function orderWithCancelRequest(): Order
    {
        $this->actingAs($this->client)->post("/order/add/{$this->product->id}", ['qty' => 1]);
        $this->actingAs($this->client)->post('/order/checkout', $this->checkoutData($this->client));
        $order = Order::latest('id')->firstOrFail();

        $this->actingAs($this->client)->post("/order/{$order->id}/cancel-request", ['cancel_reason' => 'Ошиблись']);

        return $order->fresh();
    }

    /**
     * Действие — только из модального окна x-confirm: кнопка-триггер type="button",
     * форма с action и CSRF — внутри role="dialog".
     */
    private function assertConfirm(string $html, string $action, string $title): void
    {
        $this->assertMatchesRegularExpression(
            '#role="dialog".*?<form\s+method="POST"\s+action="'.preg_quote($action, '#').'".*?name="_token".*?'.preg_quote($title, '#').'#s',
            $html,
        );
        $this->assertStringContainsString('aria-haspopup="dialog"', $html);
    }
}
