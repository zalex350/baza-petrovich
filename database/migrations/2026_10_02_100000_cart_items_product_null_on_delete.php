<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Физическое удаление товара больше не удаляет позицию корзины молча:
 * product_id обнуляется, позиция остаётся и показывается как «Товар больше
 * недоступен». product_name — чтобы и без товара было понятно, что именно пропало.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->string('product_name')->nullable()->after('product_id');

            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });

        foreach (DB::table('cart_items')->whereNull('product_name')->get(['id', 'product_id']) as $item) {
            $product = DB::table('products')->where('id', $item->product_id)->first(['name', 'display_name']);

            if ($product) {
                DB::table('cart_items')->where('id', $item->id)->update([
                    'product_name' => $product->display_name ?: $product->name,
                ]);
            }
        }
    }

    /**
     * Обратимо с потерей: позиции, у которых товар уже удалён физически
     * (product_id = null), в NOT NULL-колонку вернуть нельзя — они удаляются.
     */
    public function down(): void
    {
        DB::table('cart_items')->whereNull('product_id')->delete();

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn('product_name');
            $table->unsignedBigInteger('product_id')->nullable(false)->change();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }
};
