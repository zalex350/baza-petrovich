<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\CartSummary;
use App\Services\Catalog\KeyAttributes;
use Illuminate\Pagination\LengthAwarePaginator;

class SearchController extends Controller
{
    /** Товаров на странице результатов */
    public const PER_PAGE = 24;

    public function index(KeyAttributes $keyAttributes, CartSummary $cart)
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

        $products = Product::query()
            ->with(['mainImage', 'category'])
            ->where('is_active', 1)
            ->where('is_published', true)
            ->where(function ($outer) use ($words) {
                foreach ($words as $word) {
                    $outer->where(function ($inner) use ($word) {
                        $like = '%'.mb_strtolower($word).'%';

                        $inner->whereRaw('LOWER(sku) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(brand) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(description) LIKE ?', [$like]);
                    });
                }
            })
            ->orderByRaw('CASE WHEN LOWER(sku) = ? THEN 0 ELSE 1 END', [mb_strtolower($query)])
            ->orderBy('name')
            ->orderBy('id')
            // Страницы по 24; q и прочие параметры — в ссылках пагинации
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Строка товара: ключевые характеристики — из фильтров категории каждого товара
        $keyAttributes->load($products->getCollection());

        // «← Продолжить покупки» в корзине вернёт к этому поиску
        session([CatalogController::CONTINUE_URL => request()->getRequestUri()]);

        return view('search', [
            'query' => $query,
            'products' => $products,
            'cartItems' => $cart->items(auth()->user()),
        ]);
    }

    public function suggest()
    {
        $query = trim((string) request('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['products' => [], 'categories' => []]);
        }

        $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);

        $products = Product::query()
            ->where('is_active', 1)
            ->where('is_published', true)
            ->where(function ($outer) use ($words) {
                foreach ($words as $word) {
                    $outer->where(function ($inner) use ($word) {
                        $like = '%'.mb_strtolower($word).'%';

                        $inner->whereRaw('LOWER(sku) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(brand) LIKE ?', [$like]);
                    });
                }
            })
            ->with('mainImage')
            ->limit(5)
            ->get(['id', 'name', 'display_name', 'sku', 'price']);

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
            'products' => $products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->display_name ?: $p->name,
                'sku' => $p->sku,
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
}
