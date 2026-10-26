<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Флаги под импорт каталога (docs/CATALOG_ANALYSIS.md, «Решения»):
 * - categories.is_hidden — служебная категория («Без категории»): нет в меню и каталоге, товары — в поиске;
 * - attributes.is_logistics — «Упаковка и логистика» (свёрнутый блок в карточке);
 * - attributes.is_admin_only — только в админке (ТН ВЭД);
 * - products.description_generated — описание сгенерировано: на сайте не показывается до одобрения.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('parent_id');
        });

        Schema::table('attributes', function (Blueprint $table) {
            $table->boolean('is_logistics')->default(false)->after('is_filterable');
            $table->boolean('is_admin_only')->default(false)->after('is_logistics');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('description_generated')->default(false)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('is_hidden'));
        Schema::table('attributes', fn (Blueprint $table) => $table->dropColumn(['is_logistics', 'is_admin_only']));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('description_generated'));
    }
};
