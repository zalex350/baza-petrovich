<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\CategoryOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Дерево категорий для меню каталога (мегаменю, мобильное меню, /catalog, плитки главной,
 * подкатегории на странице раздела).
 *
 * Два запроса на всё дерево: категории + счётчики товаров по category_id (и настройки — из кэша).
 * Счётчик категории включает товары всех вложенных подкатегорий; учитываются
 * только активные и опубликованные товары. Пустые категории в дерево не попадают.
 * Порядок соседей — CategoryOrder (по количеству товаров, закреплённые первыми; вручную; по алфавиту).
 * Результат кэшируется; кэш сбрасывает CatalogMenuObserver, импорт и обмен номенклатуры,
 * смена режима порядка и ночная команда catalog:menu-refresh.
 */
class CatalogMenu
{
    public const CACHE_KEY = 'catalog_menu.tree';

    /** Защита от циклов в parent_id */
    private const MAX_DEPTH = 10;

    /** Дерево, уже прочитанное из кэша этим экземпляром (путь, узел и меню — одним чтением) */
    private ?array $tree = null;

    /**
     * @return list<array{id: int, name: string, url: string, image: ?string, count: int, children: list<array>}>
     */
    public function tree(): array
    {
        return $this->tree ??= Cache::remember(self::CACHE_KEY, config('shop.catalog_menu_ttl'), fn () => $this->build());
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Путь к категории по дереву меню (из кэша, без запросов): корень … сама категория.
     * Пустая категория в дерево не попадает — тогда пустой список.
     *
     * @return list<array{id: int, name: string, url: string, count: int, children: list<array>}>
     */
    public function path(int $id): array
    {
        return $this->find($this->tree(), $id) ?? [];
    }

    /**
     * Цепочка разделов до категории включительно — ссылками: из дерева меню (кэш);
     * если категории в нём нет (пустая) — по parent_id.
     *
     * @return list<array{label: string, url: string}>
     */
    public function chain(Category $category): array
    {
        if ($path = $this->path($category->id)) {
            return array_map(fn ($node) => ['label' => $node['name'], 'url' => $node['url']], $path);
        }

        $chain = [['label' => $category->name, 'url' => route('catalog.show', $category->slug, false)]];

        for ($current = $category->parent, $guard = 0; $current && $guard < self::MAX_DEPTH; $current = $current->parent, $guard++) {
            array_unshift($chain, ['label' => $current->name, 'url' => route('catalog.show', $current->slug, false)]);
        }

        return $chain;
    }

    /**
     * Узел категории в дереве меню (с прямыми подкатегориями и счётчиками) или null.
     */
    public function node(int $id): ?array
    {
        $path = $this->path($id);

        return $path ? end($path) : null;
    }

    private function find(array $nodes, int $id, int $depth = 0): ?array
    {
        if ($depth >= self::MAX_DEPTH) {
            return null;
        }

        foreach ($nodes as $node) {
            if ($node['id'] === $id) {
                return [$node];
            }

            if ($found = $this->find($node['children'], $id, $depth + 1)) {
                return [$node, ...$found];
            }
        }

        return null;
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
        // Служебные категории («Без категории») в меню не показываются
        $categories = Category::query()->visible()->ordered()->get();

        $counts = Product::query()
            ->where('is_active', true)
            ->where('is_published', true)
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->selectRaw('category_id, COUNT(*) AS aggregate')
            ->pluck('aggregate', 'category_id');

        return $this->branch($categories->groupBy(fn ($c) => (int) $c->parent_id), $counts, 0, 0, CategoryOrder::mode());
    }

    /**
     * Узлы уровня — в порядке CategoryOrder (по количеству товаров с закреплёнными первыми,
     * вручную или по алфавиту); пустые (без опубликованных товаров) не показываются.
     */
    private function branch(Collection $byParent, Collection $counts, int $parentId, int $depth, string $mode): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $nodes = [];

        foreach ($byParent->get($parentId, []) as $category) {
            $children = $this->branch($byParent, $counts, $category->id, $depth + 1, $mode);
            $count = (int) ($counts[$category->id] ?? 0) + array_sum(array_column($children, 'count'));

            if ($count === 0) {
                continue;
            }

            $nodes[] = [$category, [
                'id' => $category->id,
                'name' => $category->name,
                'url' => route('catalog.show', $category->slug, false),
                // Картинка категории (админка «Категории») — плитки подкатегорий
                'image' => $category->imageUrl(),
                // Иконка (App\Services\CategoryIcons): мегаменю, плитки
                'icon' => $category->icon,
                'icon_svg' => $category->icon === CategoryIcons::UPLOAD ? $category->icon_svg : null,
                'count' => $count,
                'children' => $children,
            ]];
        }

        $sorted = CategoryOrder::sort($nodes, fn ($pair) => $pair[0], fn ($pair) => $pair[1]['count'], $mode);

        return array_map(fn ($pair) => $pair[1], $sorted);
    }
}
