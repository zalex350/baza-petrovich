<?php

use App\Services\CatalogMenu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Аксессуары для ванной / Аксессуары» — кухонные товары (дозаторы, коландеры, ринзеры):
 * все переходят в «Мойки и умывальники / Аксессуары для моек», как их теперь раскладывает
 * catalog:import-db (map: «аксессуары»). Опустевшая категория удаляется.
 *
 * Идемпотентна: нет категории-источника или цели — ничего не делает. Отката нет (бэкап перед миграцией).
 */
return new class extends Migration
{
    public function up(): void
    {
        $from = $this->categoryId('Аксессуары для ванной/Аксессуары');
        $to = $this->categoryId('Мойки и умывальники/Аксессуары для моек');

        if (! $from || ! $to) {
            return;
        }

        // Удалённые товары тоже: иначе категория не опустеет
        DB::table('products')->where('category_id', $from)->update(['category_id' => $to]);

        if (! DB::table('categories')->where('parent_id', $from)->exists()) {
            DB::table('categories')->where('id', $from)->delete();
        }

        CatalogMenu::flush();
    }

    public function down(): void
    {
        //
    }

    private function categoryId(string $path): ?int
    {
        $id = null;

        foreach (explode('/', $path) as $name) {
            $id = DB::table('categories')->where('parent_id', $id)->where('name', $name)->value('id');

            if (! $id) {
                return null;
            }
        }

        return $id;
    }
};
