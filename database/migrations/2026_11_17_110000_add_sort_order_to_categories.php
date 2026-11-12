<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Порядок категорий среди соседей (админка «Категории»: вверх / вниз). По умолчанию 0 —
 * при равном порядке, как прежде, по названию. Только добавляет колонку — данные не меняет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('parent_id');
            $table->index(['parent_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['parent_id', 'sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
