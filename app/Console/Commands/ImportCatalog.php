<?php

namespace App\Console\Commands;

use App\Services\CatalogMenu;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportCatalog extends Command
{
    protected $signature = 'catalog:import';

    protected $description = 'Импорт каталога из storage/app/imports/catalog_import.json';

    public function handle(): int
    {
        $path = storage_path('app/imports/catalog_import.json');

        if (!file_exists($path)) {
            $this->error('Файл не найден: ' . $path);
            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($path), true);

        if (!$data || !isset($data['categories'], $data['products'])) {
            $this->error('Неверная структура JSON: нужны ключи categories и products');
            return self::FAILURE;
        }

        $this->info('Импорт категорий: ' . count($data['categories']) . '…');

        $categoryMap = [];
        $usedSlugs = [];

        foreach ($data['categories'] as $categoryName) {

            $existing = Category::where('name', $categoryName)->first();

            if ($existing) {
                $categoryMap[$categoryName] = $existing->id;
                continue;
            }

            $baseSlug = Str::slug($categoryName);
            $slug = $baseSlug;
            $i = 2;

            while (isset($usedSlugs[$slug]) || Category::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $i;
                $i++;
            }

            $usedSlugs[$slug] = true;

            $category = Category::create([
                'name' => $categoryName,
                'slug' => $slug,
            ]);

            $categoryMap[$categoryName] = $category->id;
        }

        $this->info('Категории готовы. Импорт товаров: ' . count($data['products']) . '…');

        $bar = $this->output->createProgressBar(count($data['products']));
        $bar->start();

        $created = 0;
        $skipped = 0;

        foreach (array_chunk($data['products'], 500) as $chunk) {

            $rows = [];

            foreach ($chunk as $item) {

                $bar->advance();

                if (empty($item['sku'])) {
                    $skipped++;
                    continue;
                }

                if (Product::where('sku', $item['sku'])->exists()) {
                    $skipped++;
                    continue;
                }

                $categoryId = $item['category'] ? ($categoryMap[$item['category']] ?? null) : null;

                $rows[] = [
                    'name' => $item['name'] ?: $item['sku'],
                    'sku' => $item['sku'],
                    'brand' => $item['brand'],
                    'description' => $item['description'],
                    'category_id' => $categoryId,
                    'price' => null,
                    'stock' => 0,
                    'is_active' => false,
                    'slug' => Str::slug(($item['name'] ?: $item['sku']) . '-' . $item['sku']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $created++;
            }

            if (!empty($rows)) {
                Product::insert($rows);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Готово. Создано: {$created}, пропущено (дубли или без артикула): {$skipped}");

        // Массовые изменения идут мимо observer — сбрасываем кэш меню вручную
        CatalogMenu::flush();

        return self::SUCCESS;
    }
}