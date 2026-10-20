<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\CategoryFilter;
use App\Models\ProductAttribute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Набор фильтров категории (BR-016). Своя настройка — строки category_filters;
 * без неё — настройка ближайшего родителя; если нигде нет — стандартные
 * (производитель, цена, наличие). Характеристика, у которой сняли «используется
 * в фильтрах», из набора выпадает.
 */
class CatalogFilters
{
    public const DEFAULT = [CategoryFilter::BRAND, CategoryFilter::PRICE, CategoryFilter::STOCK];

    /**
     * @return array{source: ?Category, filters: Collection<int, CategoryFilter>}
     *         source — категория, чья настройка действует (null — стандартный набор)
     */
    public function forCategory(Category $category): array
    {
        $current = $category;
        $guard = 0;

        while ($current && $guard++ < 20) {
            $own = $this->own($current);

            if ($own->isNotEmpty()) {
                return ['source' => $current, 'filters' => $own];
            }

            $current = $current->parent;
        }

        return [
            'source'  => null,
            'filters' => collect(self::DEFAULT)->map(fn ($type, $i) => new CategoryFilter(['type' => $type, 'sort_order' => $i])),
        ];
    }

    /**
     * Своя настройка категории (без наследования).
     *
     * @return Collection<int, CategoryFilter>
     */
    public function own(Category $category): Collection
    {
        return CategoryFilter::with('attribute')
            ->where('category_id', $category->id)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (CategoryFilter $f) => $f->type !== CategoryFilter::ATTRIBUTE || $f->attribute?->is_filterable)
            ->values();
    }

    /**
     * Сохранить свою настройку: $keys — brand | price | stock | attribute:ID в нужном порядке.
     * Пустой список — наследовать от родителя.
     */
    public function save(Category $category, array $keys): void
    {
        $allowed = $this->available()->keys()->all();

        DB::transaction(function () use ($category, $keys, $allowed) {
            CategoryFilter::where('category_id', $category->id)->delete();

            foreach (array_values(array_unique(array_intersect($keys, $allowed))) as $order => $key) {
                [$type, $attributeId] = str_contains($key, ':') ? explode(':', $key) : [$key, null];

                CategoryFilter::create([
                    'category_id'  => $category->id,
                    'type'         => $type,
                    'attribute_id' => $attributeId,
                    'sort_order'   => $order,
                ]);
            }
        });
    }

    /**
     * Все фильтры, которые можно включить: стандартные и характеристики «в фильтрах».
     *
     * @return Collection<string, string> ключ → название
     */
    public function available(): Collection
    {
        $attributes = ProductAttribute::where('is_filterable', true)->orderBy('name')->get()
            ->mapWithKeys(fn ($a) => [CategoryFilter::ATTRIBUTE . ':' . $a->id => $a->name . ($a->unit ? ", {$a->unit}" : '')]);

        return collect(CategoryFilter::STANDARD)->merge($attributes);
    }
}
