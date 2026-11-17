<?php

namespace App\Services\Pricing;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Скидки розницы. Правило (по убыванию приоритета):
 *  1. сумма скидки товара (discount_amount, BYN) — цена минус сумма;
 *  2. процент скидки товара (discount_percent);
 *  3. процент ближайшей категории с заданной скидкой (сама категория товара, затем родители).
 * Итог — products.sale_price, округление до копеек; скидки нет, нет цены или итог не меньше цены — null.
 * Цена со скидкой не бывает меньше 0,01.
 *
 * Применяется только к цене по умолчанию (розничной); особые типы цен организаций — без скидок
 * (PriceContext::priceOf).
 *
 * Пересчёт: товар — при сохранении (Product::saving); категория — при изменении скидки или родителя
 * (Category::updated); обмен с 1С — после записи цен (PricesStockHandler).
 */
class Discounts
{
    /** @var Collection<int, array{parent: ?int, percent: ?float}>|null */
    private ?Collection $tree = null;

    /** Цена со скидкой; null — скидки нет */
    public function salePrice(mixed $price, mixed $amount, mixed $percent, ?int $categoryId): ?string
    {
        if ($price === null || (float) $price <= 0) {
            return null;
        }

        $kopecks = (int) round((float) $price * 100);

        if ((float) $amount > 0) {
            $sale = $kopecks - (int) round((float) $amount * 100);
        } else {
            $rate = (float) $percent > 0 ? (float) $percent : $this->categoryPercent($categoryId);

            if (! $rate) {
                return null;
            }

            $sale = (int) round($kopecks * (100 - min($rate, 99.99)) / 100);
        }

        $sale = max(1, $sale);

        return $sale < $kopecks ? number_format($sale / 100, 2, '.', '') : null;
    }

    public function forProduct(Product $product): ?string
    {
        return $this->salePrice(
            $product->getAttributes()['price'] ?? null,
            $product->discount_amount,
            $product->discount_percent,
            $product->category_id,
        );
    }

    /** Действующий процент категории: своя скидка или ближайшего родителя со скидкой */
    public function categoryPercent(?int $categoryId): ?float
    {
        $tree = $this->tree();
        $seen = [];

        while ($categoryId && ! isset($seen[$categoryId]) && ($node = $tree->get($categoryId))) {
            if ($node['percent']) {
                return $node['percent'];
            }

            $seen[$categoryId] = true;
            $categoryId = $node['parent'];
        }

        return null;
    }

    /** Источник скидки для подписи в админке: «товар», «категория …» или null */
    public function source(Product $product): ?string
    {
        if ((float) $product->discount_amount > 0 || (float) $product->discount_percent > 0) {
            return 'товар';
        }

        return $this->categoryPercent($product->category_id) ? 'категория' : null;
    }

    /**
     * Пересчитать sale_price товаров (без событий модели). null — все товары.
     *
     * @param  iterable<int>|null  $productIds
     */
    public function recalculate(?iterable $productIds = null): int
    {
        $this->flush();
        $changed = 0;

        $query = DB::table('products')->select(['id', 'price', 'discount_amount', 'discount_percent', 'category_id', 'sale_price'])->orderBy('id');

        $apply = function ($rows) use (&$changed) {
            foreach ($rows as $row) {
                $sale = $this->salePrice($row->price, $row->discount_amount, $row->discount_percent, $row->category_id);
                $current = $row->sale_price === null ? null : number_format((float) $row->sale_price, 2, '.', '');

                if ($sale !== $current) {
                    DB::table('products')->where('id', $row->id)->update(['sale_price' => $sale]);
                    $changed++;
                }
            }
        };

        if ($productIds === null) {
            $query->chunk(500, $apply);
        } else {
            foreach (array_chunk(collect($productIds)->unique()->values()->all(), 500) as $ids) {
                $apply((clone $query)->whereIn('id', $ids)->get());
            }
        }

        return $changed;
    }

    /** Пересчитать товары категории и всех её подкатегорий */
    public function recalculateCategory(int $categoryId): int
    {
        $this->flush();
        $ids = $this->descendantIds($categoryId);

        return $this->recalculate(DB::table('products')->whereIn('category_id', $ids)->pluck('id'));
    }

    /** @return list<int> категория и все потомки */
    public function descendantIds(int $categoryId): array
    {
        $children = $this->tree()->groupBy('parent', true)->map(fn ($c) => $c->keys()->all());
        $ids = [$categoryId];

        for ($i = 0; $i < count($ids); $i++) {
            foreach ($children->get($ids[$i], []) as $child) {
                if (! in_array($child, $ids, true)) {
                    $ids[] = $child;
                }
            }
        }

        return $ids;
    }

    public function flush(): void
    {
        $this->tree = null;
    }

    /** @return Collection<int, array{parent: ?int, percent: ?float}> */
    private function tree(): Collection
    {
        return $this->tree ??= Category::query()
            ->get(['id', 'parent_id', 'discount_percent'])
            ->mapWithKeys(fn ($c) => [$c->id => [
                'parent' => $c->parent_id,
                'percent' => (float) $c->discount_percent > 0 ? (float) $c->discount_percent : null,
            ]]);
    }
}
