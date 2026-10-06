<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отмена заказа клиентом — только через запрос, решение принимает администратор.
 *
 * orders.cancel_requested_* — открытый запрос (после решения поля очищаются,
 * сам запрос и решение остаются в истории).
 * order_status_history.event — запись без смены статуса: запрос отмены, отклонение.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('cancel_requested_at')->nullable()->after('stock_returned_at');
            $table->foreignId('cancel_requested_by')->nullable()->after('cancel_requested_at')
                ->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable()->after('cancel_requested_by');

            $table->index('cancel_requested_at');
        });

        Schema::table('order_status_history', function (Blueprint $table) {
            // null — смена статуса; cancel_requested / cancel_rejected — событие без смены статуса
            $table->string('event', 50)->nullable()->after('to_status');
        });
    }

    public function down(): void
    {
        Schema::table('order_status_history', function (Blueprint $table) {
            $table->dropColumn('event');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['cancel_requested_at']);
            $table->dropConstrainedForeignId('cancel_requested_by');
            $table->dropColumn(['cancel_requested_at', 'cancel_reason']);
        });
    }
};
