<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Физическое удаление товара больше не стирает строки оформленных заказов:
 * product_id обнуляется, а название, артикул, цена и НДС остаются в снимке позиции.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->change();

            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });
    }

    /**
     * Откат невозможен, если уже есть позиции без товара: вернуть их в NOT NULL
     * можно только удалив строки заказов, а это потеря документа. В таком случае
     * down() останавливается с ошибкой, ничего не меняя.
     */
    public function down(): void
    {
        $orphans = DB::table('order_items')->whereNull('product_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Откат невозможен: {$orphans} позиций заказов без товара (product_id = null)."
            );
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable(false)->change();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }
};
