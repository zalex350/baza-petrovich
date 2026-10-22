<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Администратору: клиент запросил отмену заказа (users.notify_cancel_request).
 */
class CancelRequestNotification extends AdminOrderNotification
{
    public function toMail(User $notifiable): MailMessage
    {
        $order = $this->order;

        return (new MailMessage)
            ->subject("Запрос на отмену заказа {$order->number}")
            ->greeting("Запрос на отмену заказа {$order->number}")
            ->line('**Организация:** '.$this->organizationLine())
            ->line('**Запросил:** '.self::userLine($order->cancelRequester))
            ->line('**Причина:** '.self::text($order->cancel_reason))
            ->line('Подтвердите отмену или отклоните запрос в карточке заказа.')
            ->action('Открыть заказ в админке', $this->orderUrl())
            ->salutation('smesitel.by');
    }
}
