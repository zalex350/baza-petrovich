<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Каталог «Петровича»: ключ импорта source_key (путь на baza-petrovich.by), текст раздела (categories.description — HTML, под списком товаров) и услуги
 * как информационные страницы (pages.type = service — раздел «Услуги»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->string('source_key')->nullable()->unique();
        });

        // Ключ импорта (petrovich:import): путь на исходном сайте — повторный импорт обновляет, а не дублирует
        Schema::table('products', function (Blueprint $table) {
            $table->string('source_key')->nullable()->unique();
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->string('type', 20)->default('page')->after('slug')->index();
            $table->integer('sort_order')->default(0)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['source_key']);
            $table->dropColumn(['description', 'source_key']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['source_key']);
            $table->dropColumn('source_key');
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'sort_order']);
        });
    }
};
