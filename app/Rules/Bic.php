<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * BIC (SWIFT) банка: 8 или 11 символов — 4 буквы банка, 2 буквы страны, 2 символа города,
 * необязательно 3 символа филиала. Хранится заглавными (normalize).
 */
class Bic implements ValidationRule
{
    public static function normalize(?string $value): string
    {
        return strtoupper(preg_replace('/\s+/u', '', (string) $value));
    }

    public static function valid(string $value): bool
    {
        return (bool) preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', self::normalize($value));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::valid((string) $value)) {
            $fail('BIC — 8 или 11 латинских букв и цифр, например ALFABY2X.');
        }
    }
}
