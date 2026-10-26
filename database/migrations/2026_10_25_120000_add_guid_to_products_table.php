<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GUID номенклатуры 1С — будущий ключ обмена (docs/CATALOG_ANALYSIS.md, «Решения», п. 1).
 * Пока не заполняется: ключ сопоставления — products.sku = catalog.sku.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('guid')->nullable()->unique()->after('sku');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['guid']);
            $table->dropColumn('guid');
        });
    }
};
