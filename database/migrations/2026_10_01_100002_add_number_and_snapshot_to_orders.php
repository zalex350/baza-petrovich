<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Номер и дата появляются только при оформлении; у существующих
            // заказов их заполняет следующая миграция.
            $table->string('number', 20)->nullable()->unique()->after('id');
            $table->timestamp('placed_at')->nullable()->after('total');
            // Корзина больше не хранится в orders — «draft» как значение по умолчанию не нужен.
            $table->string('status')->default('new')->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            // Снимок товара на момент оформления: карточка заказа не зависит
            // от последующего изменения или удаления товара.
            $table->string('product_name')->nullable()->after('product_id');
            $table->string('product_sku')->nullable()->after('product_name');
            $table->decimal('vat_rate', 5, 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['product_name', 'product_sku', 'vat_rate']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropColumn(['number', 'placed_at']);
            $table->string('status')->default('draft')->change();
        });
    }
};
