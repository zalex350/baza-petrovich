<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Закреплённая категория (App\Services\Catalog\CategoryOrder): при порядке «по количеству товаров»
 * идёт первой среди соседей в своём порядке (sort_order). Ставится кнопками ↑ / ↓ в админке, снимается
 * «Вернуть автопорядок». Только добавляет колонку: сейчас ни одна категория не закреплена.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_pinned');
        });
    }
};
