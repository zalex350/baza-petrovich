<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Поток 1 — номенклатура (docs/1C_EXCHANGE_SPEC.md, раздел 4):
 *  - products.hidden_by_1c — товар скрыт потому, что 1С прислала active = 0; active = 1 показывает
 *    только такие товары, скрытый вручную в админке остаётся скрытым;
 *  - exchange_jobs.dry_run — пробный прогон (?dry_run=1), данные сайта не менялись;
 *  - exchange_jobs.report — итог задания сверх счётчиков: created, warnings, summary, missing (JSON).
 * Новые колонки со значениями по умолчанию — существующие данные не меняются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('hidden_by_1c')->default(false)->after('is_published');
        });

        Schema::table('exchange_jobs', function (Blueprint $table) {
            $table->boolean('dry_run')->default(false)->after('mode');
            $table->longText('report')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('hidden_by_1c');
        });

        Schema::table('exchange_jobs', function (Blueprint $table) {
            $table->dropColumn(['dry_run', 'report']);
        });
    }
};
