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

    /** Ключ сессии: последний открытый список — категория или поиск («← Продолжить покупки» в корзине) */
    public const CONTINUE_URL = 'shop.continue_url';

    public function index()
    {
        $parents = Category::whereNull('parent_id')
            ->visible()
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
        // Служебная категория («Без категории») — не страница каталога: товары находятся поиском
        $category = Category::where('slug', $slug)->visible()->firstOrFail();

        $listing = new CatalogListing(
            $category,
            $menu->descendantIds($category),
            $filters,
            request()->query(),
            auth()->check(),
        );

        $baseUrl = '/catalog/'.$category->slug;

        session([
            self::BACK_URL => request()->getRequestUri(),
            self::CONTINUE_URL => request()->getRequestUri(),
        ]);

        $products = $listing->products();

        // Строка товара: ключевые характеристики — из фильтров этой категории
        $keyAttributes->load($products->getCollection(), $keyAttributes->attributeIds($listing->filters));

        return view('catalog', [
            'category' => $category,
            'listing' => $listing,
            'products' => $products,
            'cartItems' => $cart->items(auth()->user()),
            'breadcrumbs' => $this->breadcrumbs($category, $menu),
            // Чипы подкатегорий над списком: прямые потомки из дерева меню (кэш, без запросов)
            'subcategories' => $menu->node($category->id)['children'] ?? [],
            'facets' => $listing->facets(),
            'chips' => $listing->chips($baseUrl),
            'baseUrl' => $baseUrl,
        ]);
    }

    /**
     * Главная / Каталог / родители (ссылки на их страницы — товары всего раздела) / раздел.
     *
     * @return list<array{label: string, url?: string}>
     */
    private function breadcrumbs(Category $category, CatalogMenu $menu): array
    {
        $chain = $menu->chain($category);
        $chain[count($chain) - 1] = ['label' => $category->name];

        return [
            ['label' => 'Главная', 'url' => '/'],
            ['label' => 'Каталог', 'url' => route('catalog.index')],
            ...$chain,
        ];
    }

    /**
     * Куда ведёт «← Продолжить покупки» из корзины: последняя открытая категория или поиск,
     * иначе — каталог. Только адреса витрины — не открытый редирект.
     */
    public static function continueUrl(): string
    {
        $url = session(self::CONTINUE_URL);

        if (is_string($url) && (str_starts_with($url, '/catalog/') || str_starts_with($url, '/search?'))) {
            return $url;
        }

        return route('catalog.index', [], false);
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
