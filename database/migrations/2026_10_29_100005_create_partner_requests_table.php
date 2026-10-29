<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заявки «Стать партнёром» с сайта и флаг письма администраторам о новой заявке
 * (по умолчанию включён, как уведомления о заказах).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_requests', function (Blueprint $table) {
            $table->id();
            $table->string('organization');
            $table->string('unp', 9);
            $table->string('contact_name');
            $table->string('phone', 50);
            $table->string('email');
            $table->text('comment')->nullable();
            $table->timestamp('consent_at');
            $table->string('status', 20)->default('new')->index();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_partner_request')->default(true)->after('notify_cancel_request');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_partner_request');
        });

        Schema::dropIfExists('partner_requests');
    }
};
