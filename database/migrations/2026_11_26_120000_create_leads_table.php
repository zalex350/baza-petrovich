<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заявки с сайта (App\Models\Lead): «Узнать цену» (price), «Заказать услугу» (service), «Обратный
 * звонок» (callback). Уходят администраторам (письмо), в Telegram и Битрикс24 (exported_at, crm_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->index();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject')->nullable();
            $table->string('name');
            $table->string('phone', 50);
            $table->string('email')->nullable();
            $table->text('comment')->nullable();
            $table->string('status', 20)->default('new')->index();
            $table->string('crm_id', 50)->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
