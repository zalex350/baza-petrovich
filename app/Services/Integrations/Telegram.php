<?php

namespace App\Services\Integrations;

use App\Models\Lead;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Сообщения менеджерам в Telegram (Bot API sendMessage, HTML-разметка). Настройки — config services.telegram.
 */
class Telegram
{
    public function enabled(): bool
    {
        return filled(config('services.telegram.bot_token')) && filled(config('services.telegram.chat_id'));
    }

    public function send(string $html): void
    {
        $response = Http::timeout(15)->asJson()->post(
            'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/sendMessage',
            [
                'chat_id' => config('services.telegram.chat_id'),
                'text' => mb_substr($html, 0, 4000),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ],
        );

        if (! $response->successful() || ! $response->json('ok')) {
            throw new RuntimeException('Telegram: '.($response->json('description') ?? 'HTTP '.$response->status()));
        }
    }

    public function orderText(Order $order): string
    {
        $order->loadMissing(['items', 'organization']);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $lines = [
            '🛒 <b>Новый заказ '.$e($order->number).'</b>',
            '<b>Сумма:</b> '.$e(money($order->total_with_vat)),
            '<b>Покупатель:</b> '.$e(collect([$order->organization?->name, $order->delivery_contact_name, $order->delivery_contact_phone])->filter()->implode(', ')),
        ];

        if ($order->customer_email) {
            $lines[] = '<b>Email:</b> '.$e($order->customer_email);
        }

        $lines[] = '<b>Получение:</b> '.$e(collect([$order->deliveryLabel() ?? $order->delivery_title, $order->delivery_address])->filter()->implode(' — '));

        if ($order->paymentLabel()) {
            $lines[] = '<b>Оплата:</b> '.$e($order->paymentLabel());
        }

        if ($order->comment) {
            $lines[] = '<b>Комментарий:</b> '.$e($order->comment);
        }

        $lines[] = '';

        foreach ($order->items as $item) {
            $lines[] = '• '.$e($item->product_name).' — '.$e(rtrim(rtrim(number_format((float) $item->quantity, 3, ',', ''), '0'), ',')).' '.$e(unit_label($item->unit)).' × '.$e(money($item->price, false)).' = '.$e(money($item->amount_with_vat));
        }

        $lines[] = '';
        $lines[] = $e(url('/admin/order/'.$order->id));

        return implode("\n", $lines);
    }

    public function leadText(Lead $lead): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return implode("\n", array_filter([
            '📩 <b>Заявка «'.$e($lead->typeLabel()).'»</b>',
            $lead->subject ? '<b>Запрос:</b> '.$e($lead->subject) : null,
            '<b>Имя:</b> '.$e($lead->name),
            '<b>Телефон:</b> '.$e($lead->phone),
            $lead->email ? '<b>Email:</b> '.$e($lead->email) : null,
            $lead->comment ? '<b>Комментарий:</b> '.$e($lead->comment) : null,
            $e(url('/admin/leads')),
        ]));
    }
}
