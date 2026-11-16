<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фото-заглушки товаров («нет фото» поставщика, плейсхолдеры): is_placeholder — фото не показывается
 * нигде на сайте (плитки, карточки, карусели, списки), товар считается без фото. Помечает команда
 * images:placeholders (по имени файла, пустому или отсутствующему файлу, одинаковому содержимому
 * у многих товаров) и загрузка в админке. content_hash — sha1 файла фото (поиск одинаковых).
 * Данные не меняются: пометить на стенде — `php artisan images:placeholders --mark`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->boolean('is_placeholder')->default(false)->after('is_main');
            $table->char('content_hash', 40)->nullable()->after('height');

            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropIndex(['content_hash']);
            $table->dropColumn(['is_placeholder', 'content_hash']);
        });
    }
};
