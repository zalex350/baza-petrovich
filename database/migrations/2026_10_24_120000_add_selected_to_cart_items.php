<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Галочка «в заказ» у позиции корзины: оформляются только отмеченные, остальные остаются
 * в корзине. Существующие позиции — отмечены (как и было: в заказ шла вся корзина).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->boolean('selected')->default(true)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn('selected');
        });
    }
};
