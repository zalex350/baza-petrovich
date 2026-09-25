<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_user_with_given_password_and_role(): void
    {
        $this->artisan('user:create', [
            'name'       => 'Иван Петров',
            'email'      => 'ivan@example.com',
            '--role'     => 'admin',
            '--password' => 'Secret-pass-123',
        ])
            ->expectsOutputToContain('Пользователь создан')
            ->doesntExpectOutputToContain('Сгенерированный пароль')
            ->assertSuccessful();

        $user = User::where('email', 'ivan@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('Иван Петров', $user->name);
        $this->assertSame('admin', $user->role);
        $this->assertTrue(Hash::check('Secret-pass-123', $user->password));
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_generates_password_and_prints_it(): void
    {
        $exitCode = Artisan::call('user:create', [
            'name'                => 'Client',
            'email'               => 'client@example.com',
            '--role'              => 'client',
            '--generate-password' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, preg_match('/Сгенерированный пароль: (\S+)/u', Artisan::output(), $m));

        $user = User::where('email', 'client@example.com')->first();
        $this->assertSame('client', $user->role);
        $this->assertTrue(Hash::check($m[1], $user->password));
    }

    public function test_generated_password_allows_login(): void
    {
        Artisan::call('user:create', [
            'name'                => 'Client',
            'email'               => 'login@example.com',
            '--role'              => 'client',
            '--generate-password' => true,
        ]);

        preg_match('/Сгенерированный пароль: (\S+)/u', Artisan::output(), $m);

        $this->post('/login', [
            'email'    => 'login@example.com',
            'password' => $m[1],
        ])->assertRedirect();

        $this->assertAuthenticated();
    }

    public function test_interactive_mode_asks_for_all_fields(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('Имя', 'Анна')
            ->expectsQuestion('Email', 'anna@example.com')
            ->expectsChoice('Роль', 'admin', ['client', 'admin'])
            ->expectsQuestion('Пароль (пусто — сгенерировать)', 'Interactive-pass-1')
            ->assertSuccessful();

        $user = User::where('email', 'anna@example.com')->first();

        $this->assertSame('admin', $user->role);
        $this->assertTrue(Hash::check('Interactive-pass-1', $user->password));
    }

    public function test_interactive_empty_password_is_generated(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('Имя', 'Олег')
            ->expectsQuestion('Email', 'oleg@example.com')
            ->expectsChoice('Роль', 'client', ['client', 'admin'])
            ->expectsQuestion('Пароль (пусто — сгенерировать)', '')
            ->expectsOutputToContain('Сгенерированный пароль')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'oleg@example.com', 'role' => 'client']);
    }

    public function test_non_interactive_defaults_to_client_and_generated_password(): void
    {
        $this->artisan('user:create', [
            'name'             => 'Default',
            'email'            => 'default@example.com',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Сгенерированный пароль')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'default@example.com', 'role' => 'client']);
    }

    public function test_rejects_unknown_role(): void
    {
        $this->artisan('user:create', [
            'name'       => 'X',
            'email'      => 'x@example.com',
            '--role'     => 'partner',
            '--password' => 'Secret-pass-123',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $this->artisan('user:create', [
            'name'       => 'Dup',
            'email'      => 'dup@example.com',
            '--role'     => 'client',
            '--password' => 'Secret-pass-123',
        ])->assertFailed();

        $this->assertSame(1, User::where('email', 'dup@example.com')->count());
    }

    public function test_rejects_invalid_email_and_short_password(): void
    {
        $this->artisan('user:create', [
            'name'       => 'Bad',
            'email'      => 'not-an-email',
            '--role'     => 'client',
            '--password' => '123',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }
}
