<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame(1, preg_match('/^Сгенерированный пароль: (\S+)\r?$/mu', Artisan::output(), $m));
        $this->assertSame(12, mb_strlen($m[1]));

        $user = User::where('email', 'client@example.com')->first();
        $this->assertSame('client', $user->role);
        $this->assertTrue(Hash::check($m[1], $user->password));
    }

    /**
     * Str::password() содержит < > \ — форматтер Symfony съедает «\<»,
     * поэтому пароль должен выводиться «как есть» (OUTPUT_RAW).
     */
    public function test_generated_password_is_printed_without_formatting(): void
    {
        $command = $this->app->make(\App\Console\Commands\CreateUser::class);
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $command->setLaravel($this->app);
        $command->setOutput(new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), $output));

        $method = new \ReflectionMethod($command, 'printGeneratedPassword');
        $method->invoke($command, 'ab\<cd<b>ef');

        $this->assertStringContainsString('Сгенерированный пароль: ab\<cd<b>ef', $output->fetch());
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

    /**
     * Сценарий из баг-репорта: Имя, Email, Роль = 0, Пароль = пусто (Enter).
     */
    public function test_empty_password_input_generates_password_that_works(): void
    {
        $plain = $this->capturePlainPasswords();

        $this->artisan('user:create')
            ->expectsQuestion('Имя', 'test client')
            ->expectsQuestion('Email', 'test@test.com')
            ->expectsChoice('Роль', '0', ['client', 'admin'])
            ->expectsQuestion('Пароль (пусто — сгенерировать)', '')
            ->expectsOutputToContain('Сгенерированный пароль: ')
            ->expectsOutputToContain('Пароль показывается один раз')
            ->doesntExpectOutputToContain('at least 8 characters')
            ->assertSuccessful()
            ->run();

        $user = User::where('email', 'test@test.com')->first();

        $this->assertNotNull($user);
        $this->assertCount(1, $plain->values);
        $this->assertSame(12, mb_strlen($plain->values[0]));
        $this->assertTrue(Hash::check($plain->values[0], $user->password));

        $this->post('/login', ['email' => 'test@test.com', 'password' => $plain->values[0]]);
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Терминал (docker-compose exec из Windows) может прислать при «пустом»
     * Enter невидимую ESC-последовательность: короткая давала ошибку
     * «at least 8 characters», длинная проходила валидацию как ручной пароль —
     * пользователь создавался без вывода пароля.
     */
    #[DataProvider('terminalGarbageProvider')]
    public function test_terminal_escape_sequence_input_generates_password(string $input): void
    {
        $plain = $this->capturePlainPasswords();

        $this->artisan('user:create')
            ->expectsQuestion('Имя', 'test')
            ->expectsQuestion('Email', 'test@g.c')
            ->expectsChoice('Роль', '0', ['client', 'admin'])
            ->expectsQuestion('Пароль (пусто — сгенерировать)', $input)
            ->expectsOutputToContain('Сгенерированный пароль: ')
            ->expectsOutputToContain('Пароль показывается один раз')
            ->doesntExpectOutputToContain('введённый вручную')
            ->assertSuccessful()
            ->run();

        $user = User::where('email', 'test@g.c')->first();

        $this->assertNotNull($user);
        $this->assertSame('client', $user->role);
        $this->assertCount(1, $plain->values);
        $this->assertSame(12, mb_strlen($plain->values[0]));
        $this->assertTrue(Hash::check($plain->values[0], $user->password));
    }

    public static function terminalGarbageProvider(): array
    {
        return [
            'focus in'           => ["\e[I"],
            'focus out + in'     => ["\e[O\e[I"],
            'bracketed paste'    => ["\e[200~\e[201~"],
            'win32-input Enter'  => ["\e[13;28;13;1;0;1_\e[13;28;13;0;0;1_"],
            'CRLF'               => ["\r\n"],
        ];
    }

    public function test_manual_password_is_reported(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('Имя', 'Manual')
            ->expectsQuestion('Email', 'manual@example.com')
            ->expectsChoice('Роль', 'client', ['client', 'admin'])
            ->expectsQuestion('Пароль (пусто — сгенерировать)', 'Manual-pass-123')
            ->expectsOutputToContain('Установлен пароль, введённый вручную (15 симв.)')
            ->doesntExpectOutputToContain('Сгенерированный пароль')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('Manual-pass-123', User::where('email', 'manual@example.com')->first()->password));
    }

    public function test_whitespace_password_input_is_treated_as_empty(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('Имя', 'Space')
            ->expectsQuestion('Email', 'space@example.com')
            ->expectsChoice('Роль', 'client', ['client', 'admin'])
            ->expectsQuestion('Пароль (пусто — сгенерировать)', "  \r")
            ->expectsOutputToContain('Сгенерированный пароль')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'space@example.com']);
    }

    public function test_short_manual_password_fails_and_user_is_not_created(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('Имя', 'Short')
            ->expectsQuestion('Email', 'short@example.com')
            ->expectsChoice('Роль', 'client', ['client', 'admin'])
            ->expectsQuestion('Пароль (пусто — сгенерировать)', '1234567')
            ->expectsOutputToContain('at least 8 characters')
            ->doesntExpectOutputToContain('Сгенерированный пароль')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'short@example.com']);
        $this->assertSame(0, User::count());
    }

    public function test_role_choice_by_index_saves_client(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('Имя', 'Index')
            ->expectsQuestion('Email', 'index@example.com')
            ->expectsChoice('Роль', '0', ['client', 'admin'])
            ->expectsQuestion('Пароль (пусто — сгенерировать)', 'Manual-pass-123')
            ->assertSuccessful();

        $this->assertSame('client', User::where('email', 'index@example.com')->first()->role);
    }

    public function test_role_choice_by_index_saves_admin(): void
    {
        $this->artisan('user:create', [
            'name'       => 'Index Admin',
            'email'      => 'index-admin@example.com',
            '--role'     => '1',
            '--password' => 'Manual-pass-123',
        ])->assertSuccessful();

        $this->assertSame('admin', User::where('email', 'index-admin@example.com')->first()->role);
    }

    /**
     * Перехватывает открытые пароли, которые cast 'hashed' передаёт в Hash::make().
     */
    private function capturePlainPasswords(): object
    {
        $captured = new class { public array $values = []; };

        $this->app->instance('hash', new class($this->app, $captured) extends HashManager
        {
            public function __construct($app, private object $captured)
            {
                parent::__construct($app);
            }

            public function make($value, array $options = []): string
            {
                $this->captured->values[] = $value;

                return parent::make($value, $options);
            }
        });
        Hash::clearResolvedInstance('hash');

        return $captured;
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
