<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Приглашение пользователя — отдельный брокер auth.passwords.invitations: ссылка
 * живёт 72 часа, обычный сброс пароля — 60 минут.
 */
class InvitationBrokerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * Админ приглашает пользователя; возвращает [пользователь, токен].
     */
    private function invite(): array
    {
        $this->actingAs($this->admin)->post('/admin/user', [
            'name'            => 'Иванов Иван',
            'email'           => 'ivanov@santeh.by',
            'phone'           => '+375 29 111-22-33',
            'role'            => 'client',
            'organization_id' => Organization::factory()->create()->id,
            'password_mode'   => 'invite',
            'password'        => '',
        ])->assertSessionHasNoErrors();

        $this->post('/logout');

        $user = User::where('email', 'ivanov@santeh.by')->firstOrFail();
        $token = null;

        Notification::assertSentTo($user, UserInvitation::class, function (UserInvitation $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return [$user, $token];
    }

    private function setPassword(string $token, bool $invite = true)
    {
        return $this->post('/reset-password', array_filter([
            'invite'                => $invite ? 1 : null,
            'token'                 => $token,
            'email'                 => 'ivanov@santeh.by',
            'password'              => 'My-new-pass-123',
            'password_confirmation' => 'My-new-pass-123',
        ]));
    }

    public function test_invitation_broker_lives_72_hours_and_reset_60_minutes(): void
    {
        $this->assertSame(72 * 60, config('auth.passwords.invitations.expire'));
        $this->assertSame(60, config('auth.passwords.users.expire'));
        $this->assertSame('72 ч', UserInvitation::expireText());
    }

    public function test_invitation_mail_says_72_hours_and_link_is_marked(): void
    {
        [$user] = $this->invite();

        Notification::assertSentTo($user, UserInvitation::class, function (UserInvitation $n) use ($user) {
            $mail = $n->toMail($user);

            $this->assertStringContainsString('Ссылка действительна 72 ч', implode(' ', $mail->outroLines));
            $this->assertStringContainsString('invite=1', $mail->actionUrl);
            $this->assertTrue(Password::broker('invitations')->tokenExists($user, $n->token));
            $this->assertFalse(Password::broker()->tokenExists($user, $n->token));

            return true;
        });
    }

    public function test_reset_form_from_invitation_keeps_invite_flag(): void
    {
        [, $token] = $this->invite();

        $this->get("/reset-password/{$token}?email=ivanov@santeh.by&invite=1")
            ->assertOk()
            ->assertSee('name="invite" value="1"', false);

        $this->get('/reset-password/abc?email=ivanov@santeh.by')
            ->assertOk()
            ->assertDontSee('name="invite"', false);
    }

    public function test_invitation_link_works_after_71_hours(): void
    {
        [$user, $token] = $this->invite();

        $this->travel(71)->hours();

        $this->setPassword($token)->assertSessionHasNoErrors()->assertRedirect(route('catalog.index'));
        $this->assertTrue(Hash::check('My-new-pass-123', $user->fresh()->password));
    }

    public function test_after_setting_password_from_invitation_user_is_logged_in(): void
    {
        [$user, $token] = $this->invite();

        $this->setPassword($token)
            ->assertRedirect(route('catalog.index'))
            ->assertSessionHas('success');

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_invited_admin_goes_to_orders(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'ivanov@santeh.by']);
        $token = Password::broker('invitations')->createToken($admin);

        $this->setPassword($token)->assertRedirect('/admin/orders');
        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_used_or_wrong_invitation_link_shows_invalid_page_without_form(): void
    {
        [, $token] = $this->invite();

        // Подменённый токен
        $this->get('/reset-password/wrong?email=ivanov@santeh.by&invite=1')
            ->assertStatus(410)
            ->assertSee('Ссылка недействительна или устарела')
            ->assertDontSee('name="password"', false);

        // Использованная ссылка
        $this->setPassword($token);
        $this->post('/logout');

        $this->get("/reset-password/{$token}?email=ivanov@santeh.by&invite=1")
            ->assertStatus(410)
            ->assertSee('data-invite-invalid', false)
            ->assertDontSee('name="password"', false);
    }

    public function test_expired_invitation_link_shows_invalid_page(): void
    {
        [, $token] = $this->invite();

        $this->travel(72 * 60 + 1)->minutes();

        $this->get("/reset-password/{$token}?email=ivanov@santeh.by&invite=1")
            ->assertStatus(410)
            ->assertSee('Ссылка недействительна или устарела');
    }

    public function test_regular_password_reset_still_redirects_to_login(): void
    {
        $user = User::factory()->create(['email' => 'ivanov@santeh.by']);
        $token = Password::broker()->createToken($user);

        $this->setPassword($token, invite: false)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_invitation_link_expires_after_72_hours(): void
    {
        [$user, $token] = $this->invite();

        $this->travel(72 * 60 + 1)->minutes();

        $this->setPassword($token)->assertSessionHasErrors(['email' => trans('passwords.token')]);
        $this->assertFalse(Hash::check('My-new-pass-123', $user->fresh()->password));
    }

    public function test_invitation_token_is_not_a_password_reset_token(): void
    {
        [, $token] = $this->invite();

        $this->setPassword($token, invite: false)->assertSessionHasErrors('email');
    }

    public function test_regular_reset_link_still_expires_after_60_minutes(): void
    {
        $user = User::factory()->create(['email' => 'ivanov@santeh.by']);
        $token = Password::broker()->createToken($user);

        // Обычный токен не становится «приглашением» с флагом invite
        $this->setPassword($token, invite: true)->assertSessionHasErrors('email');

        $this->travel(61)->minutes();

        $this->setPassword($token, invite: false)->assertSessionHasErrors(['email' => trans('passwords.token')]);
    }
}
