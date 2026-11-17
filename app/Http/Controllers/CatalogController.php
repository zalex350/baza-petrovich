<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\CartSummary;
use App\Services\Catalog\CatalogFilters;
use App\Services\Catalog\CatalogListing;
use App\Services\Catalog\KeyAttributes;
use App\Services\CatalogMenu;
use App\Services\CategoryTiles;
use App\Services\ClientCodes;

class CatalogController extends Controller
{
    /** Ключ сессии: адрес последнего списка каталога с фильтрами */
    public const BACK_URL = 'catalog.back_url';

    /** Ключ сессии: последний открытый список — категория или поиск («← Продолжить покупки» в корзине) */
    public const CONTINUE_URL = 'shop.continue_url';

    /** Подборки по флагу товара: адрес → [флаг, заголовок] */
    public const COLLECTIONS = [
        'promo' => ['is_promo', 'Акции'],
        'new' => ['is_new', 'Новинки'],
        'hits' => ['is_hit', 'Хиты'],
    ];

    /** Вид списка: list (строки, по умолчанию) | grid (плитка); параметр URL view, выбор — в localStorage */
    public static function view(): string
    {
        return request('view') === 'grid' ? 'grid' : 'list';
    }

    /**
     * Все разделы: плитки (фото типичного товара или иконка) с подразделами — из дерева меню (кэш):
     * порядок и счётчики как в мегаменю (CategoryOrder).
     */
    public function index(CatalogMenu $menu, CategoryTiles $tiles)
    {
        $sections = $menu->tree();

        return view('catalog_index', ['sections' => $sections, 'tiles' => $tiles->forTree($sections)]);
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
            prices_visible(),
        );

        // Подкатегории над списком: прямые потомки из дерева меню (кэш, без запросов);
        // плитки с картинкой — если картинка есть хотя бы у одной, иначе чипы
        $subcategories = $menu->node($category->id)['children'] ?? [];

        return $this->listingView($listing, '/catalog/'.$category->slug, $keyAttributes, $cart, [
            'category' => $category,
            'breadcrumbs' => $this->breadcrumbs($category, $menu),
            'subcategories' => $subcategories,
            'subcategoryTiles' => collect($subcategories)->contains(fn ($sub) => ! empty($sub['image'])),
        ], $keyAttributes->attributeIds($listing->filters));
    }

    /**
     * «Акции» (/promo) и «Новинки» (/new): каталожный список товаров с флагом — фильтры,
     * сортировка, вид, «Показать ещё» — как в категории.
     */
    public function collection(string $key, CatalogFilters $filters, KeyAttributes $keyAttributes, CartSummary $cart)
    {
        [$flag, $title] = self::COLLECTIONS[$key] ?? abort(404);

        // Страница без категории: заголовок — из «категории»-заглушки, фильтры — по умолчанию
        $category = new Category(['name' => $title, 'slug' => $key]);

        $listing = new CatalogListing($category, null, $filters, request()->query(), prices_visible(), $flag);

        return $this->listingView($listing, '/'.$key, $keyAttributes, $cart, [
            'category' => $category,
            'breadcrumbs' => [['label' => 'Главная', 'url' => '/'], ['label' => $title]],
            'subcategories' => [],
            'subcategoryTiles' => false,
        ]);
    }

    /**
     * Общий рендер списка (catalog.blade): адрес запоминается для «← Вернуться к списку»
     * и «← Продолжить покупки»; $attributeIds — ключевые характеристики строк (null — по категории товара).
     */
    private function listingView(CatalogListing $listing, string $baseUrl, KeyAttributes $keyAttributes, CartSummary $cart, array $data, ?array $attributeIds = null)
    {
        session([
            self::BACK_URL => request()->getRequestUri(),
            self::CONTINUE_URL => request()->getRequestUri(),
        ]);

        $products = $listing->products();

        $keyAttributes->load($products->getCollection(), $attributeIds);
        // «Ваш код» в строках и плитках — коды организации клиента одной выборкой
        app(ClientCodes::class)->load($products->getCollection());

        return view('catalog', $data + [
            'listing' => $listing,
            'products' => $products,
            'cartItems' => $cart->items(auth()->user()),
            'facets' => $listing->facets(),
            'chips' => $listing->chips($baseUrl),
            'baseUrl' => $baseUrl,
            'view' => self::view(),
        ]);
    }

    /**
     * Адрес списка витрины (категория, подборка или поиск) — для возвратов; не открытый редирект.
     */
    private static function isListUrl(string $url, bool $withSearch = false): bool
    {
        return str_starts_with($url, '/catalog/')
            || preg_match('#^/(promo|new|hits)(\?|$)#', $url) === 1
            || ($withSearch && str_starts_with($url, '/search?'));
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

        if (is_string($url) && self::isListUrl($url, withSearch: true)) {
            return $url;
        }

        return route('catalog.index', [], false);
    }

    /**
     * Последний открытый список каталога с фильтрами — для возврата из карточки по крошкам, или null.
     */
    public static function backUrl(): ?string
    {
        $url = session(self::BACK_URL);

        return is_string($url) && self::isListUrl($url) ? $url : null;
    }
}
