<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Источник визита покупателя (UTM, gclid, yclid, сайт-источник, страница входа — App\Support\Attribution)
 * в заказе и заявке: видно в админке, уходит в Битрикс24. Данные не меняются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->json('attribution')->nullable();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->json('attribution')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('attribution');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('attribution');
        });
    }
};
