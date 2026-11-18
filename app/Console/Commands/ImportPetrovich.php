<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\CatalogMenu;
use App\Services\Images\ProductImageService;
use App\Services\Pricing\Discounts;
use App\Services\ProductAttributes;
use App\Support\Html;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Каталог baza-petrovich.by → сайт (database/data/petrovich/catalog.json, собран с текущего сайта):
 * разделы с текстами, товары (цена с НДС, единица, вес, характеристики из таблиц, описание, «Хит»),
 * услуги (страницы type = service) и, с --images, фото товаров (скачиваются с исходного сайта).
 *
 *   php artisan petrovich:import --fresh --images     — первый запуск: старая номенклатура удаляется
 *   php artisan petrovich:import                       — повтор: товары и разделы находятся по source_key,
 *                                                        цены, тексты и характеристики обновляются
 *
 * Наличие: «в наличии» — stock 1, «нет в наличии» — 0, цена «по запросу» — цены нет («Узнать цену»).
 * Остатков на исходном сайте нет — в .env SHOP_TRACK_STOCK=false до обмена с 1С.
 */
class ImportPetrovich extends Command
{
    protected $signature = 'petrovich:import
        {file? : JSON каталога (по умолчанию database/data/petrovich/catalog.json)}
        {--fresh : удалить все товары, разделы и услуги перед импортом (старая номенклатура)}
        {--images : скачать фото товаров с исходного сайта}
        {--force : без вопроса при --fresh}';

    protected $description = 'Импорт каталога baza-petrovich.by: разделы, товары, услуги, фото';

    /** @var array<string, int> путь раздела → id */
    private array $categoryIds = [];

