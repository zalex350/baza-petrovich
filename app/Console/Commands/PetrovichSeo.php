<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Console\Command;

/**
 * Тексты для поиска и покупателей (database/data/petrovich/seo_content.php):
 * - разделам верхнего уровня — текст под списком и частые вопросы (только пустые; --force — перезаписать);
 * - --products: товарам без описания — описание из database/data/petrovich/product_descriptions.json
 *   (ключ — source_key товара): тексты с наших сайтов (baza-petrovich.by, zabor-petrovich.by) — сразу на сайт,
 *   написанные по характеристикам (source = generated) — на одобрение в админке (фильтр «Описание:
 *   не одобрено»); нет в файле — короткое описание по шаблону, тоже на одобрение. --approve — всё сразу на сайт.
 */
class PetrovichSeo extends Command
{
    protected $signature = 'petrovich:seo
        {--force : перезаписать уже заполненные тексты и вопросы разделов}
        {--products : описания товарам без описания}
        {--approve : описания товаров сразу на сайте, без одобрения в админке}';

    protected $description = 'Тексты разделов, частые вопросы и описания товаров для SEO';

    /** Характеристик в описании товара */
    private const ATTRIBUTES = 3;

    public function handle(): int
    {
        $content = require database_path('data/petrovich/seo_content.php');

        [$texts, $faqs] = self::applyCategories($content, (bool) $this->option('force'));
        $this->info("Разделы: тексты — {$texts}, вопросы — {$faqs}");

        if ($this->option('products')) {
            $count = self::applyProducts($content, (bool) $this->option('approve'));
            $this->info("Описаний товаров: {$count}".($this->option('approve') ? '' : ' (ждут одобрения в админке: Товары → «Описание: не одобрено»)'));
        }

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} */
    public static function applyCategories(array $content, bool $force = false): array
    {
        $texts = $faqs = 0;

        foreach ($content as $key => $row) {
            $category = Category::where('source_key', $key)->first();

            if (! $category) {
                continue;
            }

            if (! empty($row['description']) && ($force || blank(strip_tags((string) $category->description)))) {
                $category->description = trim($row['description']);
                $texts++;
            }

            if (! empty($row['faq']) && ($force || empty($category->faq))) {
                $category->faq = $row['faq'];
                $faqs++;
            }

            $category->save();
        }

        return [$texts, $faqs];
    }

    public static function applyProducts(array $content, bool $approve = false): int
    {
        $categories = Category::query()->get(['id', 'name', 'parent_id', 'source_key', 'is_hidden'])->keyBy('id');

        // Раздел верхнего уровня — по цепочке родителей
        $root = function (?int $id) use ($categories): ?Category {
            $guard = 0;

            while ($id && ($category = $categories->get($id)) && $category->parent_id && $guard++ < 20) {
                $id = $category->parent_id;
            }

            return $id ? $categories->get($id) : null;
        };

        $count = 0;
        $file = database_path('data/petrovich/product_descriptions.json');
        $prepared = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];

        Product::query()
            ->where(fn ($q) => $q->whereNull('description')->orWhere('description', ''))
            ->with(['attributeValues.attribute'])
            ->chunkById(200, function ($products) use (&$count, $categories, $root, $content, $approve, $prepared) {
                foreach ($products as $product) {
                    $ready = $prepared[$product->source_key] ?? null;

                    if ($ready && filled($ready['html'] ?? null)) {
                        // С наших сайтов — сразу на сайт; написанное по характеристикам — на одобрение
                        $product->forceFill([
                            'description' => trim($ready['html']),
                            'description_generated' => ! $approve && ($ready['source'] ?? 'generated') === 'generated',
                        ])->save();
                        $count++;

                        continue;
                    }

                    $category = $categories->get($product->category_id);
                    $section = $root($product->category_id);

                    $product->forceFill([
                        'description' => self::productText($product, $category, $content[$section?->source_key]['product'] ?? null),
                        'description_generated' => ! $approve,
                    ])->save();

                    $count++;
                }
            });

        return $count;
    }

    /** Описание товара: 2–3 предложения обычным текстом (на сайте — с переносами строк) */
    public static function productText(Product $product, ?Category $category, ?string $usage): string
    {
        $attributes = $product->attributeValues
            ->filter(fn (ProductAttributeValue $v) => $v->attribute && ! $v->attribute->is_logistics && ! $v->attribute->is_admin_only
                && trim((string) $v->value) !== '' && ! Product::isPackageAttribute($v->attribute->name))
            ->take(self::ATTRIBUTES)
            ->map(fn (ProductAttributeValue $v) => mb_strtolower($v->attribute->name).' — '.$v->displayValue())
            ->implode(', ');

        $first = $product->title()
            .($category && ! $category->is_hidden ? ' из раздела «'.$category->name.'»' : '')
            .($product->brand ? ', производитель '.$product->brand : '')
            .'.';

        return collect([
            $first.($attributes !== '' ? ' Характеристики: '.$attributes.'.' : ''),
            $usage,
            'Доставка по Минску и Беларуси: заказы до 12:00 — в тот же день. Самовывоз со складов в Минске и Бресте.',
        ])->filter()->implode("\n\n");
    }
}
