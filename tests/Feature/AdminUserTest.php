<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Главный Админ']);
        $this->org = Organization::factory()->create(['name' => 'ООО Сантехторг']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'            => 'Иванов Иван Иванович',
            'email'           => 'ivanov@santeh.by',
            'phone'           => '+375 29 111-22-33',
            'role'            => 'client',
            'organization_id' => $this->org->id,
            'password_mode'   => 'manual',
            'password'        => 'Secret-pass-123',
        ], $overrides);
    }

    // --- Доступ ---

    public static function urlsProvider(): array
    {
        return [
            'список'          => ['get', '/admin/users'],
            'форма'           => ['get', '/admin/user/create'],
            'создание'        => ['post', '/admin/user'],
            'карточка'        => ['get', '/admin/user/{id}'],
            'редактирование'  => ['get', '/admin/user/{id}/edit'],
            'сохранение'      => ['post', '/admin/user/{id}'],
            'блокировка'      => ['post', '/admin/user/{id}/block'],
            'разблокировка'   => ['post', '/admin/user/{id}/unblock'],
            'сброс пароля'    => ['post', '/admin/user/{id}/reset-password'],
            'новая ссылка приглашения' => ['post', '/admin/user/{id}/invitation'],
        ];
    }

    #[DataProvider('urlsProvider')]
    public function test_guest_is_redirected_to_login(string $method, string $url): void
    {
        $target = User::factory()->create(['organization_id' => $this->org->id]);

        $this->{$method}(str_replace('{id}', $target->id, $url))->assertRedirect(route('login'));

        $this->assertNull($target->fresh()->blocked_at);
    }

    #[DataProvider('urlsProvider')]
    public function test_client_gets_403(string $method, string $url): void
    {
        $client = User::factory()->create(['organization_id' => $this->org->id]);
        $target = User::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($client)
            ->{$method}(str_replace('{id}', $target->id, $url))
            ->assertForbidden();

        $this->assertNull($target->fresh()->blocked_at);
    }

    public function test_admin_gets_200_on_pages(): void
    {
        $target = User::factory()->create(['organization_id' => $this->org->id]);

        foreach (['/admin/users', '/admin/user/create', "/admin/user/{$target->id}", "/admin/user/{$target->id}/edit"] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }

    public function test_admin_menu_has_users_link(): void
    {
        $this->actingAs($this->admin)->get('/admin/users')
            ->assertSee('href="/admin/users"', false)
            ->assertSee('Пользователи');
    }

    public function test_there_is_no_delete_route(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->admin)->post("/admin/user/{$target->id}/delete")->assertNotFound();
        $this->assertModelExists($target);
    }

    // --- Список, поиск, фильтры ---

    public static function searchProvider(): array
    {
        return [
            'по ФИО'         => ['Петров'],
            'по email'       => ['petrov@'],
            'по организации' => ['Сантехторг'],
        ];
    }

    #[DataProvider('searchProvider')]
    public function test_search(string $query): void
    {
        User::factory()->create(['name' => 'Петров Пётр', 'email' => 'petrov@santeh.by', 'organization_id' => $this->org->id]);
        $other = Organization::factory()->create(['name' => 'ИП Другое']);
        User::factory()->create(['name' => 'Сидоров Сидор', 'email' => 'sidorov@mail.by', 'organization_id' => $other->id]);

        $this->actingAs($this->admin)->get('/admin/users?q='.urlencode($query))
            ->assertOk()
            ->assertSee('Петров Пётр')
            ->assertDontSee('Сидоров Сидор');
    }

    public function test_filter_by_role(): void
    {
        User::factory()->create(['name' => 'Клиент Один', 'organization_id' => $this->org->id]);
        User::factory()->admin()->create(['name' => 'Второй Админ']);

        // Имя вошедшего администратора есть в шапке, поэтому проверяем по «Второй Админ»
        $this->actingAs($this->admin)->get('/admin/users?role=admin')
            ->assertSee('Второй Админ')
            ->assertDontSee('Клиент Один');

        $this->actingAs($this->admin)->get('/admin/users?role=client')
            ->assertSee('Клиент Один')
            ->assertDontSee('Второй Админ');
    }

    public function test_filter_by_status(): void
    {
        User::factory()->create(['name' => 'Активный Клиент', 'organization_id' => $this->org->id]);
        User::factory()->create(['name' => 'Блокированный Клиент', 'organization_id' => $this->org->id, 'blocked_at' => now()]);

        $this->actingAs($this->admin)->get('/admin/users?status=blocked')
            ->assertSee('Блокированный Клиент')
            ->assertDontSee('Активный Клиент');

        $this->actingAs($this->admin)->get('/admin/users?status=active')
            ->assertSee('Активный Клиент')
            ->assertDontSee('Блокированный Клиент');
    }

    public function test_list_is_paginated_and_keeps_filters(): void
    {
        User::factory()->count(55)->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->admin)->get('/admin/users?role=client')
            ->assertViewHas('users', fn ($p) => $p->count() === 50 && $p->total() === 55)
            ->assertSee('role=client&amp;page=2', false);
    }

    // --- Создание ---

    public function test_create_with_manual_password(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->admin)->post('/admin/user', $this->payload());

        $user = User::where('email', 'ivanov@santeh.by')->first();

        $this->assertNotNull($user);
        $response->assertRedirect("/admin/user/{$user->id}");
        $this->assertSame('Иванов Иван Иванович', $user->name);
        $this->assertSame('+375 29 111-22-33', $user->phone);
        $this->assertSame(UserRole::Client, $user->role);
        $this->assertSame($this->org->id, $user->organization_id);
        $this->assertTrue(Hash::check('Secret-pass-123', $user->password));
        Notification::assertNothingSent();

        $this->post('/logout');
        $this->post('/login', ['email' => 'ivanov@santeh.by', 'password' => 'Secret-pass-123']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_manual_password_must_be_at_least_8_chars(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/user', $this->payload(['password' => '1234567']))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'ivanov@santeh.by']);
    }

    public function test_manual_password_is_required_in_manual_mode(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/user', $this->payload(['password' => '']))
            ->assertSessionHasErrors('password');
    }

    public function test_create_with_invitation_link(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post('/admin/user', $this->payload(['password_mode' => 'invite', 'password' => '']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'ссылка для установки пароля отправлена'));

        $user = User::where('email', 'ivanov@santeh.by')->first();

        $this->assertNotNull($user);

        Notification::assertSentTo($user, UserInvitation::class, function (UserInvitation $notification) use ($user) {
            $this->assertTrue(Password::broker(UserInvitation::BROKER)->tokenExists($user, $notification->token));

            $mail = $notification->toMail($user);
            $this->assertSame('Приглашение на smesitel.by', $mail->subject);
            $this->assertStringContainsString('smesitel.by', implode(' ', $mail->introLines));
            $this->assertSame('Установить пароль', $mail->actionText);
            $this->assertStringContainsString($notification->token, $mail->actionUrl);

            return true;
        });
        Notification::assertNotSentTo($user, ResetPassword::class, fn ($n) => ! $n instanceof UserInvitation);
    }

    public function test_invited_user_sets_password_via_link_and_logs_in(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)->post('/admin/user', $this->payload(['password_mode' => 'invite', 'password' => '']));
        $this->post('/logout');

        $user = User::where('email', 'ivanov@santeh.by')->first();
        $token = null;
        Notification::assertSentTo($user, UserInvitation::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->post('/reset-password', [
            'invite'                => 1,
            'token'                 => $token,
            'email'                 => 'ivanov@santeh.by',
            'password'              => 'My-new-pass-123',
            'password_confirmation' => 'My-new-pass-123',
        ])->assertSessionHasNoErrors();

        $this->post('/login', ['email' => 'ivanov@santeh.by', 'password' => 'My-new-pass-123']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_client_without_organization_is_not_created(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/user', $this->payload(['organization_id' => '']))
            ->assertSessionHasErrors(['organization_id' => 'Для клиента необходимо выбрать организацию.']);

        $this->assertDatabaseMissing('users', ['email' => 'ivanov@santeh.by']);
    }

    public function test_admin_without_organization_is_created(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/user', $this->payload(['role' => 'admin', 'organization_id' => '']))
            ->assertSessionHasNoErrors();

        $user = User::where('email', 'ivanov@santeh.by')->first();

        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertNull($user->organization_id);
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'ivanov@santeh.by']);

        $this->actingAs($this->admin)
            ->post('/admin/user', $this->payload())
            ->assertSessionHasErrors('email');
    }

    public function test_unknown_role_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/user', $this->payload(['role' => 'partner']))
            ->assertSessionHasErrors('role');
    }

    // --- Редактирование ---

    public function test_admin_can_edit_user(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $newOrg = Organization::factory()->create();

        $this->actingAs($this->admin)
            ->post("/admin/user/{$user->id}", [
                'name'            => 'Новое ФИО',
                'email'           => 'new@santeh.by',
                'phone'           => '+375 44 000-00-00',
                'role'            => 'client',
                'organization_id' => $newOrg->id,
            ])
            ->assertRedirect("/admin/user/{$user->id}");

        $user->refresh();
        $this->assertSame('Новое ФИО', $user->name);
        $this->assertSame('new@santeh.by', $user->email);
        $this->assertSame('+375 44 000-00-00', $user->phone);
        $this->assertSame($newOrg->id, $user->organization_id);
    }

    public function test_edit_does_not_change_orders(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $order = Order::create([
            'user_id'         => $user->id,
            'organization_id' => $this->org->id,
            'status'          => OrderStatus::New,
            'total'           => 150,
        ]);
        $newOrg = Organization::factory()->create();

        $this->actingAs($this->admin)->post("/admin/user/{$user->id}", [
            'name'            => $user->name,
            'email'           => $user->email,
            'role'            => 'admin',
            'organization_id' => $newOrg->id,
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame($this->org->id, $order->organization_id);
        $this->assertSame($user->id, $order->user_id);
        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertEquals(150, $order->total);
    }

    public function test_edit_client_requires_organization(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->admin)->post("/admin/user/{$user->id}", [
            'name'            => $user->name,
            'email'           => $user->email,
            'role'            => 'client',
            'organization_id' => '',
        ])->assertSessionHasErrors('organization_id');

        $this->assertSame($this->org->id, $user->fresh()->organization_id);
    }

    public function test_edit_keeps_own_email(): void
    {
        $user = User::factory()->create(['email' => 'same@santeh.by', 'organization_id' => $this->org->id]);

        $this->actingAs($this->admin)->post("/admin/user/{$user->id}", [
            'name'            => 'Имя',
            'email'           => 'same@santeh.by',
            'role'            => 'client',
            'organization_id' => $this->org->id,
        ])->assertSessionHasNoErrors();
    }

    public function test_admin_cannot_remove_own_admin_role(): void
    {
        $this->actingAs($this->admin)
            ->from("/admin/user/{$this->admin->id}/edit")
            ->post("/admin/user/{$this->admin->id}", [
                'name'            => $this->admin->name,
                'email'           => $this->admin->email,
                'role'            => 'client',
                'organization_id' => $this->org->id,
            ])
            ->assertRedirect("/admin/user/{$this->admin->id}/edit")
            ->assertSessionHas('error', 'Нельзя снять с себя роль администратора');

        $this->assertSame(UserRole::Admin, $this->admin->fresh()->role);
    }

    public function test_admin_can_edit_own_profile_keeping_role(): void
    {
        $this->actingAs($this->admin)->post("/admin/user/{$this->admin->id}", [
            'name'  => 'Админ Переименован',
            'email' => $this->admin->email,
            'role'  => 'admin',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Админ Переименован', $this->admin->fresh()->name);
    }

    public function test_admin_can_demote_another_admin(): void
    {
        $other = User::factory()->admin()->create();

        $this->actingAs($this->admin)->post("/admin/user/{$other->id}", [
            'name'            => $other->name,
            'email'           => $other->email,
            'role'            => 'client',
            'organization_id' => $this->org->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(UserRole::Client, $other->fresh()->role);
    }

    // --- Блокировка ---

    public function test_admin_can_block_and_unblock_user(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->admin)->post("/admin/user/{$user->id}/block")
            ->assertSessionHas('success');
        $this->assertTrue($user->fresh()->isBlocked());

        $this->actingAs($this->admin)->post("/admin/user/{$user->id}/unblock")
            ->assertSessionHas('success');
        $this->assertFalse($user->fresh()->isBlocked());
    }

    public function test_admin_cannot_block_himself(): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/user/{$this->admin->id}/block")
            ->assertSessionHas('error', 'Нельзя заблокировать самого себя');

        $this->assertFalse($this->admin->fresh()->isBlocked());
        $this->actingAs($this->admin)->get('/admin/users')->assertOk();
    }

    public function test_card_shows_status_and_actions(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id, 'blocked_at' => now()]);

        $this->actingAs($this->admin)->get("/admin/user/{$user->id}")
            ->assertSee('Заблокирован')
            ->assertSee('Разблокировать')
            ->assertSee('Сбросить пароль')
            ->assertSee('ООО Сантехторг');

        $this->actingAs($this->admin)->get("/admin/user/{$this->admin->id}")
            ->assertSee('Нельзя заблокировать самого себя')
            ->assertDontSee("/admin/user/{$this->admin->id}/block", false);
    }

    // --- Сброс пароля ---

    public function test_reset_password_sends_standard_reset_link(): void
    {
        Notification::fake();

        $user = User::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->admin)
            ->post("/admin/user/{$user->id}/reset-password")
            ->assertSessionHas('success', "Ссылка для восстановления пароля отправлена на {$user->email}");

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            return ! $n instanceof UserInvitation
                && Password::broker()->tokenExists($user, $n->token);
        });
    }

    public function test_reset_password_is_throttled(): void
    {
        Notification::fake();

        $user = User::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->admin)->post("/admin/user/{$user->id}/reset-password")->assertSessionHas('success');
        $this->actingAs($this->admin)->post("/admin/user/{$user->id}/reset-password")->assertSessionHas('error');

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    // --- Карточка организации ---

    public function test_organization_card_links_to_users(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->admin)->get("/admin/organization/{$this->org->id}")
            ->assertSee("/admin/user/{$user->id}", false);
    }
}
