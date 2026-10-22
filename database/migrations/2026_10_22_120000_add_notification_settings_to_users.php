<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email-уведомления администраторам: о новом заказе и о запросе на отмену.
 * Значимы только для роли admin; по умолчанию включены — в том числе у существующих администраторов.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_new_order')->default(true)->after('blocked_at');
            $table->boolean('notify_cancel_request')->default(true)->after('notify_new_order');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notify_new_order', 'notify_cancel_request']);
        });
    }
};
