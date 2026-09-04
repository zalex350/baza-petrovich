<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Product;

class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [

            [
                'name' => 'Смесители',
                'slug' => 'smesiteli',
            ],

            [
                'name' => 'Душевые системы',
                'slug' => 'dushevye-sistemy',
            ],

            [
                'name' => 'Инсталляции',
                'slug' => 'installyacii',
            ],

        ];

        foreach ($categories as $categoryData) {

            $category = Category::updateOrCreate(
                ['slug' => $categoryData['slug']],
                $categoryData
            );

            $products = match ($category->slug) {

                'smesiteli' => [

                    [
                        'name' => 'Ledeme L2427B',
                        'sku' => 'L2427B',
                        'brand' => 'Ledeme',
                        'price' => 160,
                        'stock' => 12,
                    ],

                    [
                        'name' => 'Grohe Start',
                        'sku' => 'GR-100',
                        'brand' => 'Grohe',
                        'price' => 349,
                        'stock' => 7,
                    ],

                ],

                'dushevye-sistemy' => [

                    [
                        'name' => 'Hansgrohe Croma',
                        'sku' => 'HC-240',
                        'brand' => 'Hansgrohe',
                        'price' => 899,
                        'stock' => 5,
                    ],

                    [
                        'name' => 'Grohe Tempesta',
                        'sku' => 'GT-310',
                        'brand' => 'Grohe',
                        'price' => 640,
                        'stock' => 4,
                    ],

                ],

                default => [

                    [
                        'name' => 'Geberit Duofix',
                        'sku' => 'GD-111',
                        'brand' => 'Geberit',
                        'price' => 790,
                        'stock' => 3,
                    ],

                    [
                        'name' => 'AlcaPlast AM101',
                        'sku' => 'AM101',
                        'brand' => 'AlcaPlast',
                        'price' => 560,
                        'stock' => 6,
                    ],

                ],

            };

            foreach ($products as $product) {

                Product::updateOrCreate(

                    [
                        'sku' => $product['sku'],
                    ],

                    array_merge(
                        $product,
                        [
                            'category_id' => $category->id,
                            'is_active' => true,
                        ]
                    )

                );

            }

        }
    }
}