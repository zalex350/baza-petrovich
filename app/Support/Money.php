<?php

namespace App\Support;

/**
 * Единый формат денежных сумм (docs/UI_INVENTORY.md §3 «Цена»): 1 234.50 BYN.
 * Используется компонентом x-price; экраны переводятся на него постепенно.
 */
final class Money
{
    public const CURRENCY = 'BYN';

    public static function format(float|int|string|null $amount, bool $currency = true): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $formatted = number_format((float) $amount, 2, '.', ' ');

        return $currency ? $formatted.' '.self::CURRENCY : $formatted;
    }
}
