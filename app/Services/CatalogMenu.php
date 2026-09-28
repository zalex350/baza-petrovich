<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Дерево категорий для меню каталога (мегаменю и мобильное меню).
 *
 * Два запроса на всё дерево: категории + счётчики товаров по category_id.
 * Счётчик категории включает товары всех вложенных подкатегорий; учитываются
 * только активные и опубликованные товары. Пустые категории в дерево не попадают.
 * Результат кэшируется; кэш сбрасывает CatalogMenuObserver.
 */
class CatalogMenu
{
    public const CACHE_KEY = 'catalog_menu.tree';

    /** Защита от циклов в parent_id */
    private const MAX_DEPTH = 10;

    /**
     * @return list<array{id: int, name: string, url: string, image: ?string, count: int, children: list<array>}>
     */
    public function tree(): array
    {
        return Cache::remember(self::CACHE_KEY, config('shop.catalog_menu_ttl'), fn () => $this->build());
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * ID категории и всех её потомков (для страницы «весь раздел»).
     *
     * @return list<int>
     */
    public function descendantIds(Category $category): array
    {
        $byParent = Category::query()->get(['id', 'parent_id'])->groupBy(fn ($c) => (int) $c->parent_id);

        $ids = [$category->id];
        $queue = [$category->id];

        for ($depth = 0; $queue && $depth < self::MAX_DEPTH; $depth++) {
            $next = [];

            foreach ($queue as $parentId) {
                foreach ($byParent->get($parentId, []) as $child) {
                    $ids[] = $child->id;
                    $next[] = $child->id;
                }
            }

            $queue = $next;
        }

        return array_values(array_unique($ids));
    }

    public function build(): array
    {
        $categories = Category::query()->orderBy('name')->get();

        $counts = Product::query()
            ->where('is_active', true)
            ->where('is_published', true)
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->selectRaw('category_id, COUNT(*) AS aggregate')
            ->pluck('aggregate', 'category_id');

        return $this->branch($categories->groupBy(fn ($c) => (int) $c->parent_id), $counts, 0, 0);
    }

    private function branch(Collection $byParent, Collection $counts, int $parentId, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $nodes = [];

        foreach ($byParent->get($parentId, []) as $category) {
            $children = $this->branch($byParent, $counts, $category->id, $depth + 1);
            $count = (int) ($counts[$category->id] ?? 0) + array_sum(array_column($children, 'count'));

            if ($count === 0) {
                continue;
            }

            $nodes[] = [
                'id'       => $category->id,
                'name'     => $category->name,
                'url'      => route('catalog.show', $category->slug, false),
                // Колонки image у категорий пока нет — подхватится, когда появится
                'image'    => $category->image ? asset('storage/'.$category->image) : null,
                'count'    => $count,
                'children' => $children,
            ];
        }

        return $nodes;
    }
}
