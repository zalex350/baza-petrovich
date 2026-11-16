<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\CatalogMenu;
use App\Services\Images\PlaceholderImages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Поиск фото-заглушек товаров (PlaceholderImages) и статистика настоящих фото.
 *
 * Без --mark — только отчёт, база не меняется: сколько заглушек и по каким признакам, самые частые
 * одинаковые файлы, сколько товаров витрины с настоящим фото — всего и по разделам верхнего уровня
 * (с учётом найденного). --mark — пометить найденные (is_placeholder) и записать content_hash;
 * --reset — сначала снять все прежние пометки (иначе они сохраняются). После пометки сбрасывается
 * кэш меню и плиток разделов.
 */
class ImagesPlaceholders extends Command
{
    protected $signature = 'images:placeholders
        {--mark : Пометить найденные заглушки (без опции — только отчёт)}
        {--reset : Перед пометкой снять все прежние пометки}
        {--min-products='.PlaceholderImages::MIN_PRODUCTS.' : Одинаковый файл у стольких товаров и больше — заглушка (0 — не искать одинаковые)}';

    protected $description = 'Найти фото-заглушки товаров и посчитать товары с настоящим фото по разделам';

    /** Одинаковых файлов в отчёте */
    private const TOP = 10;

    public function handle(): int
    {
        $minProducts = max(0, (int) $this->option('min-products'));
        $disk = Storage::disk(ProductImage::disk());

        $reasons = [];        // id фото => признак
        $hashes = [];         // id фото => sha1 (для записи)
        $byHash = [];         // sha1 => [product_id => true]
        $sample = [];         // sha1 => пример исходного имени
        $productOf = [];      // id фото => product_id
        $marked = [];         // id фото, помеченные раньше
        $imageHash = [];      // id фото => sha1 (все с файлом)

        ProductImage::query()->orderBy('id')->chunkById(1000, function ($images) use ($disk, &$reasons, &$hashes, &$byHash, &$sample, &$productOf, &$marked, &$imageHash) {
            foreach ($images as $image) {
                $productOf[$image->id] = $image->product_id;

                if ($image->is_placeholder) {
                    $marked[$image->id] = true;
                }

                $hash = $image->content_hash;
                $exists = trim((string) $image->path) !== '' && $disk->exists($image->pathFor('large'));

                if ($exists && $hash === null) {
                    $hash = sha1($disk->get($image->pathFor('large')));
                    $hashes[$image->id] = $hash;
                }

                if ($reason = PlaceholderImages::reason($image, $exists)) {
                    $reasons[$image->id] = $reason;
                }

                if ($exists && $hash !== null) {
                    $byHash[$hash][$image->product_id] = true;
                    $sample[$hash] ??= $image->original_name ?: basename((string) $image->path);
                    $imageHash[$image->id] = $hash;
                }
            }
        });

        // Одинаковый файл у многих товаров
        $duplicates = $minProducts > 0
            ? array_filter($byHash, fn ($products) => count($products) >= $minProducts)
            : [];

        foreach ($imageHash as $id => $hash) {
            if (isset($duplicates[$hash])) {
                $reasons[$id] ??= PlaceholderImages::DUPLICATE;
            }
        }

        $this->report($reasons, $duplicates, $sample, count($productOf), count($marked));

        $placeholders = $this->option('reset') ? $reasons : $reasons + $marked;

        if ($this->option('mark')) {
            $this->mark(array_keys($reasons), $hashes);
            $this->info('Помечено заглушек: '.count($reasons).($this->option('reset') ? ' (прежние пометки сняты)' : ', всего с прежними: '.count($placeholders)));
        } else {
            $this->line('Отчёт без изменений. Пометить: php artisan images:placeholders --mark');
        }

        $this->stats(array_diff_key($productOf, $placeholders));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $reasons
     * @param  array<string, array<int, true>>  $duplicates
     * @param  array<string, string>  $sample
     */
    private function report(array $reasons, array $duplicates, array $sample, int $total, int $marked): void
    {
        $this->info("Фото товаров: {$total}, помечено заглушками раньше: {$marked}, найдено заглушек: ".count($reasons));

        $counts = array_count_values($reasons);
        $rows = [];
        foreach (PlaceholderImages::REASONS as $key => $label) {
            $rows[] = [$label, $counts[$key] ?? 0];
        }
        $this->table(['Признак', 'Фото'], $rows);

        if ($duplicates !== []) {
            uasort($duplicates, fn ($a, $b) => count($b) <=> count($a));
            $this->line('Одинаковые файлы у многих товаров:');
            $this->table(['sha1', 'Товаров', 'Пример имени файла'], collect($duplicates)->take(self::TOP)
                ->map(fn ($products, $hash) => [substr($hash, 0, 12), count($products), $sample[$hash] ?? ''])->values()->all());
        }
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, string>  $hashes
     */
    private function mark(array $ids, array $hashes): void
    {
        DB::transaction(function () use ($ids, $hashes) {
            if ($this->option('reset')) {
                ProductImage::query()->where('is_placeholder', true)->update(['is_placeholder' => false]);
            }

            foreach ($hashes as $id => $hash) {
                ProductImage::query()->whereKey($id)->update(['content_hash' => $hash]);
            }

            foreach (array_chunk($ids, 500) as $chunk) {
                ProductImage::query()->whereIn('id', $chunk)->update(['is_placeholder' => true]);
            }
        });

        CatalogMenu::flush();
    }

    /**
     * Товары витрины (активные и опубликованные) с настоящим фото: всего и по разделам верхнего уровня.
     *
     * @param  array<int, int>  $realImages  id настоящего фото => product_id
     */
    private function stats(array $realImages): void
    {
        $withPhoto = array_flip(array_values($realImages));
        $categories = Category::query()->get(['id', 'parent_id', 'name'])->keyBy('id');

        $rootOf = function (?int $id) use ($categories): ?int {
            for ($depth = 0; $id && $depth < 20; $depth++) {
                $parent = $categories->get($id)?->parent_id;
                if (! $parent) {
                    return $categories->has($id) ? $id : null;
                }
                $id = $parent;
            }

            return null;
        };

        $sections = [];
        $total = ['all' => 0, 'photo' => 0];

        foreach (Product::query()->where('is_active', true)->where('is_published', true)->toBase()->get(['id', 'category_id']) as $product) {
            $root = $rootOf($product->category_id) ?? 0;
            $sections[$root] ??= ['all' => 0, 'photo' => 0];
            $sections[$root]['all']++;
            $total['all']++;

            if (isset($withPhoto[$product->id])) {
                $sections[$root]['photo']++;
                $total['photo']++;
            }
        }

        uasort($sections, fn ($a, $b) => [$b['photo'], $b['all']] <=> [$a['photo'], $a['all']]);

        $rows = [];
        foreach ($sections as $root => $count) {
            $rows[] = [$root ? $categories->get($root)->name : '— без категории —', $count['photo'], $count['all']];
        }
        $rows[] = ['ВСЕГО', $total['photo'], $total['all']];

        $this->line('Товары витрины с настоящим фото (без заглушек):');
        $this->table(['Раздел', 'С фото', 'Товаров'], $rows);
    }
}
