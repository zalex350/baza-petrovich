<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Приглашение нового пользователя: стандартная ссылка сброса пароля Laravel
 * (токен из Password broker), но с текстом приглашения на smesitel.by.
 */
class UserInvitation extends ResetPassword
{
    protected function buildMailMessage($url): MailMessage
    {
        $expire = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Приглашение на smesitel.by')
            ->greeting('Здравствуйте!')
            ->line('Для вас создана учётная запись на smesitel.by.')
            ->line('Чтобы войти, установите пароль по ссылке ниже.')
            ->action('Установить пароль', $url)
            ->line("Ссылка действительна {$expire} мин. Если срок истёк, воспользуйтесь восстановлением пароля на странице входа.")
            ->salutation('Команда smesitel.by');
    }
}
