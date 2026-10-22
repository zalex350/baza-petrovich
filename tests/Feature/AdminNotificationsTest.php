<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Notifications\CancelRequestNotification;
use App\Notifications\NewOrderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Email-уведомления администраторам: новый заказ и запрос на отмену (users.notify_*).
 */
class AdminNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create(['name' => 'ООО «Аква Строй»', 'unp' => '190000001']);
        $this->client = User::factory()->create([
            'name' => 'Иван Клиентов',
            'email' => 'ivan@client.test',
            'organization_id' => $organization->id,
        ]);
        $this->product = Product::create([
            'name' => 'Grohe Start', 'sku' => 'GR-001', 'price' => 100, 'vat_rate' => 20, 'stock' => 10,
            'is_active' => true, 'is_published' => true,
        ]);
    }

    public function test_settings_are_enabled_by_default(): void
    {
        $admin = User::factory()->admin()->create()->fresh();

        $this->assertTrue($admin->notify_new_order);
        $this->assertTrue($admin->notify_cancel_request);
    }

    public function test_new_order_notifies_only_active_admins_with_setting_on(): void
    {
        Notification::fake();
        [$on, $off, $blocked, $otherSetting] = $this->admins();

        $order = $this->placeOrder('Позвонить перед доставкой');

        Notification::assertSentTo($on, NewOrderNotification::class, fn ($n) => $n->order->is($order));
        Notification::assertSentTo($otherSetting, NewOrderNotification::class);
        Notification::assertNotSentTo([$off, $blocked, $this->client], NewOrderNotification::class);
        Notification::assertCount(2);
    }

    public function test_repeated_checkout_form_does_not_notify_twice(): void
    {
        Notification::fake();
        [$on] = $this->admins();

        $this->addToCart();
        $data = $this->checkoutData($this->client, ['checkout_token' => 'same-form-token']);
        $this->actingAs($this->client)->post('/order/checkout', $data)->assertRedirect();
        $this->actingAs($this->client)->post('/order/checkout', $data)->assertRedirect();

        $this->assertSame(1, Order::count());
        Notification::assertSentToTimes($on, NewOrderNotification::class, 1);
    }

    public function test_failed_checkout_sends_nothing(): void
    {
        Notification::fake();
        $this->admins();

        $this->addToCart(qty: 3);
        $this->product->update(['stock' => 1]);

        $this->actingAs($this->client)->post('/order/checkout', $this->checkoutData($this->client))
            ->assertSessionHas('error');

        $this->assertSame(0, Order::count());
        Notification::assertNothingSent();
    }

    public function test_cancel_request_notifies_admins_with_setting_on(): void
    {
        $order = $this->placeOrder();

        Notification::fake();
        [$on, $off, $blocked, $otherSetting] = $this->admins();

        $this->actingAs($this->client)->post("/order/{$order->id}/cancel-request", ['cancel_reason' => 'Ошиблись с количеством'])
            ->assertSessionHas('success');

        Notification::assertSentTo($on, CancelRequestNotification::class, fn ($n) => $n->order->is($order));
        Notification::assertNotSentTo([$off, $blocked, $otherSetting, $this->client], CancelRequestNotification::class);
    }

    public function test_new_order_mail_content(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->placeOrder("Позвонить перед доставкой\n[подарок](http://evil.test) **срочно**", qty: 3);

        $mail = (new NewOrderNotification($order))->toMail($admin);
        $html = (string) $mail->render();

        $this->assertSame("Новый заказ {$order->number}", $mail->subject);
        foreach ([
            $order->number,
            'ООО «Аква Строй» (УНП 190000001)',
            'Иван Клиентов (ivan@client.test)',
            "300,00\u{00A0}BYN", "60,00\u{00A0}BYN", "360,00\u{00A0}BYN",
            'Основной склад — г. Минск, ул. Промышленная, 1',
            'Позвонить перед доставкой',
            'Открыть заказ в админке',
            url("/admin/order/{$order->id}"),
        ] as $text) {
            $this->assertStringContainsString(e($text), $html, $text);
        }

        // Текст клиента — без разметки: ни ссылки, ни жирного
        $this->assertStringNotContainsString('href="http://evil.test"', $html);
        $this->assertStringNotContainsString('<strong>срочно</strong>', $html);
        $this->assertStringContainsString('[подарок](http://evil.test)', html_entity_decode(strip_tags($html)));
    }

    public function test_cancel_request_mail_content(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->placeOrder();
        $this->actingAs($this->client)->post("/order/{$order->id}/cancel-request", ['cancel_reason' => 'Ошиблись <b>с количеством</b>']);

        $mail = (new CancelRequestNotification($order->fresh()))->toMail($admin);
        $html = (string) $mail->render();

        $this->assertSame("Запрос на отмену заказа {$order->number}", $mail->subject);
        foreach ([$order->number, 'ООО «Аква Строй» (УНП 190000001)', 'Иван Клиентов (ivan@client.test)', url("/admin/order/{$order->id}")] as $text) {
            $this->assertStringContainsString(e($text), $html, $text);
        }
        $this->assertStringNotContainsString('<b>с количеством</b>', $html);
        $this->assertStringContainsString('Ошиблись <b>с количеством</b>', html_entity_decode(strip_tags($html)));
        // Письмо — на русском (строки шаблона Laravel из lang/ru.json)
        $this->assertStringContainsString(
            'Если кнопка «Открыть заказ в админке» не работает',
            preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html))),
        );
    }

    public function test_mail_failure_does_not_break_checkout(): void
    {
        User::factory()->admin()->create();
        // SMTP недоступен — отправка падает, заказ всё равно оформлен
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 9]);

        $this->addToCart();
        $this->actingAs($this->client)->post('/order/checkout', $this->checkoutData($this->client))
            ->assertRedirect()
            ->assertSessionHas('success', 'Заказ оформлен');

        $this->assertSame(1, Order::count());
    }

    public function test_admin_edits_notification_settings(): void
    {
        $self = User::factory()->admin()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($self)->get("/admin/user/{$admin->id}/edit")
            ->assertOk()
            ->assertSee('Уведомления на почту')
            ->assertSee('name="notify_new_order" value="1" checked', false)
            ->assertSee('name="notify_cancel_request" value="1" checked', false);

        $this->actingAs($self)->post("/admin/user/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'admin',
            'notify_new_order' => '0', 'notify_cancel_request' => '1',
        ])->assertRedirect("/admin/user/{$admin->id}");

        $admin->refresh();
        $this->assertFalse($admin->notify_new_order);
        $this->assertTrue($admin->notify_cancel_request);

        $this->actingAs($self)->get("/admin/user/{$admin->id}")
            ->assertOk()
            ->assertSeeInOrder(['Уведомления на почту', 'Новый заказ', 'Выключено', 'Запрос на отмену', 'Включено']);
    }

    public function test_new_admin_can_turn_notifications_off_on_create(): void
    {
        $self = User::factory()->admin()->create();

        $this->actingAs($self)->post('/admin/user', [
            'name' => 'Мария Менеджер', 'email' => 'maria@shop.test', 'role' => 'admin',
            'password_mode' => 'manual', 'password' => 'Secret-pass-123',
            'notify_new_order' => '0', 'notify_cancel_request' => '0',
        ])->assertRedirect();

        $created = User::where('email', 'maria@shop.test')->firstOrFail();
        $this->assertFalse($created->notify_new_order);
        $this->assertFalse($created->notify_cancel_request);
    }

    public function test_settings_are_ignored_for_clients(): void
    {
        $self = User::factory()->admin()->create();

        $this->actingAs($self)->post("/admin/user/{$this->client->id}", [
            'name' => $this->client->name, 'email' => $this->client->email, 'role' => 'client',
            'organization_id' => $this->client->organization_id,
            'notify_new_order' => '0',
        ])->assertRedirect();

        $this->assertTrue($this->client->fresh()->notify_new_order);

        // У клиента блока нет на карточке
        $this->actingAs($self)->get("/admin/user/{$this->client->id}")->assertDontSee('Уведомления на почту');
    }

    /**
     * @return array{User, User, User, User} [всё включено, всё выключено, заблокирован, только отмены]
     */
    private function admins(): array
    {
        return [
            User::factory()->admin()->create(),
            User::factory()->admin()->create(['notify_new_order' => false, 'notify_cancel_request' => false]),
            User::factory()->admin()->create(['blocked_at' => now()]),
            User::factory()->admin()->create(['notify_new_order' => true, 'notify_cancel_request' => false]),
        ];
    }

    private function addToCart(int $qty = 1): void
    {
        $this->actingAs($this->client)->post("/order/add/{$this->product->id}", ['qty' => $qty]);
    }

    private function placeOrder(?string $comment = null, int $qty = 1): Order
    {
        $this->addToCart($qty);
        $this->actingAs($this->client)
            ->post('/order/checkout', $this->checkoutData($this->client, array_filter(['comment' => $comment])))
            ->assertSessionHas('success', 'Заказ оформлен');

        return Order::latest('id')->firstOrFail();
    }
}
