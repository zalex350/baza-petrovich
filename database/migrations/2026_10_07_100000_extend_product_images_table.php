<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Галерея фото товара (SRS §4.7, BR-051…BR-054).
 *
 * product_images: основное фото (одно на товар — проверяется в ProductImageService),
 * порядок, исходное имя файла и размеры большой версии.
 *
 * Прежнее поле products.image переносится в product_images как основное фото и
 * удаляется. Перенесённая запись указывает на старый файл как есть (без webp-размеров):
 * ProductImage::url() отдаёт его для всех размеров, отсутствующий файл заменяет заглушка.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('original_name')->nullable()->after('path');
            $table->boolean('is_main')->default(false)->after('original_name');
            $table->unsignedInteger('sort_order')->default(0)->after('is_main');
            $table->unsignedInteger('width')->nullable()->after('sort_order');
            $table->unsignedInteger('height')->nullable()->after('width');

            $table->index(['product_id', 'sort_order']);
        });

        $now = now();

        DB::table('products')
            ->whereNotNull('image')
            ->where('image', '!=', '')
            ->orderBy('id')
            ->select(['id', 'image'])
            ->chunk(500, function ($products) use ($now) {
                DB::table('product_images')->insert($products->map(fn ($product) => [
                    'product_id'    => $product->id,
                    'path'          => $product->image,
                    'original_name' => basename($product->image),
                    'is_main'       => true,
                    'sort_order'    => 0,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ])->all());
            });

        // Строки, загруженные раньше без признака основного: основным становится первое
        $withoutMain = DB::table('product_images')
            ->select('product_id')
            ->groupBy('product_id')
            ->havingRaw('SUM(CASE WHEN is_main THEN 1 ELSE 0 END) = 0')
            ->pluck('product_id');

        foreach ($withoutMain as $productId) {
            $first = DB::table('product_images')->where('product_id', $productId)->orderBy('id')->value('id');
            DB::table('product_images')->where('id', $first)->update(['is_main' => true]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image')->nullable();
        });

        // Обратно — путь основного фото (для загруженных через галерею — большая версия)
        DB::table('product_images')->where('is_main', true)->orderBy('id')->each(function ($image) {
            DB::table('products')->where('id', $image->product_id)->update(['image' => $image->path]);
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'sort_order']);
            $table->dropColumn(['original_name', 'is_main', 'sort_order', 'width', 'height']);
        });
    }
};
