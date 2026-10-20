<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Приглашение нового пользователя: ссылка на установку пароля из брокера
 * auth.passwords.invitations (72 ч). Ссылка ведёт на обычную форму установки
 * пароля с invite=1 — форма проверяет токен этим брокером.
 */
class UserInvitation extends ResetPassword
{
    public const BROKER = 'invitations';

    protected function resetUrl($notifiable)
    {
        return url(route('password.reset', [
            'token'  => $this->token,
            'email'  => $notifiable->getEmailForPasswordReset(),
            'invite' => 1,
        ], false));
    }

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Приглашение на smesitel.by')
            ->greeting('Здравствуйте!')
            ->line('Для вас создана учётная запись на smesitel.by.')
            ->line('Чтобы войти, установите пароль по ссылке ниже.')
            ->action('Установить пароль', $url)
            ->line('Ссылка действительна ' . self::expireText() . '. Если срок истёк, попросите менеджера отправить приглашение ещё раз или воспользуйтесь восстановлением пароля на странице входа.')
            ->salutation('Команда smesitel.by');
    }

    /**
     * «72 ч» / «90 мин» — срок из настройки брокера.
     */
    public static function expireText(): string
    {
        $minutes = (int) config('auth.passwords.' . self::BROKER . '.expire');

        return $minutes % 60 === 0 ? intdiv($minutes, 60) . ' ч' : "{$minutes} мин";
    }
}
