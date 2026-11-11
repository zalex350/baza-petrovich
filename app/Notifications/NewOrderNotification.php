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
     * Позиция: «Арт. L4755-2 (SKU LEDEME-L4755-2, код клиента: A-1) — Смеситель × 2 шт.»;
     * артикул и код — снимки при оформлении. Письмо — администраторам, поэтому SKU тоже.
     */
    private static function itemLine(OrderItem $item): string
    {
        $sku = 'Арт. '.self::text($item->displayArticle());
        $extra = [];

        if ($item->product_article && $item->product_sku) {
            $extra[] = 'SKU '.self::text($item->product_sku);
        }

        if ($item->client_code) {
            $extra[] = 'код клиента: **'.self::text($item->client_code).'**';
        }

        if ($extra) {
            $sku .= ' ('.implode(', ', $extra).')';
        }

        return $sku.' — '.self::text($item->product_name).' × '.$item->quantity.' шт.';
    }
}
