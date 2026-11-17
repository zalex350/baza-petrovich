<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Models\User;
use App\Notifications\Concerns\MarkdownText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Администратору: новая заявка с сайта (users.notify_partner_request — «Заявки с сайта»).
 */
class LeadNotification extends Notification implements ShouldQueue
{
    use MarkdownText, Queueable;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Lead $lead)
    {
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $l = $this->lead;

        return (new MailMessage)
            ->subject('Заявка «'.$l->typeLabel().'»'.($l->subject ? ': '.self::text($l->subject) : ''))
            ->greeting('Новая заявка «'.$l->typeLabel().'»')
            ->line('**Запрос:** '.self::text($l->subject))
            ->line('**Имя:** '.self::text($l->name))
            ->line('**Телефон:** '.self::text($l->phone))
            ->line('**Email:** '.self::text($l->email))
            ->line('**Комментарий:** '.self::text($l->comment))
            ->action('Открыть заявки в админке', url('/admin/leads'))
            ->salutation(config('shop.name'));
    }
}
