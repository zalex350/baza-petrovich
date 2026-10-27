<?php

/*
 * Форматы вывода в шаблонах (docs/UI_INVENTORY.md §3): суммы — money() или x-price,
 * даты — date_ru(). Подключается через composer.json → autoload.files.
 */

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
        return $date?->format($time ? 'd.m.Y H:i' : 'd.m.Y') ?? '';
    }
}
