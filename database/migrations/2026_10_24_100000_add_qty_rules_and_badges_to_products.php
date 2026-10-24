<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кратность заказа (поля 1С) и бейджи ХИТ / НОВИНКА (поля сайта).
 * min_qty — минимум в заказе, qty_step — шаг (кратность), default_qty — количество
 * в поле x-qty по умолчанию. Существующие товары: 1 / 1 / 1, без бейджей — как сейчас.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('min_qty')->default(1)->after('on_order');
            $table->unsignedInteger('qty_step')->default(1)->after('min_qty');
            $table->unsignedInteger('default_qty')->default(1)->after('qty_step');
            $table->boolean('is_hit')->default(false)->after('is_published');
            $table->boolean('is_new')->default(false)->after('is_hit');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['min_qty', 'qty_step', 'default_qty', 'is_hit', 'is_new']);
        });
    }
};
