<?php

namespace App\Notifications;

use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Администратору: оформлен новый заказ (users.notify_new_order).
 */
class NewOrderNotification extends AdminOrderNotification
{
    public function toMail(User $notifiable): MailMessage
    {
        $order = $this->order;
        $totals = $order->vatTotals();

        $address = collect([
            $order->delivery_title,
            $order->delivery_address,
            collect([$order->delivery_contact_name, $order->delivery_contact_phone])->filter()->implode(', '),
        ])->filter()->implode(' — ');

        $mail = (new MailMessage)
            ->subject("Новый заказ {$order->number}")
            ->greeting("Новый заказ {$order->number}")
            ->line('**Организация:** '.$this->organizationLine())
            ->line('**Оформил:** '.self::userLine($order->user))
            ->line('**Сумма без НДС:** '.self::money($totals->net))
            ->line('**НДС:** '.self::money($totals->vat))
            ->line('**Сумма с НДС:** '.self::money($totals->gross))
            ->line('**Адрес доставки:** '.self::text($address))
            ->line('**Комментарий:** '.self::text($order->comment))
            ->line('**Позиции:**');

        foreach ($order->items as $item) {
            $mail->line(self::itemLine($item));
        }

        return $mail
            ->action('Открыть заказ в админке', $this->orderUrl())
            ->salutation('smesitel.by');
    }

    /**
     * Позиция: «GR-001 (код клиента: A-1) — Смеситель × 2 шт.»; код — снимок при оформлении.
     */
    private static function itemLine(OrderItem $item): string
    {
        $sku = self::text($item->product_sku);

        if ($item->client_code) {
            $sku .= ' (код клиента: **'.self::text($item->client_code).'**)';
        }

        return $sku.' — '.self::text($item->product_name).' × '.$item->quantity.' шт.';
    }
}
