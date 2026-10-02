<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История изменения статусов заказа (SRS §10.7.5).
 * Колонки названы from_status / to_status: from и to — зарезервированные слова SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable(); // null — заказ только что оформлен
            $table->string('to_status');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // Отметка «остаток возвращён на склад»: отмена возвращает его ровно один раз
            $table->timestamp('stock_returned_at')->nullable()->after('placed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('stock_returned_at');
        });

        Schema::dropIfExists('order_status_history');
    }
};
