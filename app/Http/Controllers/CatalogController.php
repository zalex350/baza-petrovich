<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\CartSummary;
use App\Services\Catalog\CatalogFilters;
use App\Services\Catalog\CatalogListing;
use App\Services\Catalog\KeyAttributes;
use App\Services\CatalogMenu;

class CatalogController extends Controller
{
    /** Ключ сессии: адрес последнего списка каталога с фильтрами */
    public const BACK_URL = 'catalog.back_url';

    public function index()
    {
        $parents = Category::whereNull('parent_id')
            ->with(['children' => function ($q) {
                $q->withCount(['products' => fn ($q2) => $q2->where('is_active', 1)])
                    ->orderBy('name');
            }])
            ->orderBy('name')
            ->get();

        return view('catalog_index', compact('parents'));
    }

    /**
     * JSON-дерево категорий для мобильного меню (из кэша CatalogMenu).
     */
    public function menu(CatalogMenu $menu)
    {
        return response()
            ->json($menu->tree(), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * Товары категории вместе со всеми вложенными подкатегориями — «весь раздел» из мегаменю
     * и мобильного меню — с фильтрами и сортировкой (SRS §3.9, §3.10). Всё состояние — в URL
     * (BR-029, BR-030); адрес списка запоминается для «← Вернуться к списку» в карточке (BR-084).
     */
    public function show($slug, CatalogMenu $menu, CatalogFilters $filters, KeyAttributes $keyAttributes, CartSummary $cart)
    {
        $category = Category::where('slug', $slug)->firstOrFail();

        $listing = new CatalogListing(
            $category,
            $menu->descendantIds($category),
            $filters,
            request()->query(),
            auth()->check(),
        );

        $baseUrl = '/catalog/'.$category->slug;

        session([self::BACK_URL => request()->getRequestUri()]);

        $products = $listing->products();

        // Строка товара: ключевые характеристики — из фильтров этой категории
        $keyAttributes->load($products->getCollection(), $keyAttributes->attributeIds($listing->filters));

        return view('catalog', [
            'category' => $category,
            'listing' => $listing,
            'products' => $products,
            'cartItems' => $cart->items(auth()->user()),
            'breadcrumbs' => $this->breadcrumbs($category),
            'facets' => $listing->facets(),
            'chips' => $listing->chips($baseUrl),
            'baseUrl' => $baseUrl,
        ]);
    }

    /**
     * Главная / Каталог / родители / раздел.
     *
     * @return list<array{label: string, url?: string}>
     */
    private function breadcrumbs(Category $category): array
    {
        $chain = [];

        for ($current = $category->parent, $guard = 0; $current && $guard < 20; $current = $current->parent, $guard++) {
            array_unshift($chain, ['label' => $current->name, 'url' => '/catalog/'.$current->slug]);
        }

        return [
            ['label' => 'Главная', 'url' => '/'],
            ['label' => 'Каталог', 'url' => route('catalog.index')],
            ...$chain,
            ['label' => $category->name],
        ];
    }

    /**
     * Куда ведёт «← Вернуться к списку» из карточки: последний открытый список каталога,
     * иначе — категория товара.
     */
    public static function backUrl(?Category $category): string
    {
        $url = session(self::BACK_URL);

        if (is_string($url) && str_starts_with($url, '/catalog/')) {
            return $url;
        }

        return $category ? '/catalog/'.$category->slug : route('catalog.index');
    }
}
