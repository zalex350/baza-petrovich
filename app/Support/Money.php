<?php

namespace App\Support;

/**
 * Единый формат денежных сумм (docs/UI_INVENTORY.md §3 «Цена»): 1 234,50 BYN —
 * запятая перед копейками, неразрывные пробелы между тысячами и перед валютой
 * (сумма не переносится по строкам). Используется компонентом x-price.
 */
final class Money
{
    public const CURRENCY = 'BYN';

    /** Неразрывный пробел U+00A0 */
    public const NBSP = "\u{00A0}";

    public static function format(float|int|string|null $amount, bool $currency = true): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $formatted = number_format((float) $amount, 2, ',', self::NBSP);

        return $currency ? $formatted.self::NBSP.self::CURRENCY : $formatted;
    }
}
