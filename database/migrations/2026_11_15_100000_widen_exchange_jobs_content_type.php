<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * exchange_jobs.content_type: 50 → 255. MIME .xlsx ручной загрузки цен
 * (application/vnd.openxmlformats-officedocument.spreadsheetml.sheet) — 65 символов:
 * на MySQL «Data too long for column 'content_type'» → 500 на «Применить». Данные не меняются.
 * Модель всё равно обрезает значение до длины колонки (ExchangeJob::LENGTHS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_jobs', function (Blueprint $table) {
            $table->string('content_type', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('exchange_jobs', function (Blueprint $table) {
            $table->string('content_type', 50)->nullable()->change();
        });
    }
};
