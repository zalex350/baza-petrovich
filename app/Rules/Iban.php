<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Белорусский IBAN (расчётный счёт): BY, 2 контрольные цифры, 4 символа кода банка,
 * 4 цифры балансового счёта, 16 символов счёта — 28 символов; проверка mod 97 (ISO 13616).
 * Пробелы допускаются при вводе, хранится без них (normalize).
 */
class Iban implements ValidationRule
{
    public static function normalize(?string $value): string
    {
        return strtoupper(preg_replace('/\s+/u', '', (string) $value));
    }

    public static function valid(string $value): bool
    {
        $iban = self::normalize($value);

        if (! preg_match('/^BY\d{2}[A-Z0-9]{4}\d{4}[A-Z0-9]{16}$/', $iban)) {
            return false;
        }

        // Первые 4 символа — в конец, буквы — числами (A = 10 … Z = 35), остаток от деления на 97 — 1
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $remainder = 0;

        foreach (str_split($rearranged) as $char) {
            $digits = ctype_digit($char) ? $char : (string) (ord($char) - 55);
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $iban = self::normalize($value);

        if (! preg_match('/^BY\d{2}[A-Z0-9]{4}\d{4}[A-Z0-9]{16}$/', $iban)) {
            $fail('IBAN — 28 символов: BY, 2 цифры, код банка (4 символа), 4 цифры, 16 символов счёта.');
        } elseif (! self::valid($iban)) {
            $fail('IBAN с ошибкой: не сходятся контрольные цифры.');
        }
    }
}
