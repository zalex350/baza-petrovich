<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Поля номенклатуры 1С (docs/1C_EXCHANGE_SPEC.md, раздел 4).
 * article — «чистый» артикул без бренда (catalog:fill-articles), match_key — ключ
 * первичного сопоставления «бренд + артикул» (Product::matchKey), *_synced_at — время
 * последнего применённого обмена: номенклатуры, цены, остатка.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('article')->nullable()->after('sku');
            $table->string('match_key')->nullable()->index()->after('article');
            $table->string('unit', 20)->default('шт')->after('vat_rate');
            $table->string('barcode', 64)->nullable()->after('unit');
            $table->string('group_1c')->nullable()->after('barcode');
            $table->timestamp('synced_at')->nullable()->after('updated_at');
            $table->timestamp('price_synced_at')->nullable()->after('synced_at');
            $table->timestamp('stock_synced_at')->nullable()->after('price_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['match_key']);
            $table->dropColumn(['article', 'match_key', 'unit', 'barcode', 'group_1c', 'synced_at', 'price_synced_at', 'stock_synced_at']);
        });
    }
};
