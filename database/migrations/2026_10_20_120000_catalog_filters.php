<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фильтры каталога (SRS §3.9, BR-016): набор фильтров по категориям,
 * признак «Под заказ» (§4.11.3) и индексы под фильтрацию и сортировку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // brand | price | stock | attribute
            $table->foreignId('attribute_id')->nullable()->constrained('attributes')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'type', 'attribute_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            // Нулевой остаток, но товар можно заказать (приходит из учётной системы)
            $table->boolean('on_order')->default(false)->after('stock');

            // Список категории: видимые товары категории; фильтры и сортировки
            $table->index(['category_id', 'is_active', 'is_published'], 'products_catalog_index');
            $table->index('brand');
            $table->index('price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_catalog_index');
            $table->dropIndex(['brand']);
            $table->dropIndex(['price']);
            $table->dropColumn('on_order');
        });

        Schema::dropIfExists('category_filters');
    }
};
