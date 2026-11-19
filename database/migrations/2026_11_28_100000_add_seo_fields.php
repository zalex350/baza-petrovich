<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO разделов и товаров: свой заголовок и описание для поиска (пусто — шаблон из «Настроек»),
 * у раздела — вопросы и ответы (FAQ под списком и разметка FAQPage). Данные не меняются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('seo_title', 255)->nullable()->after('description');
            $table->string('seo_description', 500)->nullable()->after('seo_title');
            $table->json('faq')->nullable()->after('seo_description');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('seo_title', 255)->nullable()->after('description');
            $table->string('seo_description', 500)->nullable()->after('seo_title');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['seo_title', 'seo_description', 'faq']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['seo_title', 'seo_description']);
        });
    }
};
