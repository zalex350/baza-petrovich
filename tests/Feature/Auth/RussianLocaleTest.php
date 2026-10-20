<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Русская локализация: страницы входа, сообщения валидации и авторизации, письма.
 */
class RussianLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_locale_is_russian(): void
    {
        $this->assertSame('ru', app()->getLocale());
    }

    public function test_login_page_is_in_russian_with_remember_me(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Пароль')
            ->assertSee('Запомнить меня')
            ->assertSee('Забыли пароль?')
            ->assertSee('Войти')
            ->assertSee('name="remember"', false)
            ->assertDontSee('Remember me')
            ->assertDontSee('Log in');
    }

    public function test_remember_me_still_sets_cookie(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => 'on']);

        $this->assertAuthenticatedAs($user);
        $this->assertNotEmpty(array_filter(
            $response->headers->getCookies(),
            fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_')
        ));
    }

    public function test_failed_login_and_validation_messages_are_russian(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'Неверный email или пароль.']);

        $this->post('/login', ['email' => '', 'password' => ''])
            ->assertSessionHasErrors([
                'email'    => 'Заполните поле «Email».',
                'password' => 'Заполните поле «Пароль».',
            ]);
    }

    public function test_password_reset_pages_are_russian(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('Отправить ссылку для восстановления')
            ->assertDontSee('Email Password Reset Link');

        $this->get('/reset-password/token')
            ->assertOk()
            ->assertSee('Подтверждение пароля')
            ->assertSee('Установить пароль');

        $this->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHasErrors(['email' => 'Пользователь с таким email не найден.']);
    }

    public function test_password_reset_email_is_russian(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $mail = $notification->toMail($user);
            $html = (string) $mail->render();

            $this->assertSame('Восстановление пароля на smesitel.by', $mail->subject);
            $this->assertSame('Установить пароль', $mail->actionText);
            $this->assertStringContainsString('Здравствуйте!', $html);
            $this->assertStringContainsString('С уважением,', $html);
            $this->assertStringContainsString('Если кнопка «Установить пароль» не работает', $html);
            $this->assertStringNotContainsString('Regards', $html);

            return true;
        });
    }
}
