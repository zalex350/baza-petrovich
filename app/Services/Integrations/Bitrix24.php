<?php

namespace App\Services\Integrations;

use App\Models\Lead;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Vat\VatCalculator;
use App\Support\Attribution;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Битрикс24 через входящий вебхук (REST): заказ и заявка → лид CRM (crm.lead.add), позиции заказа —
 * товарные строки лида (crm.lead.productrows.set). Настройки — config services.bitrix24.
 */
class Bitrix24
{
    public function enabled(): bool
    {
        return filled(config('services.bitrix24.webhook_url'));
    }

    /** @return mixed поле result ответа */
    public function call(string $method, array $params): mixed
    {
        $url = rtrim((string) config('services.bitrix24.webhook_url'), '/').'/'.$method.'.json';
        $response = Http::timeout(20)->asJson()->post($url, $params);

        if (! $response->successful() || $response->json('error')) {
            throw new RuntimeException("Битрикс24 {$method}: ".($response->json('error_description') ?? $response->json('error') ?? 'HTTP '.$response->status()));
        }

        return $response->json('result');
    }

    /** Лид по заказу с товарами; возвращает ID лида */
    public function pushOrder(Order $order): string
    {
        $order->loadMissing(['items', 'organization']);

        $comments = collect([
            'Заказ на сайте: '.$order->number,
            $order->organization ? 'Организация: '.$order->organization->name.' (УНП '.$order->organization->unp.')' : null,
            'Получение: '.collect([$order->deliveryLabel() ?? $order->delivery_title, $order->delivery_address])->filter()->implode(' — '),
            $order->paymentLabel() ? 'Оплата: '.$order->paymentLabel() : null,
            $order->comment ? 'Комментарий: '.$order->comment : null,
            ($source = Attribution::label($order->attribution)) ? 'Источник: '.$source : null,
            url('/admin/order/'.$order->id),
        ])->filter()->map(fn ($l) => e($l))->implode('<br>');

        $id = (string) $this->call('crm.lead.add', ['fields' => $this->contactFields(
            title: 'Заказ '.$order->number.' с сайта',
            name: $order->delivery_contact_name ?? $order->organization?->name,
            phone: $order->delivery_contact_phone,
            email: $order->customer_email,
        ) + [
            'OPPORTUNITY' => (float) $order->total_with_vat,
            'CURRENCY_ID' => 'BYN',
            'ADDRESS' => $order->delivery_address,
            'COMPANY_TITLE' => $order->organization?->name,
            'COMMENTS' => $comments,
        ] + $this->utmFields($order->attribution), 'params' => ['REGISTER_SONET_EVENT' => 'Y']]);

        $this->call('crm.lead.productrows.set', [
            'id' => $id,
            'rows' => $order->items->map(fn (OrderItem $item) => [
                'PRODUCT_NAME' => $item->product_name,
                'PRICE' => (float) (VatCalculator::pricesIncludeVat() ? $item->price : ($item->quantity ? $item->amount_with_vat / $item->quantity : 0)),
                'QUANTITY' => (float) $item->quantity,
                'MEASURE_NAME' => unit_label($item->unit),
                'TAX_RATE' => $item->vat_rate === null ? null : (float) $item->vat_rate,
                'TAX_INCLUDED' => 'Y',
            ])->values()->all(),
        ]);

        return $id;
    }

    /** Лид по заявке с сайта; возвращает ID лида */
    public function pushLead(Lead $lead): string
    {
        return (string) $this->call('crm.lead.add', ['fields' => $this->contactFields(
            title: $lead->typeLabel().($lead->subject ? ': '.$lead->subject : '').' (сайт)',
            name: $lead->name,
            phone: $lead->phone,
            email: $lead->email,
        ) + [
            'COMMENTS' => e((string) $lead->comment)
                .(($source = Attribution::label($lead->attribution)) ? '<br>'.e('Источник: '.$source) : ''),
        ] + $this->utmFields($lead->attribution), 'params' => ['REGISTER_SONET_EVENT' => 'Y']]);
    }

    /** Метки рекламы — в стандартные поля лида UTM_* (отчёты Битрикс24 по источникам) */
    private function utmFields(?array $attribution): array
    {
        return collect(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'])
            ->mapWithKeys(fn ($key) => [strtoupper($key) => $attribution[$key] ?? null])
            ->filter()
            ->all();
    }

    private function contactFields(string $title, ?string $name, ?string $phone, ?string $email): array
    {
        return array_filter([
            'TITLE' => mb_substr($title, 0, 255),
            'NAME' => $name,
            'PHONE' => $phone ? [['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']] : null,
            'EMAIL' => $email ? [['VALUE' => $email, 'VALUE_TYPE' => 'WORK']] : null,
            'SOURCE_ID' => config('services.bitrix24.source_id', 'WEB'),
            'SOURCE_DESCRIPTION' => config('app.url'),
            'ASSIGNED_BY_ID' => config('services.bitrix24.assigned_by_id'),
        ], fn ($v) => $v !== null && $v !== '');
    }
}
