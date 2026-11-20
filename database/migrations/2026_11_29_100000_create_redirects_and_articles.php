<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Редиректы (админка «Редиректы» и автоматические — при смене адреса товара, раздела, страницы)
 * и статьи (страницы type = article, /stati/{slug}): дата, обложка, ключ импорта со старого сайта.
 * Данные не меняются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            // Путь без домена и без «/» по краям, в нижнем регистре: catalog/profnastil/old-slug
            $table->string('from_path', 500)->unique();
            $table->string('to_url', 500);
            $table->unsignedSmallInteger('status')->default(301);
            $table->string('note', 255)->nullable();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();
        });

        Schema::table('pages', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('is_published');
            $table->string('image', 500)->nullable()->after('published_at');
            $table->string('excerpt', 500)->nullable()->after('image');
            $table->string('source_key', 500)->nullable()->unique()->after('excerpt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');

        Schema::table('pages', function (Blueprint $table) {
            $table->dropUnique(['source_key']);
            $table->dropColumn(['published_at', 'image', 'excerpt', 'source_key']);
        });
    }
};
