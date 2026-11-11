<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductClientCode;
use App\Models\ProductImage;
use App\Services\ArticleSearch;
use App\Services\CartSummary;
use App\Services\Catalog\KeyAttributes;
use App\Services\ClientCodes;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Поиск и подсказки в шапке: по артикулу, названию, бренду (и описанию на странице
 * результатов), а у пользователя организации — и по кодам товаров этой организации
 * (ClientCodes; без учёта регистра — по code_key).
 */
class SearchController extends Controller
{
    /** Товаров на странице результатов */
    public const PER_PAGE = 24;

    public function index(KeyAttributes $keyAttributes, CartSummary $cart, ClientCodes $codes, ArticleSearch $articles)
    {
        $query = trim((string) request('q', ''));

        if ($query === '') {
            return view('search', [
                'query' => $query,
                'products' => new LengthAwarePaginator([], 0, self::PER_PAGE),
                'cartItems' => collect(),
            ]);
        }

        $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        // Коды товаров — только своей организации; гость и пользователь без организации — без них
        $organizationId = $codes->organizationId();

        $products = Product::query()
            ->with(['mainImage', 'category'])
            ->where('is_active', 1)
            ->where('is_published', true)
            ->where(function ($outer) use ($words, $codes, $organizationId) {
                foreach ($words as $word) {
                    $outer->where(function ($inner) use ($word, $codes, $organizationId) {
                        $like = '%'.mb_strtolower($word).'%';

                        $inner->whereRaw('LOWER(sku) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(article) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(display_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(brand) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(description) LIKE ?', [$like]);

                        if ($organizationId) {
                            $inner->orWhere(fn ($code) => $codes->whereCodeContains($code, $word, $organizationId));
                        }
                    });
                }
            })
            // Сначала точное совпадение с кодом клиента, затем с артикулом
            ->tap(fn ($q) => $this->exactFirst($q, $query, $organizationId))
            ->orderBy('name')
            ->orderBy('id')
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
            'cartItems' => $cart->items(auth()->user()),
            // Ничего не нашлось — «Возможно, вы искали L4755-2» (артикул без учёта дефисов)
            'didYouMean' => $products->total() === 0 ? $articles->didYouMean($this->shown(), $query) : [],
        ]);
    }

    /** Товары, которые видит покупатель */
    private function shown()
    {
        return Product::query()->where('is_active', 1)->where('is_published', true);
    }

    public function suggest(ClientCodes $codes, ArticleSearch $articles)
    {
        $query = trim((string) request('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['products' => [], 'categories' => []]);
        }

        $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        $organizationId = $codes->organizationId();

        $products = Product::query()
            ->where('is_active', 1)
            ->where('is_published', true)
            ->where(function ($outer) use ($words, $codes, $organizationId) {
                foreach ($words as $word) {
                    $outer->where(function ($inner) use ($word, $codes, $organizationId) {
                        $like = '%'.mb_strtolower($word).'%';

                        $inner->whereRaw('LOWER(sku) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(article) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(display_name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(brand) LIKE ?', [$like]);

                        if ($organizationId) {
                            $inner->orWhere(fn ($code) => $codes->whereCodeContains($code, $word, $organizationId));
                        }
                    });
                }
            })
            ->with('mainImage')
            ->tap(fn ($q) => $this->exactFirst($q, $query, $organizationId))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(5)
            ->get(['id', 'name', 'display_name', 'sku', 'article', 'brand', 'price', 'default_qty']);

        $codes->load($products);

        $categories = Category::query()
            ->where(function ($outer) use ($words) {
                foreach ($words as $word) {
                    $outer->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($word).'%']);
                }
            })
            ->limit(5)
            ->get(['name', 'slug']);

        // Цена — только после входа, как в каталоге и карточке товара
        $withPrices = auth()->check();

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
                'default_qty' => (int) ($p->default_qty ?: 1),
                // Цена — только после входа
                'price' => $withPrices && $p->price ? number_format($p->price, 2) : null,
                'image' => $p->mainImage?->url('thumb') ?? ProductImage::placeholderUrl(),
                'placeholder' => ProductImage::placeholderUrl(),
                'url' => route('product.show', $p->id),
            ]),
            'categories' => $categories->map(fn ($c) => [
                'name' => $c->name,
                'url' => route('catalog.show', $c->slug),
            ]),
        ]);
    }

    /**
     * Порядок: точное совпадение запроса с кодом организации (без учёта регистра — code_key),
     * затем с артикулом, затем остальные.
     */
    private function exactFirst($query, string $text, ?int $organizationId): void
    {
        if ($organizationId) {
            $query->orderByRaw(
                'CASE WHEN EXISTS (SELECT 1 FROM product_client_codes WHERE product_client_codes.product_id = products.id'
                .' AND product_client_codes.organization_id = ? AND product_client_codes.code_key = ?) THEN 0 ELSE 1 END',
                [$organizationId, ProductClientCode::key($text)]
            );
        }

        $text = mb_strtolower(trim($text));
        $query->orderByRaw('CASE WHEN LOWER(TRIM(article)) = ? OR LOWER(TRIM(sku)) = ? THEN 0 ELSE 1 END', [$text, $text]);
    }
}
