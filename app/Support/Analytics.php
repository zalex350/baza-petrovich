<?php

namespace App\Support;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;

/**
 * Аналитика витрины: какие счётчики подключены (админка «Настройки» → Аналитика) и данные событий
 * электронной торговли в формате GA4 (items: item_id, item_name, item_brand, item_category, price, quantity).
 * События отправляет resources/js/analytics.js (window.track) — в dataLayer (GTM), gtag (GA4, Ads),
 * Яндекс Метрику и Clarity. Серверные события страницы — компонент x-track.
 */
class Analytics
{
    public const CURRENCY = 'BYN';

    /** Cookie с выбором посетителя: granted | denied (ставит баннер, JS — не шифруется, bootstrap/app.php) */
    public const CONSENT_COOKIE = 'cookie_consent';

    /**
     * Настройки для JS; null — счётчики не подключаются (выключено, ни одного ID, админка, визит администратора).
     *
     * @return array<string, mixed>|null
     */
    public static function config(): ?array
    {
        if (! config('seo.analytics') || auth()->user()?->isAdmin()) {
            return null;
        }

        $ids = [
            'gtm' => Setting::value('analytics_gtm_id'),
            'ga4' => Setting::value('analytics_ga4_id'),
            'ads' => Setting::value('analytics_ads_id'),
            'ym' => Setting::value('analytics_ym_id'),
            'clarity' => Setting::value('analytics_clarity_id'),
        ];

        if (! array_filter($ids)) {
            return null;
        }

        return $ids + [
            'adsLabels' => array_filter([
                'purchase' => Setting::value('analytics_ads_purchase_label'),
                'generate_lead' => Setting::value('analytics_ads_lead_label'),
            ]),
            'consent' => self::consentMode(),
            'currency' => self::CURRENCY,
        ];
    }

    public static function consentMode(): string
    {
        // По умолчанию — согласие: Закон РБ № 99-З требует его для cookie аналитики и рекламы
        // (данные уходят Google, Microsoft — в т.ч. в страны без надлежащей защиты)
        $mode = Setting::value('analytics_consent') ?: 'required';

        return array_key_exists($mode, Setting::CONSENT_MODES) ? $mode : 'required';
    }

    /**
     * Товар для событий GA4. Цена — та, что видит покупатель; $index — позиция в списке.
     *
     * @return array<string, mixed>
     */
    public static function item(Product $product, int|float $quantity = 1, ?int $index = null, ?float $price = null): array
    {
        // Только уже загруженные связи — списки не делают запросов на каждый товар
        $category = $product->relationLoaded('category') ? $product->category : null;
        $parent = $category?->relationLoaded('parent') ? $category->parent : null;
        $price ??= prices_visible() && $product->price !== null ? (float) $product->price : null;

        return array_filter([
            'item_id' => $product->article ?: $product->sku ?: (string) $product->id,
            'item_name' => $product->title(),
            'item_brand' => $product->brand,
            'item_category' => $parent?->name ?? $category?->name,
            'item_category2' => $parent ? $category?->name : null,
            'price' => $price !== null ? round($price, 2) : null,
            'quantity' => $quantity,
            'index' => $index,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Корзина (позиции с товаром; отмеченные к заказу, если есть отметки) → {value, items}.
     *
     * @param  iterable<CartItem>  $cartItems
     */
    public static function cart(iterable $cartItems): array
    {
        $rows = collect($cartItems)->filter(fn ($row) => $row->product);
        $selected = $rows->where('selected', true);
        $rows = $selected->isNotEmpty() ? $selected : $rows;

        $items = $rows->values()->map(fn ($row, $i) => self::item($row->product, (float) $row->quantity, $i))->all();

        return ['value' => self::value($items), 'items' => $items];
    }

    /** Покупка (purchase): номер заказа, итог с НДС, позиции по ценам заказа */
    public static function purchase(Order $order): array
    {
        $order->loadMissing('items');

        $items = $order->items->values()->map(fn ($row, $i) => array_filter([
            'item_id' => $row->product_article ?: $row->product_sku ?: (string) $row->product_id,
            'item_name' => $row->product_name,
            'price' => round((float) $row->quantity > 0 ? (float) $row->amount_with_vat / (float) $row->quantity : (float) $row->price, 2),
            'quantity' => (float) $row->quantity,
            'index' => $i,
        ], fn ($value) => $value !== null && $value !== ''))->all();

        return array_filter([
            'transaction_id' => $order->number,
            'value' => round((float) $order->total_with_vat, 2),
            'tax' => round((float) $order->total_vat, 2),
            'shipping' => 0,
            'currency' => self::CURRENCY,
            'payment_type' => $order->payment_method,
            'shipping_tier' => $order->delivery_method,
            'items' => $items,
        ], fn ($value) => $value !== null);
    }

    /** Сумма позиций для value */
    public static function value(array $items): float
    {
        return round(array_sum(array_map(fn ($item) => ($item['price'] ?? 0) * ($item['quantity'] ?? 1), $items)), 2);
    }
}
