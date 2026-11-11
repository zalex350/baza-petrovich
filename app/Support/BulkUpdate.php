<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Обновление многих строк одним запросом: UPDATE … SET col = CASE id WHEN … THEN … ELSE col END
 * WHERE id IN (…). Для загрузок из 1С: тысячи строк — десятки запросов вместо тысяч.
 * Работает в MySQL и SQLite. Мимо моделей: событий и updated_at нет.
 */
class BulkUpdate
{
    /** Строк в одном запросе (плейсхолдеров — до ~3 на колонку на строку) */
    private const CHUNK = 500;

    /**
     * @param  array<int, array<string, mixed>>  $rows  id => [колонка => значение]
     */
    public static function run(string $table, array $rows, string $key = 'id'): void
    {
        foreach (array_chunk($rows, self::CHUNK, true) as $chunk) {
            $columns = array_unique(array_merge(...array_map('array_keys', array_values($chunk))));
            $sets = [];
            $bindings = [];

            foreach ($columns as $column) {
                $cases = [];

                foreach ($chunk as $id => $values) {
                    if (array_key_exists($column, $values)) {
                        $cases[] = 'WHEN ? THEN ?';
                        $bindings[] = $id;
                        $bindings[] = $values[$column];
                    }
                }

                $wrapped = DB::getQueryGrammar()->wrap($column);
                $sets[] = "{$wrapped} = CASE ".DB::getQueryGrammar()->wrap($key).' '.implode(' ', $cases)." ELSE {$wrapped} END";
            }

            $ids = array_keys($chunk);
            $bindings = [...$bindings, ...$ids];

            DB::update(
                'UPDATE '.DB::getQueryGrammar()->wrapTable($table).' SET '.implode(', ', $sets)
                .' WHERE '.DB::getQueryGrammar()->wrap($key).' IN ('.implode(', ', array_fill(0, count($ids), '?')).')',
                $bindings
            );
        }
    }
}
