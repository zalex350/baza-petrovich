<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Розница: корзина гостя (carts.token — из cookie, user_id пуст) и заказ без учётной записи —
 * контакт (delivery_contact_name / _phone уже есть) + email, способ получения и оплаты,
 * public_token — ссылка на заказ для гостя (/o/{token}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('token', 64)->nullable()->unique()->after('user_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_email')->nullable()->after('delivery_contact_phone');
            $table->string('delivery_method', 30)->nullable()->after('customer_email');
            $table->string('payment_method', 30)->nullable()->after('delivery_method');
            $table->string('public_token', 64)->nullable()->unique()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['customer_email', 'delivery_method', 'payment_method', 'public_token']);
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->dropUnique(['token']);
            $table->dropColumn('token');
        });
    }
};
