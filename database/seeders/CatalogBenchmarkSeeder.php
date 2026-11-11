<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ProductAttribute;
use App\Services\Catalog\CatalogFilters;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Нагрузочные данные для замера фильтров каталога: категория «Тест фильтров» с подкатегорией,
 * 10 000 товаров, 20 производителей, характеристики «Цвет» (текст) и «Ширина» (число).
 * Запуск: php artisan db:seed --class=CatalogBenchmarkSeeder (только на тестовой базе).
 */
class CatalogBenchmarkSeeder extends Seeder
{
    public const SLUG = 'benchmark-filtrov';

    public function run(int $count = 10000): void
    {
        $parent = Category::firstOrCreate(['slug' => self::SLUG], ['name' => 'Тест фильтров']);
        $child = Category::firstOrCreate(['slug' => self::SLUG . '-2'], ['name' => 'Тест фильтров — подкатегория', 'parent_id' => $parent->id]);

        $color = ProductAttribute::firstOrCreate(['name_key' => 'цвет (тест)'], ['name' => 'Цвет (тест)', 'is_filterable' => true]);
        $width = ProductAttribute::firstOrCreate(['name_key' => 'ширина (тест)'], ['name' => 'Ширина (тест)', 'unit' => 'мм', 'type' => 'number', 'is_filterable' => true]);

        app(CatalogFilters::class)->save($parent, ['brand', 'price', 'stock', "attribute:{$color->id}", "attribute:{$width->id}"]);

        $colors = ['Хром', 'Белый', 'Чёрный', 'Бронза', 'Золото', 'Сатин', 'Графит', 'Медь', 'Никель', 'Латунь'];
        $now = now();
        $startId = (int) DB::table('products')->max('id');

        foreach (array_chunk(range(1, $count), 1000) as $chunk) {
            DB::table('products')->insert(array_map(fn ($i) => [
                'name'         => "Тестовый смеситель {$i}",
                'sku'          => "BENCH-{$startId}-{$i}",
                'brand'        => 'Бренд ' . ($i % 20 + 1),
                'price'        => $i % 37 === 0 ? null : round(20 + ($i * 7919 % 2000), 2),
                'stock'        => $i % 5 === 0 ? 0 : $i % 50,
                // Вставка мимо модели: остаток 1С = доступный (резерва у новых товаров нет)
                'stock_1c'     => $i % 5 === 0 ? 0 : $i % 50,
                'on_order'     => $i % 10 === 0,
                'category_id'  => $i % 2 ? $parent->id : $child->id,
                'is_active'    => $i % 50 !== 0,
                'is_published' => true,
                'created_at'   => $now,
                'updated_at'   => $now,
            ], $chunk));
        }

        $ids = DB::table('products')->where('sku', 'like', "BENCH-{$startId}-%")->pluck('id');

        foreach ($ids->chunk(1000) as $chunk) {
            $rows = [];

            foreach ($chunk as $n => $id) {
                $rows[] = ['product_id' => $id, 'attribute_id' => $color->id, 'value' => $colors[$n % 10], 'value_number' => null, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now];
                $w = 100 + ($n * 13 % 400);
                $rows[] = ['product_id' => $id, 'attribute_id' => $width->id, 'value' => (string) $w, 'value_number' => $w, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now];
            }

            DB::table('product_attribute_values')->insert($rows);
        }

        // Заказы для сортировки «Популярные»: 2000 позиций по случайным товарам
        $user = DB::table('users')->value('id');

        if ($user) {
            $orderId = DB::table('orders')->insertGetId(['user_id' => $user, 'status' => 'completed', 'created_at' => $now, 'updated_at' => $now]);

            DB::table('order_items')->insert($ids->random(min(2000, $ids->count()))->map(fn ($id) => [
                'order_id' => $orderId, 'product_id' => $id, 'quantity' => random_int(1, 20), 'price' => 100,
                'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }
    }
}
