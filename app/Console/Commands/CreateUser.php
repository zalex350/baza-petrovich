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
        {--generate-password : Сгенерировать пароль и вывести его в консоль}
        {--debug-input : Показать длину и hex каждого введённого поля до и после очистки}';

    protected $description = 'Create a user account (self-registration is disabled, SRS §3). '
        .'Example: php artisan user:create "Имя" email@example.com --role=client --generate-password. '
        .'Prefer arguments: interactive input in PowerShell via docker-compose exec may arrive with garbage characters';

    public function handle(): int
    {
        $name  = $this->cleanField('name', $this->argument('name') ?? $this->ask('Имя'));
        $email = $this->cleanField('email', $this->argument('email') ?? $this->ask('Email'));
        $role  = $this->normalizeRole($this->cleanField(
            'role',
            $this->option('role')
                ?? ($this->input->isInteractive() ? $this->choice('Роль', self::ROLES, 0) : 'client')
        ));

        $password = $this->option('password');

        if ($password === null && ! $this->option('generate-password') && $this->input->isInteractive()) {
            $password = $this->secret('Пароль (пусто — сгенерировать)');
        }

        // Пустой ввод → генерация до валидации; правила длины применяются
        // только к ручному паролю.
        $password  = $this->cleanField('password', $password);
        $generated = $password === '';

        if (! mb_check_encoding($name, 'UTF-8')) {
            $this->error('Некорректная кодировка ввода, используйте аргументы команды:');
            $this->line('  php artisan user:create "Имя Фамилия" email@example.com --role=client');
            $this->outputRaw('Получено: '.$this->visible($name));

            return self::FAILURE;
        }

        if ($generated) {
            $password = Str::password(12);
        }

        $values = compact('name', 'email', 'role', 'password');

        $rules = [
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'role'  => ['required', Rule::in(self::ROLES)],
        ];

        if (! $generated) {
            $rules['password'] = ['required', Password::defaults()];
        }

        $validator = Validator::make($values, $rules);

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }

                $this->outputRaw('  Получено: '.$this->visible($values[$field]));
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
     * Очищает значение поля; с --debug-input выводит байты до и после очистки.
     */
    private function cleanField(string $field, ?string $raw): string
    {
        $clean = $this->cleanInput($raw);

        if ($this->option('debug-input')) {
            $this->outputRaw(sprintf(
                '[debug-input] %s: raw %s, clean %d байт hex=%s',
                $field,
                $raw === null ? 'null' : strlen($raw).' байт hex='.bin2hex($raw),
                strlen($clean),
                bin2hex($clean),
            ));
        }

        return $clean;
    }

    /**
     * Вывод в обход форматтера: Str::password() содержит < > \,
     * а форматтер Symfony съедает «\<» и разбирает <...> как теги.
     */
    private function printGeneratedPassword(string $password): void
    {
        $this->newLine();
        $this->outputRaw("Сгенерированный пароль: {$password}");
        $this->line('Пароль показывается один раз — сохраните его.');
    }

    private function outputRaw(string $text): void
    {
        $this->output->writeln($text, OutputInterface::OUTPUT_RAW);
    }

    /**
     * Значение в видимом виде: управляющие и не-ASCII символы экранируются
     * (\u001b, и…); не-UTF-8 строка выводится как hex.
     */
    private function visible(?string $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : 'hex:'.bin2hex((string) $value);
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
