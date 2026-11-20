<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Откуда отзыв: google | yandex | letter (письменный, скан) | null (с сайта / добавлен вручную).
 * На витрине — значок источника; отзывы Google и Яндекса — первыми. Данные не меняются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('group');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
