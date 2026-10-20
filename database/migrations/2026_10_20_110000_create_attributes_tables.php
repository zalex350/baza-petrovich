<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Характеристики товаров (SRS §4.9, BR-058…062): справочник и значения.
 * name_key — нормализованное название (без регистра и лишних пробелов):
 * «Цвет» и «цвет » — одна характеристика.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_key')->unique();
            $table->string('unit', 50)->nullable();
            $table->string('type', 10)->default('text'); // text | number
            $table->boolean('is_filterable')->default(false);
            $table->timestamps();
        });

        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->string('value');
            $table->decimal('value_number', 15, 4)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // BR-059: один ключ на товар
            $table->unique(['product_id', 'attribute_id']);
            // Фильтры каталога: значения характеристики
            $table->index(['attribute_id', 'value']);
            $table->index(['attribute_id', 'value_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('attributes');
    }
};
