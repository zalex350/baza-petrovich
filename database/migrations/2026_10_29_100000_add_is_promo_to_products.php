<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Флаг «Акция» (бейдж «%», страница /promo) — данные сайта, рядом с ХИТ и НОВИНКА.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_promo')->default(false)->after('is_new_locked')->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_promo']);
            $table->dropColumn('is_promo');
        });
    }
};
