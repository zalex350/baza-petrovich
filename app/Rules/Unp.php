<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * УНП организации Республики Беларусь: 9 цифр, последняя — контрольная.
 * Контрольная цифра — сумма первых восьми цифр с весами 29, 23, 19, 17, 13, 7, 5, 3 по модулю 11;
 * остаток 10 не бывает у действительного УНП.
 */
class Unp implements ValidationRule
{
    private const WEIGHTS = [29, 23, 19, 17, 13, 7, 5, 3];

    public static function valid(string $value): bool
    {
        return preg_match('/^\d{9}$/', $value) && self::checkDigit(substr($value, 0, 8)) === (int) $value[8];
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
        $value = (string) $value;

        if (! preg_match('/^\d{9}$/', $value)) {
            $fail('УНП — ровно 9 цифр.');
        } elseif (! self::valid($value)) {
            $fail('УНП с ошибкой: не сходится контрольная цифра.');
        }
    }
}
