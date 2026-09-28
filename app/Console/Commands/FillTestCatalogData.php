<?php

namespace App\Console\Commands;

use App\Services\CatalogMenu;
use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Console\Command;

class FillTestCatalogData extends Command
{
    protected $signature = 'catalog:fill-test-data';

    protected $description = 'Randomly fill price/stock for imported catalog products and activate them (test data only)';

    public function handle(): int
    {
        $path = storage_path('app/imports/catalog_import.json');

        $articleBySku = [];

        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            foreach ($data['products'] ?? [] as $item) {
                if (!empty($item['sku'])) {
                    $articleBySku[$item['sku']] = $item['article'] ?? null;
                }
            }
        }

        $imported = Product::whereNull('price')->get();

        $this->info('Filling test price/stock for ' . $imported->count() . ' products...');

        $bar = $this->output->createProgressBar($imported->count());
        $bar->start();

        foreach ($imported->chunk(200) as $chunk) {

            foreach ($chunk as $product) {

                $price = round(mt_rand(200, 90000) / 100, 2);
                $stock = mt_rand(0, 30);

                $product->price = $price;
                $product->stock = $stock;
                $product->is_active = true;
                $product->save();

                ProductStock::updateOrCreate(
                    ['sku' => $product->sku],
                    [
                        'article' => $articleBySku[$product->sku] ?? null,
                        'name' => $product->name,
                        'stock' => $stock,
                    ]
                );

                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();

        $activatedExisting = Product::where('is_active', false)->update(['is_active' => true]);

        $this->info("Done. Filled: {$imported->count()}. Additionally activated: {$activatedExisting}.");

        // Массовые изменения идут мимо observer — сбрасываем кэш меню вручную
        CatalogMenu::flush();

        return self::SUCCESS;
    }
}