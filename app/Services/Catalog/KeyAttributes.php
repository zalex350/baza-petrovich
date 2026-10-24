<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\CategoryFilter;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Support\Collection;

/**
 * Ключевые характеристики строки списка (x-product-row): значения первых фильтруемых
 * характеристик категории товара — в порядке фильтров категории (CatalogFilters), не больше LIMIT.
 * Результат — связь keyAttributes у каждого товара (коллекция ProductAttributeValue).
 */
class KeyAttributes
{
    public const LIMIT = 3;

    /** @var array<int, list<int>> id категории → id характеристик её фильтров */
    private array $byCategory = [];

    public function __construct(private readonly CatalogFilters $filters) {}

    /**
     * @param  iterable<Product>  $products  с загруженной category
     * @param  list<int>|null  $attributeIds  одни фильтры для всех (список категории); null — по категории товара
     */
    public function load(iterable $products, ?array $attributeIds = null): void
    {
        $products = collect($products);

        $ids = $products->mapWithKeys(fn (Product $p) => [
            $p->id => $attributeIds ?? $this->forCategory($p->category),
        ]);

        $all = $ids->flatten()->unique()->values()->all();

        $values = $all
            ? ProductAttributeValue::with('attribute')
                ->whereIn('product_id', $products->pluck('id'))
                ->whereIn('attribute_id', $all)
                ->where('value', '<>', '')
                ->get()
                ->groupBy('product_id')
            : collect();

        foreach ($products as $product) {
            $order = array_flip($ids[$product->id]);

            $product->setRelation('keyAttributes', collect($values[$product->id] ?? [])
                ->filter(fn (ProductAttributeValue $v) => isset($order[$v->attribute_id]))
                ->sortBy(fn (ProductAttributeValue $v) => $order[$v->attribute_id])
                ->take(self::LIMIT)
                ->values());
        }
    }

    /**
     * Характеристики из фильтров категории (своих или унаследованных), в порядке фильтров.
     *
     * @return list<int>
     */
    public function forCategory(?Category $category): array
    {
        if (! $category) {
            return [];
        }

        return $this->byCategory[$category->id] ??= $this->attributeIds($this->filters->forCategory($category)['filters']);
    }

    /**
     * @param  Collection<int, CategoryFilter>  $filters
     * @return list<int>
     */
    public function attributeIds(Collection $filters): array
    {
        return $filters
            ->where('type', CategoryFilter::ATTRIBUTE)
            ->pluck('attribute_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
