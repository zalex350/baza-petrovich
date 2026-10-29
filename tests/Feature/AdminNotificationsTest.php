<?php

namespace Tests\Feature;

use App\Events\CancelRequested;
use App\Events\OrderPlaced;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Notifications\CancelRequestNotification;
use App\Notifications\NewOrderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
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

    public function test_notifications_wait_for_commit(): void
    {
        $order = $this->placeOrder();
        Notification::fake();
        $admin = User::factory()->admin()->create();

        DB::transaction(function () use ($order) {
            OrderPlaced::dispatch($order);
            CancelRequested::dispatch($order);

            // Внутри транзакции — ещё ничего
            Notification::assertNothingSent();
        });

        Notification::assertSentTo($admin, NewOrderNotification::class);
        Notification::assertSentTo($admin, CancelRequestNotification::class);
    }

    public function test_rolled_back_transaction_sends_nothing(): void
    {
        $order = $this->placeOrder();
        Notification::fake();
        User::factory()->admin()->create();

        try {
            DB::transaction(function () use ($order) {
                OrderPlaced::dispatch($order);
                CancelRequested::dispatch($order);

                throw new RuntimeException('откат');
            });
        } catch (RuntimeException) {
        }

        Notification::assertNothingSent();
    }

    public function test_mails_are_queued_per_recipient_after_commit(): void
    {
        Queue::fake();
        [$on, , , $otherSetting] = $this->admins();

        $order = $this->placeOrder();

        $notification = new NewOrderNotification($order);
        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertTrue($notification->afterCommit);
        $this->assertSame(3, $notification->tries);

        // По задаче на получателя: включено у двоих
        Queue::assertPushed(SendQueuedNotifications::class, 2);
        Queue::assertPushed(
            SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job) => $job->notification instanceof NewOrderNotification
                && $job->notifiables->contains($on),
        );
        Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => $job->notifiables->contains($otherSetting));
    }

    public function test_queue_failure_does_not_break_checkout_or_cancel_request(): void
    {
        User::factory()->admin()->create();
        // Очередь недоступна: постановка письма падает
        Notification::shouldReceive('send')->andThrow(new RuntimeException('queue is down'));

        $order = $this->placeOrder();

        $this->actingAs($this->client)->post("/order/{$order->id}/cancel-request", ['cancel_reason' => 'Передумали'])
            ->assertSessionHas('success');
        $this->assertTrue($order->fresh()->hasOpenCancelRequest());
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

        // Текстовая версия письма — без лишних «\.», «\(»: экранируется только разметка
        $text = (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());
        $this->assertStringContainsString('Организация:** ООО «Аква Строй» (УНП 190000001)', $text);
        $this->assertStringContainsString('Иван Клиентов (ivan@client.test)', $text);
        $this->assertStringNotContainsString('\\.', $text);
        $this->assertStringNotContainsString('\\(', $text);
    }

    public function test_line_breaks_in_client_text_do_not_make_blocks(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->placeOrder("Срочно\n# Заголовок\n- пункт\n> цитата");

        $html = (string) (new NewOrderNotification($order))->toMail($admin)->render();

        $this->assertStringNotContainsString('<h1>Заголовок', $html);
        $this->assertStringNotContainsString('<li>пункт', $html);
        $this->assertStringNotContainsString('<blockquote', $html);
        $this->assertStringContainsString('Срочно # Заголовок - пункт &gt; цитата', $html);
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

        $form = $this->actingAs($self)->get("/admin/user/{$admin->id}/edit")->assertOk()->getContent();
        $this->assertStringContainsString('Уведомления на почту', $form);
        $this->assertCheckbox($form, 'notify_new_order', true);
        $this->assertCheckbox($form, 'notify_cancel_request', true);

        $this->actingAs($self)->post("/admin/user/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'admin',
            'notify_new_order' => '0', 'notify_cancel_request' => '1',
        ])->assertRedirect("/admin/user/{$admin->id}");

        $admin->refresh();
        $this->assertFalse($admin->notify_new_order);
        $this->assertTrue($admin->notify_cancel_request);

        // Карточка: те же галочки (x-checkbox) в своей форме
        $card = $this->actingAs($self)->get("/admin/user/{$admin->id}")->assertOk()->getContent();
        $this->assertStringContainsString('action="/admin/user/'.$admin->id.'/notifications"', $card);
        $this->assertCheckbox($card, 'notify_new_order', false);
        $this->assertCheckbox($card, 'notify_cancel_request', true);
    }

    public function test_admin_card_saves_notification_settings(): void
    {
        $self = User::factory()->admin()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($self)->from("/admin/user/{$admin->id}")
            ->post("/admin/user/{$admin->id}/notifications", ['notify_new_order' => '0', 'notify_cancel_request' => '0', 'notify_partner_request' => '0'])
            ->assertRedirect("/admin/user/{$admin->id}")
            ->assertSessionHas('success', 'Настройки уведомлений сохранены');

        $admin->refresh();
        $this->assertFalse($admin->notify_new_order);
        $this->assertFalse($admin->notify_cancel_request);
        $this->assertFalse($admin->notify_partner_request);

        // Своё тоже можно менять; имя, email и роль не трогаются
        $this->actingAs($self)->post("/admin/user/{$self->id}/notifications", ['notify_new_order' => '0', 'notify_cancel_request' => '1', 'notify_partner_request' => '1']);
        $this->assertFalse($self->fresh()->notify_new_order);
        $this->assertTrue($self->fresh()->isAdmin());

        $this->actingAs($self)->post("/admin/user/{$admin->id}/notifications", ['notify_new_order' => 'да'])
            ->assertSessionHasErrors(['notify_new_order', 'notify_cancel_request', 'notify_partner_request']);
    }

    public function test_card_settings_are_not_for_clients_or_non_admins(): void
    {
        $self = User::factory()->admin()->create();

        $this->actingAs($self)->post("/admin/user/{$this->client->id}/notifications", ['notify_new_order' => '0', 'notify_cancel_request' => '0'])
            ->assertSessionHas('error');
        $this->assertTrue($this->client->fresh()->notify_new_order);

        // Клиент не может сам менять настройки администратора
        $this->actingAs($this->client)->post("/admin/user/{$self->id}/notifications", ['notify_new_order' => '0', 'notify_cancel_request' => '0'])
            ->assertForbidden();
        $this->assertTrue($self->fresh()->notify_new_order);
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
     * x-checkbox: скрытое «0» + галочка «1», отмечена или нет.
     */
    private function assertCheckbox(string $html, string $name, bool $checked): void
    {
        $this->assertStringContainsString('<input type="hidden" name="'.$name.'" value="0">', $html);
        $this->assertMatchesRegularExpression(
            '#<input\s+type="checkbox"\s+id="f-'.$name.'"\s+name="'.$name.'"\s+value="1"\s+'.($checked ? 'checked' : '(?!checked)').'#',
            $html,
            "$name: ".($checked ? 'включено' : 'выключено'),
        );
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
