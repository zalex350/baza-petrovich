<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * УНП Республики Беларусь. Проверка (блокирующая) — только формат: ровно 9 цифр, пробелы при вводе
 * убирает normalize(). Контрольная цифра — справочно (warning()): жёлтая подсказка под полем и
 * пометка в карточке, сохранение разрешено.
 * Контрольная цифра — сумма первых восьми цифр с весами 29, 23, 19, 17, 13, 7, 5, 3 по модулю 11;
 * остаток 10 не бывает у действительного УНП.
 */
class Unp implements ValidationRule
{
    public const WARNING = 'Контрольная цифра не сходится — проверьте УНП';

    private const WEIGHTS = [29, 23, 19, 17, 13, 7, 5, 3];

    /** Без пробелов (в том числе неразрывных) */
    public static function normalize(?string $value): string
    {
        return (string) preg_replace('/[\s\p{Z}]+/u', '', (string) $value);
    }

    /** 9 цифр и контрольная цифра сходится */
    public static function valid(string $value): bool
    {
        return preg_match('/^\d{9}$/', $value) && self::checkDigit(substr($value, 0, 8)) === (int) $value[8];
    }

    /** Справочная подсказка: 9 цифр, но контрольная цифра не сходится; иначе null */
    public static function warning(?string $value): ?string
    {
        $value = self::normalize($value);

        return preg_match('/^\d{9}$/', $value) && ! self::valid($value) ? self::WARNING : null;
    }

    /** Контрольная цифра к первым восьми цифрам; null — у такого начала действительного УНП нет */
    public static function checkDigit(string $first8): ?int
    {
        $sum = 0;
        foreach (self::WEIGHTS as $i => $weight) {
            $sum += (int) $first8[$i] * $weight;
        }

        $check = $sum % 11;

        return $check < 10 ? $check : null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! preg_match('/^\d{9}$/', (string) $value)) {
            $fail('УНП — ровно 9 цифр.');
        }
    }
}
