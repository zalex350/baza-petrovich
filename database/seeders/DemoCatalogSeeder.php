<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Небольшой демо-каталог металлобазы для локальной разработки (db:seed). Полный каталог —
 * php artisan petrovich:import (database/data/petrovich/catalog.json).
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            ['Металлопрокат', 'metalloprokat', [
                ['Профильная труба 20x20x1.5', 'BP-DEMO-01', 3.36, 'м.п.'],
                ['Уголок стальной 50x50x4', 'BP-DEMO-02', 18.40, 'м.п.'],
            ]],
            ['Профнастил', 'profnastil', [
                ['Профнастил С8 стандарт 0.45 мат', 'BP-DEMO-03', 29.56, 'м²'],
                ['Профнастил ПС20 стандарт 0.4', 'BP-DEMO-04', 18.37, 'м²'],
            ]],
            ['Сетка', 'setka', [
                ['Сетка рабица 50x50 1,6 мм, 1,5x10 м', 'BP-DEMO-05', 41.20, 'рул'],
                ['Сетка кладочная 50x50 4 мм', 'BP-DEMO-06', 7.90, 'шт'],
            ]],
        ];

        foreach ($catalog as [$name, $slug, $products]) {
            $category = Category::updateOrCreate(['slug' => $slug], ['name' => $name]);

            foreach ($products as [$title, $sku, $price, $unit]) {
                Product::updateOrCreate(['sku' => $sku], [
                    'name' => $title,
                    'price' => $price,
                    'vat_rate' => 20,
                    'stock' => 1,
                    'category_id' => $category->id,
                    'is_active' => true,
                    'is_published' => true,
                ])->forceFill(['unit' => $unit])->save();
            }
        }
    }
}
