<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Время для людей — в поясе app.display_timezone (Europe/Minsk, APP_DISPLAY_TIMEZONE).
 * В БД и в коде время — в app.timezone (UTC): MySQL TIMESTAMP пишется как есть, сервер
 * БД — UTC. Переводится только показ: date_ru(), письма, имена файлов и отчётов, номер
 * заказа (год), границы дней в фильтрах. Обмен с 1С — отдельно, ExchangeTime (явные пояса).
 */
class LocalTime
{
    public static function zone(): string
    {
        // Без приложения (unit-тесты date_ru) — пояс по умолчанию
        return app()->bound('config') ? (string) config('app.display_timezone', 'Europe/Minsk') : 'Europe/Minsk';
    }

    /** Копия в поясе показа; исходный объект не меняется */
    public static function of(?CarbonInterface $time): ?Carbon
    {
        return $time ? Carbon::instance($time)->setTimezone(self::zone()) : null;
    }

    public static function now(): Carbon
    {
        return Carbon::now(self::zone());
    }

    /**
     * Начало и конец дня $date (ГГГГ-ММ-ДД, день по Минску) — в поясе приложения,
     * для сравнения с колонками БД: «с 10.11» — с 10.11 00:00 по Минску (09.11 21:00 UTC).
     *
     * @return array{Carbon, Carbon}
     */
    public static function day(string $date): array
    {
        $day = Carbon::parse($date, self::zone());

        return [
            $day->copy()->startOfDay()->setTimezone(config('app.timezone')),
            $day->copy()->endOfDay()->setTimezone(config('app.timezone')),
        ];
    }
}
