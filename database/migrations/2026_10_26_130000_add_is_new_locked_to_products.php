<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Новинку» поставили или сняли в админке — импорт каталога (catalog:import-db) её больше
 * не меняет: иначе товар из «Новинок» catalog.db снова получал бейдж при каждом прогоне (A-6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_new_locked')->default(false)->after('is_new');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_new_locked');
        });
    }
};
