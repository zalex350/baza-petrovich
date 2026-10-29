<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Документы товара (SRS §4.8): инструкции, паспорта, сертификаты и т. п. — PDF на диске фото
 * (публичная ссылка), тип, название, порядок в карточке.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type', 30);
            $table->string('path');
            $table->string('original_name');
            $table->unsignedInteger('size');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_documents');
    }
};
