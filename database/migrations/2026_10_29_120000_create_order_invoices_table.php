<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Счёт на оплату заказа (SRS §8.10, UC-035): один на заказ, PDF в закрытом хранилище.
 * source — откуда счёт: manual (загрузил администратор) | erp (придёт из 1С, external_id —
 * номер/идентификатор документа в 1С). Новый счёт заменяет прежний.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('source', 20)->default('manual');
            $table->string('external_id')->nullable();
            $table->string('path');
            $table->string('original_name');
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_invoices');
    }
};
