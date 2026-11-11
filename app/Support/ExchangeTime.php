<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Время в API обмена с 1С (docs/1C_EXCHANGE_SPEC.md, раздел 2): в ответах — ISO 8601
 * в поясе shop.exchange_1c.timezone (Europe/Minsk, +03:00); на входе — ISO 8601 с любым
 * смещением (+03:00, Z, +00:00…); без смещения — время пояса обмена. В БД — как всё
 * приложение (app.timezone, UTC).
 */
class ExchangeTime
{
    public static function zone(): string
    {
        return (string) config('shop.exchange_1c.timezone', 'Europe/Minsk');
    }

    public static function format(?DateTimeInterface $time): ?string
    {
        return $time ? Carbon::instance($time)->setTimezone(self::zone())->toIso8601String() : null;
    }

    /**
     * Время из файла или запроса 1С → Carbon в поясе приложения; не ISO 8601 — null
     * (строка уходит в ошибки задания).
     */
    public static function parse(?string $value): ?Carbon
    {
        $value = trim((string) $value);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/i', $value)) {
            return null;
        }

        try {
            return Carbon::parse($value, self::zone())->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
