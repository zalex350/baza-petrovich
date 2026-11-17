<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\MarkdownText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Администратору: сбой обмена с 1С (users.notify_exchange) — задание failed, много ошибок,
 * давно нет цен и остатков, заказы долго ждут передачи. Через очередь, как письма о заказах.
 */
class ExchangeAlertNotification extends Notification implements ShouldQueue
{
    use MarkdownText, Queueable;

    public int $tries = 3;

    /**
     * @param  list<string>  $lines  уже экранированные строки письма
     */
    public function __construct(public string $subject, public array $lines, public string $url)
    {
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Обмен с 1С: '.$this->subject)
            ->greeting($this->subject);

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail->action('Открыть журнал обмена', $this->url)->salutation(config('shop.name'));
    }
}
