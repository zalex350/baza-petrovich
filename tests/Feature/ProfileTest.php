<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create(['email' => 'own@example.com']);

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'phone' => '+375 29 111-22-33',
                // Email, роль и организация в профиле не меняются (BR-116)
                'email' => 'test@example.com',
                'role' => 'admin',
                'organization_id' => 999,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile')
            ->assertSessionHas('success', 'Профиль сохранён');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('+375 29 111-22-33', $user->phone);
        $this->assertSame('own@example.com', $user->email);
        $this->assertFalse($user->isAdmin());
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /**
     * B2B: учётную запись блокирует администратор, сам пользователь её не удаляет.
     */
    public function test_nobody_can_delete_own_account(): void
    {
        foreach ([User::factory()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get('/profile')
                ->assertOk()
                ->assertDontSee('Delete Account')
                ->assertDontSee('userDeletion', false);

            $this->actingAs($user)->delete('/profile', ['password' => 'password'])
                ->assertStatus(405);

            $this->assertNotNull($user->fresh());
        }
    }

    public function test_profile_page_is_in_russian(): void
    {
        $this->actingAs(User::factory()->create())->get('/profile')
            ->assertOk()
            ->assertSee('Профиль')
            ->assertSee('ФИО')
            ->assertSee('href="/account/password"', false)
            ->assertDontSee('Profile Information')
            ->assertDontSee('Update Password');

        $this->actingAs(User::factory()->create())->get('/account/password')
            ->assertOk()
            ->assertSee('Смена пароля')
            ->assertSee('Текущий пароль');
    }
}
