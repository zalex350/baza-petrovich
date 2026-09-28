<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlockedUserTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email'           => 'client@santeh.by',
            'organization_id' => Organization::factory()->create()->id,
        ]);
    }

    private function login(): \Illuminate\Testing\TestResponse
    {
        return $this->post('/login', ['email' => 'client@santeh.by', 'password' => 'password']);
    }

    public function test_blocked_user_cannot_log_in(): void
    {
        $this->user->forceFill(['blocked_at' => now()])->save();

        $this->login()->assertSessionHasErrors(['email' => 'Учётная запись заблокирована']);

        $this->assertGuest();
    }

    public function test_wrong_password_for_blocked_user_shows_generic_error(): void
    {
        $this->user->forceFill(['blocked_at' => now()])->save();

        $this->post('/login', ['email' => 'client@santeh.by', 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    }

    public function test_active_session_is_terminated_after_blocking(): void
    {
        $this->login();
        $this->assertAuthenticatedAs($this->user);
        $this->get('/orders')->assertOk();

        // Администратор блокирует пользователя из своей сессии
        User::whereKey($this->user->id)->update(['blocked_at' => now()]);

        // Следующий запрос пользователя: guard заново загружает его из БД
        $this->app['auth']->forgetGuards();

        $this->get('/orders')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Учётная запись заблокирована']);

        $this->assertGuest();
    }

    public function test_blocked_user_is_logged_out_on_any_page(): void
    {
        $this->user->forceFill(['blocked_at' => now()])->save();

        $this->actingAs($this->user)->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_error_message_is_shown_on_login_page(): void
    {
        $this->user->forceFill(['blocked_at' => now()])->save();

        $this->actingAs($this->user)
            ->followingRedirects()
            ->get('/orders')
            ->assertSee('Учётная запись заблокирована');
    }

    public function test_unblocking_restores_login(): void
    {
        $this->user->forceFill(['blocked_at' => now()])->save();
        $this->login()->assertSessionHasErrors('email');

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post("/admin/user/{$this->user->id}/unblock");
        $this->post('/logout');

        $this->login()->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->user);
        $this->assertSame(1, User::where('email', 'client@santeh.by')->count());
    }

    public function test_active_user_is_not_affected(): void
    {
        $this->actingAs($this->user)->get('/orders')->assertOk();
        $this->assertAuthenticatedAs($this->user);
    }
}
