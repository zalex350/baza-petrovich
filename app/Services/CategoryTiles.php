<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Фото на плитке раздела (главная, /catalog): по порядку —
 *  1. своя картинка категории (админка, «Картинка»; та же — у плиток подкатегорий);
 *  2. товар, выбранный в админке (categories.tile_product_id) — если он на витрине и с фото;
 *  3. только в режиме «автоматически» (настройка «Фото на плитках разделов», Setting SETTING) —
 *     самый продаваемый товар раздела (с подкатегориями) с фото и в наличии: больше всего штук
 *     в заказах (кроме отменённых), при равенстве — больше остаток, затем новее;
 *  4. нет — null: плитка с иконкой категории. По умолчанию режим «только выбранные вручную».
 * Фото-заглушки (product_images.is_placeholder) не в счёт — Product::mainImage их пропускает.
 * forTree() — для всех разделов меню сразу (несколько запросов, кэш CACHE_KEY на catalog_menu_ttl;
 * сбрасывается вместе с меню — CatalogMenu::flush). url() — для одной категории (админка).
 */
class CategoryTiles
{
    public const CACHE_KEY = 'catalog_menu.tiles';

    public const SETTING = 'catalog_tile_photos';

    public const MANUAL = 'manual';

    public const AUTO = 'auto';

    public const MODES = [
        self::MANUAL => 'Только выбранные вручную',
        self::AUTO => 'Автоматически — самый продаваемый товар',
    ];

    public static function mode(): string
    {
        $mode = Setting::value(self::SETTING);

        return isset(self::MODES[$mode]) ? $mode : self::MANUAL;
    }

    /** Товаров на выбор в админке */
    public const CANDIDATES = 12;

    /** Размер фото товара на плитке (ProductImage::SIZES) */
    public const SIZE = 'medium';

    /**
     * Фото плиток разделов верхнего уровня: id раздела => URL или null.
     *
     * @param  list<array>  $tree  CatalogMenu::tree()
     * @return array<int, ?string>
     */
    public function forTree(array $tree): array
    {
        return Cache::remember(self::CACHE_KEY, config('shop.catalog_menu_ttl'), fn () => $this->build($tree));
    }

    /**
     * @param  list<array>  $tree
     * @return array<int, ?string>
     */
    private function build(array $tree): array
    {
        if ($tree === []) {
            return [];
        }

        $categories = Category::query()->whereIn('id', array_column($tree, 'id'))->get(['id', 'image', 'tile_product_id'])->keyBy('id');
        $chosen = $this->shown()->whereIn('products.id', $categories->pluck('tile_product_id')->filter()->all())->get()->keyBy('id');

        $urls = [];
        $rootOf = [];

        foreach ($tree as $node) {
            $category = $categories->get($node['id']);
            $product = $category?->tile_product_id ? $chosen->get($category->tile_product_id) : null;

            $urls[$node['id']] = $category?->imageUrl() ?? $product?->mainImage?->url(self::SIZE);

            if ($urls[$node['id']] === null) {
                foreach ([$node['id'], ...self::ids($node['children'])] as $id) {
                    $rootOf[$id] = $node['id'];
                }
            }
        }

        if ($rootOf === [] || self::mode() !== self::AUTO) {
            return $urls;
        }

        // Самый популярный с фото и в наличии — одной выборкой на все разделы, первый по порядку на раздел
        $best = [];
        foreach ($this->popular(array_keys($rootOf))->toBase()->get(['products.id', 'products.category_id']) as $row) {
            $best[$rootOf[$row->category_id]] ??= $row->id;
        }

        // Фото — как Product::mainImage: основное настоящее первым, заглушки пропускаются
        $images = ProductImage::query()->whereIn('product_id', $best)->where('is_placeholder', false)
            ->orderByDesc('is_main')->orderBy('sort_order')->orderBy('id')
            ->get()->unique('product_id')->keyBy('product_id');

        foreach ($best as $rootId => $productId) {
            $urls[$rootId] = $images->get($productId)?->url(self::SIZE);
        }

        return $urls;
    }

    /**
     * URL фото плитки одной категории или null.
     *
     * @param  list<int>  $categoryIds  категория и её подкатегории
     */
    public function url(Category $category, array $categoryIds): ?string
    {
        if ($image = $category->imageUrl()) {
            return $image;
        }

        $product = $this->chosen($category)
            ?? (self::mode() === self::AUTO ? $this->candidates($categoryIds, 1)->first() : null);

        return $product?->mainImage?->url(self::SIZE);
    }

    /** Выбранный в админке товар, если он на витрине и с фото */
    public function chosen(Category $category): ?Product
    {
        if (! $category->tile_product_id) {
            return null;
        }

        return $this->shown()->whereKey($category->tile_product_id)->first();
    }

    /**
     * Популярные товары раздела с фото и в наличии (автоматический выбор и список в админке).
     *
     * @param  list<int>  $categoryIds
     * @return Collection<int, Product>
     */
    public function candidates(array $categoryIds, int $limit = self::CANDIDATES): Collection
    {
        if ($categoryIds === []) {
            return collect();
        }

        return $this->popular($categoryIds)->with('mainImage')->select('products.*')->limit($limit)->get();
    }

    /** Товары категорий с фото и в наличии, по популярности, остатку и новизне */
    private function popular(array $categoryIds)
    {
        return $this->shown()
            ->whereIn('products.category_id', $categoryIds)
            ->where('products.stock', '>', 0)
            ->leftJoinSub($this->popularity(), 'pop', 'pop.product_id', '=', 'products.id')
            ->orderByRaw('COALESCE(pop.units, 0) desc')
            ->orderByDesc('products.stock')
            ->orderByDesc('products.id');
    }

    /** Товары витрины с главным фото */
    private function shown()
    {
        return Product::query()
            ->with('mainImage')
            ->where('products.is_active', true)
            ->where('products.is_published', true)
            ->whereHas('mainImage');
    }

    private function popularity()
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotNull('order_items.product_id')
            ->where('orders.status', '<>', OrderStatus::Cancelled->value)
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as units');
    }

    /**
     * ID узлов поддерева меню (без корня).
     *
     * @return list<int>
     */
    private static function ids(array $nodes): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            $ids[] = $node['id'];
            array_push($ids, ...self::ids($node['children']));
        }

        return $ids;
    }
}
