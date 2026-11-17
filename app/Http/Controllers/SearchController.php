<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ArticleSearch;
use App\Services\CartSummary;
use App\Services\Catalog\KeyAttributes;
use App\Services\ClientCodes;
use App\Services\Pricing\PriceContext;
use App\Services\Search\ProductSearch;
use App\Support\OrderQuantity;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Поиск и подсказки в шапке (ProductSearch): слова — по основам и синонимам в названиях, бренде,
 * категории; подстрокой — артикул, sku (и описание на странице результатов), а у пользователя
 * организации — и коды товаров этой организации (ClientCodes; без учёта регистра — по code_key).
 */
class SearchController extends Controller
{
    /** Товаров на странице результатов */
    public const PER_PAGE = 24;

    /** Сортировки поиска; по цене — только после входа (гость цен не видит), как в каталоге */
    public const SORTS = [
        'relevance' => 'По релевантности',
        'price_asc' => 'Цена ↑',
        'price_desc' => 'Цена ↓',
    ];

    /** @return array<string, string> доступные сортировки */
    public static function sorts(): array
    {
        return prices_visible() ? self::SORTS : ['relevance' => self::SORTS['relevance']];
    }

    public function index(KeyAttributes $keyAttributes, CartSummary $cart, ClientCodes $codes, ArticleSearch $articles, ProductSearch $search)
    {
        $query = trim((string) request('q', ''));

        if ($query === '') {
            return view('search', [
                'query' => $query,
                'products' => new LengthAwarePaginator([], 0, self::PER_PAGE),
                'cartItems' => collect(),
            ]);
        }

        // Коды товаров — только своей организации; гость и пользователь без организации — без них
        $organizationId = $codes->organizationId();

        // Сортировка: по релевантности (ProductSearch) или по цене без НДС — без цены в конце;
        // у гостя sort=price_* не действует
        $sort = (string) request('sort', 'relevance');
        $sort = array_key_exists($sort, self::sorts()) ? $sort : 'relevance';

        $products = Product::query()
            ->with(['mainImage', 'category'])
            ->where('is_active', 1)
            ->where('is_published', true)
            // Слова — по основам и синонимам, артикул / код — подстрокой; порядок — ProductSearch
            ->tap(fn ($q) => $search->apply($q, $search->parse($query), $organizationId, withDescription: true))
            // Цена покупателя (тип цены его организации) — в колонке price и в сортировке
            ->withEffectivePrice()
            ->when($sort !== 'relevance', fn ($q) => $q->reorder()
                ->orderByRaw(($price = app(PriceContext::class)->expression($q)).' IS NULL')
                ->orderByRaw($price.($sort === 'price_desc' ? ' desc' : ' asc'))
                ->orderBy('products.id'))
            // Страницы по 24; q и прочие параметры — в ссылках пагинации
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Строка товара: ключевые характеристики — из фильтров категории каждого товара
        $keyAttributes->load($products->getCollection());
        // «Ваш код» в строках — коды организации одной выборкой
        $codes->load($products->getCollection());

        // «← Продолжить покупки» в корзине вернёт к этому поиску
        session([CatalogController::CONTINUE_URL => request()->getRequestUri()]);

        return view('search', [
            'query' => $query,
            'products' => $products,
            'sort' => $sort,
            'cartItems' => $cart->items(),
            // Ничего не нашлось — «Возможно, вы искали L4755-2» (артикул без учёта дефисов)
            'didYouMean' => $products->total() === 0 ? $articles->didYouMean($this->shown(), $query) : [],
        ]);
    }

    /** Товары, которые видит покупатель */
    private function shown()
    {
        return Product::query()->where('is_active', 1)->where('is_published', true);
    }

    public function suggest(ClientCodes $codes, ArticleSearch $articles, ProductSearch $search)
    {
        $query = trim((string) request('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['products' => [], 'categories' => []]);
        }

        $organizationId = $codes->organizationId();
        $parsed = $search->parse($query);

        $products = Product::query()
            ->where('is_active', 1)
            ->where('is_published', true)
            ->with('mainImage')
            ->tap(fn ($q) => $search->apply($q, $parsed, $organizationId))
            ->limit(5)
            ->get(['id', 'name', 'display_name', 'sku', 'article', 'brand', 'price', 'min_qty', 'qty_step', 'default_qty']);

        $codes->load($products);

        // Категории — по тем же основам и синонимам, с родителями (их пара сотен — сравнение в PHP);
        // сначала верхние уровни (короче путь)
        $categories = $search->categories(Category::query()->orderBy('id')->get(['id', 'parent_id', 'name', 'slug']), $parsed)
            ->sortBy(fn ($c) => substr_count($c->path, ' / '))
            ->take(5);

        // Цена — только после входа, как в каталоге и карточке товара
        $withPrices = prices_visible();

        return response()->json([
            'guest' => ! $withPrices,
            'did_you_mean' => $products->isEmpty() ? $articles->didYouMean($this->shown(), $query) : [],
            'products' => $products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->title(),
                // Артикул для покупателя (артикул, без него — sku); сам sku клиенту не отдаётся
                'article' => $p->displayArticle(),
                'brand' => $p->brand,
                // Код товара у организации пользователя (только своей) или null
                'client_code' => $codes->codeFor($p),
                // «Добавить по артикулу» в корзине подставляет количество товара по умолчанию
                // (допустимое: вверх до кратности, как в x-qty)
                'default_qty' => OrderQuantity::for($p)->roundUp((int) ($p->default_qty ?: 1)),
                // Цена — только после входа
                'price' => $withPrices && $p->price ? number_format($p->price, 2) : null,
                'image' => $p->mainImage?->url('thumb') ?? ProductImage::placeholderUrl(),
                'placeholder' => ProductImage::placeholderUrl(),
                'url' => route('product.show', $p->id),
            ]),
            'categories' => $categories->values()->map(fn ($c) => [
                // С родителями: «Смесители / Для кухни» — одно «Для кухни» неоднозначно
                'name' => $c->path,
                'url' => route('catalog.show', $c->slug),
            ]),
        ]);
    }
}
