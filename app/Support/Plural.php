<?php

namespace App\Support;

/**
 * Русские формы слова после числа: 1 товар, 2 товара, 5 товаров, 11 товаров, 21 товар.
 */
final class Plural
{
    /**
     * @param  array{0: string, 1: string, 2: string}  $forms  [1, 2–4, 5–20]
     */
    public static function ru(int $n, array $forms): string
    {
        $n = abs($n);
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        $form = match (true) {
            $mod10 === 1 && $mod100 !== 11 => $forms[0],
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => $forms[1],
            default => $forms[2],
        };

        return "{$n} {$form}";
    }
}
