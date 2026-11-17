<?php

/*
 * Форматы вывода в шаблонах (docs/UI_INVENTORY.md §3): суммы — money() или x-price,
 * даты — date_ru(). Подключается через composer.json → autoload.files.
 */

use App\Models\Product;
use App\Services\ClientCodes;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonInterface;

if (! function_exists('money')) {
    /**
     * Сумма в едином формате: 1 234,50 BYN ($currency = false — без валюты).
     * Пустая сумма — $empty: на витрине «Цена уточняется» (как x-price), в админке — money($x, empty: '—').
     */
    function money(float|int|string|null $amount, bool $currency = true, string $empty = 'Цена уточняется'): string
    {
        return Money::format($amount, $currency) ?? $empty;
    }
}

if (! function_exists('date_ru')) {
    /**
     * Дата по-русски: 05.03.2026 — в списках, 05.03.2026 14:07 ($time) — в карточках и истории.
     * Пустая дата — пустая строка.
     */
    function date_ru(?CarbonInterface $date, bool $time = false): string
    {
        // Время в БД — UTC, людям — по Минску (LocalTime)
        return LocalTime::of($date)?->format($time ? 'd.m.Y H:i' : 'd.m.Y') ?? '';
    }
}

if (! function_exists('client_code')) {
    /**
     * «Ваш код» товара — код организации текущего пользователя (App\Services\ClientCodes)
     * или null: гость, пользователь без организации, у товара нет кода. В списках коды
     * загружает контроллер (ClientCodes::load) — без запроса на каждый товар.
     */
    function client_code(?Product $product): ?string
    {
        return app(ClientCodes::class)->codeFor($product);
    }
}

if (! function_exists('prices_visible')) {
    /**
     * Показывать ли цены и наличие текущему посетителю: розница (shop.retail) — всем,
     * B2B — только после входа.
     */
    function prices_visible(): bool
    {
        return (bool) config('shop.retail') || auth()->check();
    }
}

if (! function_exists('unit_label')) {
    /** Единица товара для подписи цены «за м.п.»; пусто — «шт.» */
    function unit_label(?string $unit): string
    {
        $unit = trim((string) $unit);

        // Сокращения без точки из справочников (1С пишет «шт», «компл») — с точкой
        return match (mb_strtolower($unit)) {
            '', 'шт', 'шт.' => 'шт.',
            'компл' => 'компл.',
            default => $unit,
        };
    }
}

if (! function_exists('stock_tracked')) {
    /**
     * Ведётся ли учёт остатков (shop.track_stock). Нет — товар только «в наличии» (stock > 0) или «нет»,
     * количество в заказе остатком не ограничивается, витрина не показывает «Мало» / «N шт.».
     */
    function stock_tracked(): bool
    {
        return (bool) config('shop.track_stock', true);
    }
}

if (! function_exists('qty_max')) {
    /** Предел поля количества: остаток при учёте остатков, иначе — без предела (null) */
    function qty_max(?Product $product): ?int
    {
        return $product && stock_tracked() ? (int) $product->stock : null;
    }
}
