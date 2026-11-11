<?php

namespace App\Support;

/**
 * Обрезка строк до длины колонки перед записью: SQLite в тестах длину VARCHAR не проверяет,
 * MySQL отвечает «Data too long» (SQLSTATE 22001) — и запись падает целиком.
 * Длины — символы (VARCHAR utf8mb4 считает символы); для TEXT — запас в символах.
 */
class ColumnLength
{
    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, int>  $lengths  колонка → максимум символов
     * @return array<string, mixed>
     */
    public static function fit(array $values, array $lengths): array
    {
        foreach ($lengths as $column => $length) {
            if (isset($values[$column]) && is_string($values[$column]) && mb_strlen($values[$column]) > $length) {
                $values[$column] = mb_substr($values[$column], 0, $length);
            }
        }

        return $values;
    }
}
