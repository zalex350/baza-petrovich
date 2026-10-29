<?php

namespace App\Notifications;

use App\Models\PartnerRequest;
use App\Models\User;
use App\Notifications\Concerns\MarkdownText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Администратору: новая заявка «Стать партнёром» (users.notify_partner_request).
 * Через очередь, как письма о заказах (AdminOrderNotification).
 */
class PartnerRequestNotification extends Notification implements ShouldQueue
{
    use MarkdownText, Queueable;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public PartnerRequest $partnerRequest)
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
        $r = $this->partnerRequest;

        return (new MailMessage)
            ->subject('Заявка «Стать партнёром»: '.self::text($r->organization))
            ->greeting('Новая заявка «Стать партнёром»')
            ->line('**Организация:** '.self::text($r->organization))
            ->line('**УНП:** '.self::text($r->unp))
            ->line('**Контактное лицо:** '.self::text($r->contact_name))
            ->line('**Телефон:** '.self::text($r->phone))
            ->line('**Email:** '.self::text($r->email))
            ->line('**Комментарий:** '.self::text($r->comment))
            ->action('Открыть заявки в админке', url('/admin/partners'))
            ->salutation('smesitel.by');
    }
}
