<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Коды товаров клиентов: организация называет наш товар своим кодом (артикулом в своей системе).
 * Один код организации на товар; у одной организации код не повторяется.
 * Удаление товара или организации удаляет и их коды.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_client_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 100);
            $table->timestamps();

            $table->unique(['organization_id', 'product_id']);
            $table->unique(['organization_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_client_codes');
    }
};
