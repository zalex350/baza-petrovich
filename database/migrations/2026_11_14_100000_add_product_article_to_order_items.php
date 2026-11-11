<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * order_items.product_article — снимок артикула производителя на момент оформления: его видит
 * покупатель (заказ, письмо), его же 1С получит в items.article (docs/1C_EXCHANGE_SPEC.md,
 * раздел 6). Пусто — показывается снимок кода сайта product_sku.
 * Существующие позиции — артикул товара сейчас (у позиций без товара — пусто).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('product_article')->nullable()->after('product_sku');
        });

        DB::table('order_items')->whereNull('product_article')->update([
            'product_article' => DB::raw("(SELECT NULLIF(TRIM(products.article), '') FROM products WHERE products.id = order_items.product_id)"),
        ]);
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('product_article');
        });
    }
};