    public function handle(ProductAttributes $attributes, ProductImageService $images, Discounts $discounts): int
    {
        $file = $this->argument('file') ?: database_path('data/petrovich/catalog.json');
        $data = json_decode((string) @file_get_contents($file), true);

        if (! is_array($data) || ! isset($data['categories'], $data['products'])) {
            $this->error("Не прочитан каталог: {$file}");

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            if (! $this->option('force') && ! $this->confirm('Удалить ВСЕ товары, разделы и услуги перед импортом?')) {
                return self::FAILURE;
            }

            $this->purge();
        }

        DB::transaction(function () use ($data) {
            $this->importCategories($data['categories']);
        });

        $bar = $this->output->createProgressBar(count($data['products']));
        $created = $updated = 0;
        $photos = 0;

        foreach ($data['products'] as $i => $row) {
            [$product, $isNew] = DB::transaction(fn () => $this->importProduct($row, $i, $attributes));
            $isNew ? $created++ : $updated++;

            if ($this->option('images') && ! $product->images()->exists()) {
                $photos += $this->downloadImages($product, $row['images'] ?? [], $images);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $services = $this->importServices($data['services'] ?? []);

        $discounts->recalculate();
        $icons = PetrovichIcons::apply();
        app(CatalogMenu::class)->flush();

        $this->info(sprintf(
            'Разделов: %d. Товаров: создано %d, обновлено %d. Фото: %d. Услуг: %d. Иконок: %d.',
            count($this->categoryIds), $created, $updated, $photos, $services, $icons
        ));

        return self::SUCCESS;
    }

    /** Старая номенклатура: товары (с фото, характеристиками, ценами), разделы, услуги */
    private function purge(): void
    {
        $this->warn('Удаляю прежний каталог…');

        $images = app(ProductImageService::class);
        ProductImage::query()->each(fn (ProductImage $image) => $images->delete($image));

        DB::transaction(function () {
            DB::table('cart_items')->delete();
            DB::table('product_attribute_values')->delete();
            DB::table('product_prices')->delete();
            DB::table('product_drafts')->delete();
            DB::table('product_client_codes')->delete();
            DB::table('product_documents')->delete();
            DB::table('order_items')->update(['product_id' => null]);
            DB::table('leads')->update(['product_id' => null]);
            DB::table('categories')->update(['tile_product_id' => null]);
            Product::withTrashed()->forceDelete();
            DB::table('category_filters')->delete();
            DB::table('categories')->update(['parent_id' => null]);
            Category::query()->delete();
            Page::query()->where('type', Page::SERVICE)->delete();
        });
    }

    /** @param list<array{path: string, parent: ?string, name: string, slug_part: string, description: string, sort: int}> $rows */
    private function importCategories(array $rows): void
    {
        // Родитель раньше потомков: по глубине пути
        usort($rows, fn ($a, $b) => [substr_count($a['path'], '/'), $a['sort'], $a['path']] <=> [substr_count($b['path'], '/'), $b['sort'], $b['path']]);

        $order = [];

        foreach ($rows as $row) {
            $parentId = $row['parent'] ? ($this->categoryIds[$row['parent']] ?? null) : null;
            $category = Category::where('source_key', $row['path'])->first() ?? new Category;

            $category->fill([
                'name' => $row['name'],
                'parent_id' => $parentId,
                'description' => $this->html($row['description'] ?? ''),
                'sort_order' => $row['sort'] < 1000 ? $row['sort'] : ($order[$parentId] = ($order[$parentId] ?? 0) + 1),
            ]);
            $category->source_key = $row['path'];

            if (! $category->exists) {
                $category->slug = $this->uniqueSlug('categories', $row['slug_part'] ?: Str::slug($row['name']));
            }

            $category->save();
            $this->categoryIds[$row['path']] = $category->id;
        }
    }

    /** @return array{0: Product, 1: bool} */
    private function importProduct(array $row, int $i, ProductAttributes $attributes): array
    {
        $product = Product::withTrashed()->where('source_key', $row['path'])->first();
        $isNew = ! $product;
        $product ??= new Product;

        $product->fill([
            'name' => $row['name'],
            'display_name' => $row['name'],
            'price' => $row['price'],
            'vat_rate' => 20,
            'stock' => $row['status'] === 'out' ? 0 : 1,
            'category_id' => $this->categoryIds[$row['category']] ?? null,
            'description' => $this->html($row['description'] ?? '') ?: null,
            'is_hit' => (bool) ($row['hit'] ?? false),
            'is_active' => true,
            'is_published' => true,
        ]);

        // Поля 1С (не в $fillable): единица, ключ импорта
        $product->forceFill([
            'unit' => trim((string) ($row['unit'] ?? '')) ?: 'шт',
            'source_key' => $row['path'],
        ]);

        if ($isNew) {
            $product->sku = 'BP-'.str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT);
            $product->slug = $this->uniqueSlug('products', $row['slug'] ?: Str::slug($row['name']));
        }

        if ($product->trashed()) {
            $product->restore();
        }

        $product->save();

        $rows = [];
        foreach ($row['attributes'] ?? [] as $name => $value) {
            $rows[] = ['name' => $name, 'value' => $value];
        }
        if (! empty($row['weight'])) {
            $rows[] = ['name' => 'Вес, кг', 'value' => rtrim(rtrim(number_format((float) $row['weight'], 3, ',', ''), '0'), ',')];
        }
        if (! empty($row['volume_price'])) {
            [$label, $value] = array_map('trim', explode(':', $row['volume_price'], 2)) + [1 => ''];
            $rows[] = ['name' => 'Цена '.mb_strtolower($label), 'value' => str_replace('руб.', 'BYN', $value)];
        }

        try {
            $attributes->sync($product, $attributes->fromRows($rows));
        } catch (Throwable $e) {
            $this->warn("\n{$row['name']}: характеристики не записаны — {$e->getMessage()}");
        }

        return [$product, $isNew];
    }

    /** @param list<string> $urls */
    private function downloadImages(Product $product, array $urls, ProductImageService $images): int
    {
        $count = 0;

        foreach (array_slice($urls, 0, 8) as $url) {
            $tmp = tempnam(sys_get_temp_dir(), 'bp');

            try {
                $response = Http::timeout(30)->retry(2, 1000)->withOptions(['sink' => $tmp])->get($url);

                if (! $response->successful()) {
                    continue;
                }

                $name = basename(parse_url($url, PHP_URL_PATH) ?: 'photo.jpg');
                $images->upload($product, new UploadedFile($tmp, $name, mime_content_type($tmp) ?: null, null, true));
                $count++;
            } catch (Throwable $e) {
                $this->warn("\nФото {$url}: {$e->getMessage()}");
            } finally {
                @unlink($tmp);
            }
        }

        return $count;
    }

    /** @param list<array{slug: string, title: string, description: string}> $rows */
    private function importServices(array $rows): int
    {
        foreach ($rows as $i => $row) {
            Page::updateOrCreate(['slug' => $row['slug']], [
                'title' => $row['title'],
                'type' => Page::SERVICE,
                'sort_order' => $i + 1,
                'body' => $this->html($row['description'] ?? ''),
                'is_published' => true,
            ]);
        }

        return count($rows);
    }

    private function html(string $html): string
    {
        return trim(Html::clean($html));
    }

    private function uniqueSlug(string $table, string $base): string
    {
        $base = trim(Str::limit($base, 200, ''), '-') ?: 'item';
        $slug = $base;

        for ($n = 2; DB::table($table)->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
