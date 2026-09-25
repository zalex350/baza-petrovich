<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Output\OutputInterface;

class CreateUser extends Command
{
    public const ROLES = ['client', 'admin'];

    protected $signature = 'user:create
        {name? : Имя пользователя}
        {email? : Email (логин)}
        {--role= : Роль: client или admin}
        {--password= : Пароль (если не задан — запрашивается или генерируется)}
        {--generate-password : Сгенерировать пароль и вывести его в консоль}';

    protected $description = 'Create a user account (self-registration is disabled, SRS §3)';

    public function handle(): int
    {
        $name  = $this->argument('name') ?? $this->ask('Имя');
        $email = $this->argument('email') ?? $this->ask('Email');
        $role  = $this->normalizeRole(
            $this->option('role')
                ?? ($this->input->isInteractive() ? $this->choice('Роль', self::ROLES, 0) : 'client')
        );

        $password = $this->option('password');

        if ($password === null && ! $this->option('generate-password') && $this->input->isInteractive()) {
            $password = $this->secret('Пароль (пусто — сгенерировать)');
        }

        // Пустой ввод → генерация до валидации; правила длины применяются
        // только к ручному паролю.
        $password  = $this->cleanInput($password);
        $generated = $password === '';

        if ($generated) {
            $password = Str::password(12);
        }

        $rules = [
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'role'  => ['required', Rule::in(self::ROLES)],
        ];

        if (! $generated) {
            $rules['password'] = ['required', Password::defaults()];
        }

        $validator = Validator::make(compact('name', 'email', 'role', 'password'), $rules);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User([
            'name'     => $name,
            'email'    => $email,
            'password' => $password,
        ]);
        $user->role              = $role;
        $user->email_verified_at = now();
        $user->save();

        $this->info("Пользователь создан: #{$user->id} {$user->email} ({$user->role})");

        if ($generated) {
            $this->printGeneratedPassword($password);
        } else {
            $this->line('Установлен пароль, введённый вручную ('.mb_strlen($password).' симв.).');
        }

        return self::SUCCESS;
    }

    /**
     * Вывод в обход форматтера: Str::password() содержит < > \,
     * а форматтер Symfony съедает «\<» и разбирает <...> как теги.
     */
    private function printGeneratedPassword(string $password): void
    {
        $this->newLine();
        $this->output->writeln("Сгенерированный пароль: {$password}", OutputInterface::OUTPUT_RAW);
        $this->line('Пароль показывается один раз — сохраните его.');
    }

    /**
     * Убирает из ввода невидимый «мусор» терминала: ESC-последовательности
     * (события фокуса ESC[I / ESC[O, bracketed paste, win32-input-mode)
     * и управляющие символы (\r, \n, \t…), затем обрезает пробелы.
     */
    private function cleanInput(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]|\e[@-_]|[\x00-\x1F\x7F]/', '', $value);

        return trim($value);
    }

    /**
     * choice() может вернуть индекс варианта вместо значения — приводим к роли.
     */
    private function normalizeRole(?string $role): ?string
    {
        if ($role !== null && ctype_digit($role) && isset(self::ROLES[(int) $role])) {
            return self::ROLES[(int) $role];
        }

        return $role;
    }
}
