<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * product_stocks — наследие тестовых данных (catalog:fill-test-data): витрина её не читала,
 * onec_id не заполнен. Остатки 1С — в products (docs/1C_EXCHANGE_SPEC.md, раздел 5).
 * down() возвращает пустую таблицу.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('product_stocks');
    }

    public function down(): void
    {
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->string('article')->nullable();
            $table->string('sku')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('onec_id')->nullable()->unique();
            $table->integer('stock')->default(0);
            $table->timestamps();
        });
    }
};
