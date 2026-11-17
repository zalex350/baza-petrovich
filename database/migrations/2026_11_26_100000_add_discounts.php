<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Скидки розницы (App\Services\Pricing\Discounts): у товара — процент или сумма, у категории — процент
 * (действует на товары категории и её подкатегорий, если у товара нет своей скидки). Итог —
 * products.sale_price: цена со скидкой для показа, корзины, сортировки и фильтра (null — скидки нет).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->nullable()->after('price');
            $table->decimal('discount_amount', 12, 2)->nullable()->after('discount_percent');
            $table->decimal('sale_price', 12, 2)->nullable()->after('discount_amount');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->nullable()->after('is_hidden');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['discount_percent', 'discount_amount', 'sale_price']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });
    }
};
