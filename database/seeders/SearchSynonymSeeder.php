<?php

namespace Database\Seeders;

use App\Models\SearchSynonym;
use Illuminate\Database\Seeder;

/**
 * Стартовые синонимы поиска для сантехники. Запускается миграцией один раз и только в пустую
 * таблицу: дальше синонимы правят в админке («Синонимы поиска»), повторный запуск ничего не меняет.
 */
class SearchSynonymSeeder extends Seeder
{
    /** Группы «a, b» — в обе стороны; «a → b» — в одну */
    public const LINES = [
        'смеситель, кран',
        'кухня, кухонный',
        'ванна, ванная',
        'мойка → раковина',
        'раковина, умывальник',
        'унитаз, туалет',
        'водонагреватель, бойлер',
        'полотенцесушитель, сушилка для полотенец',
        'шланг → подводка',
        'вентиль, шаровой кран',
        'душевая стойка, душевая система',
        'гигиенический душ, биде',
        'трап, лоток',
        'термостат, термостатический',
        'хром, хромированный',
    ];

    public function run(): void
    {
        if (SearchSynonym::query()->exists()) {
            return;
        }

        foreach (self::LINES as $line) {
            SearchSynonym::create(SearchSynonym::parseLine($line));
        }
    }
}
