<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Порядок категорий среди соседей (мегаменю, /catalog, плитки главной, чипы и плитки подкатегорий,
 * список в админке). Режим — настройка «Порядок категорий» (Setting SETTING):
 *  - count  — по количеству опубликованных товаров с подкатегориями, больше — выше; категории,
 *             закреплённые в админке (↑ / ↓, categories.is_pinned), идут первыми в своём порядке
 *             (sort_order); при равенстве — по названию;
 *  - manual — только вручную: sort_order, затем название (закрепление не важно);
 *  - alpha  — по алфавиту.
 * Витрина берёт порядок из дерева меню (CatalogMenu, кэш); кэш сбрасывается при изменении категорий,
 * после импорта и обмена номенклатуры и ночью (catalog:menu-refresh).
 */
class CategoryOrder
{
    public const SETTING = 'catalog_category_order';

    public const COUNT = 'count';

    public const MANUAL = 'manual';

    public const ALPHA = 'alpha';

    public const MODES = [
        self::COUNT => 'По количеству товаров (закреплённые — первыми)',
        self::MANUAL => 'Только вручную',
        self::ALPHA => 'По алфавиту',
    ];

    public static function mode(): string
    {
        $mode = Setting::value(self::SETTING);

        return isset(self::MODES[$mode]) ? $mode : self::COUNT;
    }

    /**
     * Соседи по порядку режима.
     *
     * @template T
     *
     * @param  iterable<T>  $items
     * @param  callable(T): Category  $category  категория элемента
     * @param  callable(T): int  $count  товаров в ней (с подкатегориями)
     * @return list<T>
     */
    public static function sort(iterable $items, callable $category, callable $count, ?string $mode = null): array
    {
        $mode ??= self::mode();
        $list = is_array($items) ? array_values($items) : collect($items)->values()->all();
        $name = fn ($item) => mb_strtolower((string) $category($item)->name);

        usort($list, function ($a, $b) use ($mode, $category, $count, $name) {
            $ca = $category($a);
            $cb = $category($b);

            return match ($mode) {
                self::ALPHA => [$name($a), $ca->id] <=> [$name($b), $cb->id],
                self::MANUAL => [(int) $ca->sort_order, $name($a), $ca->id] <=> [(int) $cb->sort_order, $name($b), $cb->id],
                // Закреплённые — первыми, по sort_order; остальные — по числу товаров
                default => (bool) $ca->is_pinned !== (bool) $cb->is_pinned
                    ? ($ca->is_pinned ? -1 : 1)
                    : ($ca->is_pinned
                        ? [(int) $ca->sort_order, $name($a), $ca->id] <=> [(int) $cb->sort_order, $name($b), $cb->id]
                        : [-$count($a), $name($a), $ca->id] <=> [-$count($b), $name($b), $cb->id]),
            };
        });

        return $list;
    }

    /**
     * Опубликованных товаров в каждой категории вместе с подкатегориями.
     *
     * @param  Collection<int, Category>  $categories  все категории (id, parent_id)
     * @return array<int, int>
     */
    public static function counts(Collection $categories): array
    {
        $direct = Product::query()
            ->where('is_active', true)
            ->where('is_published', true)
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->selectRaw('category_id, COUNT(*) AS aggregate')
            ->pluck('aggregate', 'category_id');

        $children = $categories->groupBy(fn ($c) => (int) $c->parent_id);
        $total = [];

        $sum = function (int $id, int $depth = 0) use (&$sum, &$total, $children, $direct): int {
            if (isset($total[$id])) {
                return $total[$id];
            }

            $count = (int) ($direct[$id] ?? 0);
            if ($depth < 10) {
                foreach ($children->get($id, []) as $child) {
                    $count += $sum($child->id, $depth + 1);
                }
            }

            return $total[$id] = $count;
        };

        foreach ($categories as $category) {
            $sum($category->id);
        }

        return $total;
    }
}
