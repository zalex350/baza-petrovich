<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Товар для плитки раздела (App\Services\CategoryTiles): фото этого товара на плитке главной и /catalog,
 * если у категории нет своей картинки. Пусто — самый популярный товар раздела с фото и в наличии.
 * Удалили товар — поле очищается. Только добавляет колонку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('tile_product_id')->nullable()->after('icon_svg')->constrained('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tile_product_id');
        });
    }
};
