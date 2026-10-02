<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Одноразовый токен формы оформления: повторная отправка той же формы
 * находит уже созданный заказ, unique-индекс не даёт создать второй.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('checkout_token', 64)->nullable()->unique()->after('placed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['checkout_token']);
            $table->dropColumn('checkout_token');
        });
    }
};
