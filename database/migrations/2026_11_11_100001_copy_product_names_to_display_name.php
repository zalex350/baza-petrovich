<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Название сайта — в display_name, name освобождается под наименование 1С
 * (docs/1C_EXCHANGE_SPEC.md, раздел 4, правило 4): обмен номенклатуры перепишет name,
 * а покупатель продолжит видеть прежнее название.
 *
 * Копируется только настоящее название (Product::hasRealName): если в name записан sku,
 * Product::title() собирает «БРЕНД артикул» — display_name = sku это сломал бы.
 * Заданный в админке display_name не трогается. Повторный запуск ничего не меняет.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = [];

        DB::table('products')
            ->where(fn ($q) => $q->whereNull('display_name')->orWhere('display_name', ''))
            ->select(['id', 'name', 'sku'])
            ->eachById(function ($row) use (&$ids) {
                // Правило Product::hasRealName на момент миграции; без учёта регистра — в PHP:
                // UPPER в SQLite не знает кириллицы
                $name = trim((string) $row->name);

                if ($name !== '' && mb_strtoupper($name) !== mb_strtoupper(trim((string) $row->sku))) {
                    $ids[] = $row->id;
                }
            });

        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('products')->whereIn('id', $chunk)->update(['display_name' => DB::raw('name')]);
        }
    }

    /**
     * С потерей: display_name, совпадающий с name, снимается, в том числе заданный
     * в админке до миграции (отличить их уже нельзя).
     */
    public function down(): void
    {
        DB::table('products')->whereColumn('display_name', 'name')->update(['display_name' => null]);
    }
};
