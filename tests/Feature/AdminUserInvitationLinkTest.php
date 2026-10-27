<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ссылка приглашения в карточке пользователя (админка): поле + «копировать», срок,
 * «Выпустить новую ссылку»; после принятия приглашения блока нет.
 */
class AdminUserInvitationLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Carbon::setTestNow('2026-03-05 14:07:00');
        $this->admin = User::factory()->admin()->create();
    }

    private function invite(): User
    {
        $this->actingAs($this->admin)->post('/admin/user', [
            'name' => 'Иванов Иван', 'email' => 'ivanov@santeh.by', 'role' => 'client',
            'organization_id' => Organization::factory()->create()->id, 'password_mode' => 'invite',
        ])->assertSessionHasNoErrors();

        return User::where('email', 'ivanov@santeh.by')->sole();
    }

    /** Токены из писем-приглашений по порядку отправки */
    private function mailedTokens(User $user): array
    {
        return Notification::sent($user, UserInvitation::class)->map(fn (UserInvitation $n) => $n->token)->all();
    }

    private function card(User $user): TestResponse
    {
        return $this->actingAs($this->admin)->get("/admin/user/{$user->id}")->assertOk();
    }

    private function acceptWith(User $user, string $token)
    {
        $this->post('/logout');

        return $this->post('/reset-password', [
            'invite' => 1, 'token' => $token, 'email' => $user->email,
            'password' => 'My-new-pass-123', 'password_confirmation' => 'My-new-pass-123',
        ]);
    }

    public function test_card_shows_link_from_mail_with_expiry_right_after_invite(): void
    {
        $user = $this->invite();
        [$token] = $this->mailedTokens($user);
        $url = UserInvitation::urlFor($token, $user);

        $this->card($user)
            ->assertSee('data-invitation', false)
            ->assertSee('value="'.e($url).'"', false)
            ->assertSee('readonly', false)
            ->assertSee('data-copy', false)
            ->assertSee('Действует до 08.03.2026 14:07')
            ->assertSee('Выпустить новую ссылку');

        // Ссылка из карточки рабочая — та же, что в письме
        $this->assertStringContainsString('invite=1', $url);
        $this->assertTrue(Password::broker(UserInvitation::BROKER)->tokenExists($user, $token));
    }

    public function test_reissue_sends_new_link_and_old_stops_working(): void
    {
        $user = $this->invite();

        // Сразу после приглашения — без ограничения «раз в минуту»
        $this->actingAs($this->admin)->from("/admin/user/{$user->id}")->post("/admin/user/{$user->id}/invitation")
            ->assertRedirect("/admin/user/{$user->id}")->assertSessionHas('success');

        [$old, $new] = $this->mailedTokens($user);
        $this->assertNotSame($old, $new);

        $this->card($user)
            ->assertSee('value="'.e(UserInvitation::urlFor($new, $user)).'"', false)
            ->assertDontSee(e(UserInvitation::urlFor($old, $user)), false);

        $this->acceptWith($user, $old)->assertSessionHasErrors('email');
        $this->acceptWith($user, $new)->assertSessionHasNoErrors();
    }

    public function test_block_is_hidden_after_invitation_is_accepted(): void
    {
        $user = $this->invite();
        [$token] = $this->mailedTokens($user);

        $this->acceptWith($user, $token)->assertSessionHasNoErrors();

        $this->card($user)->assertDontSee('data-invitation', false)->assertDontSee('Выпустить новую ссылку');

        // Принятое приглашение заново не выпускается
        $this->actingAs($this->admin)->post("/admin/user/{$user->id}/invitation")->assertSessionHas('error');
        $this->assertCount(1, $this->mailedTokens($user));
    }

    public function test_user_with_password_has_no_block(): void
    {
        $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->card($user)->assertDontSee('data-invitation', false);
        $this->actingAs($this->admin)->post("/admin/user/{$user->id}/invitation")->assertSessionHas('error');
        Notification::assertNothingSentTo($user);
    }

    public function test_expired_link_is_not_shown_and_can_be_reissued(): void
    {
        $user = $this->invite();

        Carbon::setTestNow(now()->addHours(73));

        $this->card($user)
            ->assertSee('Срок ссылки истёк 08.03.2026 14:07')
            ->assertDontSee('id="invite-url"', false)
            ->assertSee('Выпустить новую ссылку');

        $this->actingAs($this->admin)->post("/admin/user/{$user->id}/invitation")->assertSessionHas('success');
        $this->card($user)->assertSee('id="invite-url"', false)->assertSee('Действует до 11.03.2026 15:07');
    }

    public function test_invitation_issued_before_links_shows_hint_to_reissue(): void
    {
        $user = $this->invite();
        DB::table('user_invitation_tokens')->update(['token_encrypted' => null]);

        $this->card($user)
            ->assertSee('data-invitation', false)
            ->assertDontSee('id="invite-url"', false)
            ->assertSee('Чтобы увидеть ссылку, выпустите новую');
    }

    public function test_token_is_stored_encrypted(): void
    {
        $user = $this->invite();
        [$token] = $this->mailedTokens($user);

        $row = DB::table('user_invitation_tokens')->where('email', $user->email)->sole();
        $this->assertStringNotContainsString($token, $row->token_encrypted);
        $this->assertStringNotContainsString($token, $row->token);
    }
}
