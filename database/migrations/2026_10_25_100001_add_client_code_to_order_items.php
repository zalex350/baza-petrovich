<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Снимок кода клиента в позиции заказа (product_client_codes на момент оформления):
 * дальнейшие изменения кодов заказ не меняют. Существующие позиции — без кода.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('client_code', 100)->nullable()->after('product_sku');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('client_code');
        });
    }
};
