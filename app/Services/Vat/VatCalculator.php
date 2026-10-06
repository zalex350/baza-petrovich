<?php

namespace App\Services\Vat;

/**
 * Расчёт НДС (B2B, как в счёт-фактуре РБ). Единственное место, где считается НДС.
 *
 * Цена товара хранится без НДС. По позиции: сумма без НДС = цена × количество,
 * НДС = сумма без НДС × ставка, округлённый до копеек, сумма с НДС = их сумма.
 * Итог документа — сумма позиций, а не НДС от итоговой суммы.
 *
 * Ставка null — «Без НДС»: НДС = 0. Это не то же, что ставка 0%.
 * Расчёт ведётся в копейках (целые числа), чтобы не накапливать погрешность float.
 */
class VatCalculator
{
    /** Значение «Без НДС» в формах — в БД хранится как null */
    public const NO_VAT = 'none';

    /** Ставки для выбора в админке */
    public const RATES = [20, 10, 0];

    public function line($price, int $quantity, $rate): VatLine
    {
        $rate = self::normalizeRate($rate);

        $net = self::toKopecks($price) * $quantity;
        $vat = $rate === null ? 0 : (int) round($net * $rate / 100);

        return new VatLine(
            price: self::toKopecks($price) / 100,
            quantity: $quantity,
            rate: $rate,
            net: $net / 100,
            vat: $vat / 100,
            gross: ($net + $vat) / 100,
        );
    }

    /**
     * @param  iterable<VatLine>  $lines
     */
    public function total(iterable $lines): VatTotal
    {
        $net = $vat = 0;

        foreach ($lines as $line) {
            $net += self::toKopecks($line->net);
            $vat += self::toKopecks($line->vat);
        }

        return new VatTotal($net / 100, $vat / 100, ($net + $vat) / 100);
    }

    /**
     * «20%», «10,5%» или «Без НДС».
     */
    public static function rateLabel($rate): string
    {
        $rate = self::normalizeRate($rate);

        if ($rate === null) {
            return 'Без НДС';
        }

        return rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',') . '%';
    }

    /**
     * Ставка из БД или формы: null / '' / 'none' — «Без НДС», иначе число.
     */
    public static function normalizeRate($rate): ?float
    {
        if ($rate === null || $rate === '' || $rate === self::NO_VAT) {
            return null;
        }

        return (float) $rate;
    }

    /**
     * Правило проверки ставки в формах: «Без НДС» или число от 0 до 100.
     */
    public static function rateRule(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            if ($value === self::NO_VAT) {
                return;
            }

            if (! is_numeric($value) || $value < 0 || $value > 100) {
                $fail('Ставка НДС — число от 0 до 100 или «Без НДС»');
            }
        };
    }

    private static function toKopecks($amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
