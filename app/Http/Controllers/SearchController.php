<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    public function index()
    {
        $query = trim((string) request('q', ''));

        if ($query === '') {
            return view('search', [
                'query' => $query,
                'products' => collect(),
            ]);
        }

        $words = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);

        $products = Product::query()
            ->where('is_active', 1)
            ->where(function ($outer) use ($words) {
                foreach ($words as $word) {
                    $outer->where(function ($inner) use ($word) {
                        $like = '%' . mb_strtolower($word) . '%';

                        $inner->whereRaw('LOWER(sku) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(brand) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(description) LIKE ?', [$like]);
                    });
                }
            })
            ->orderByRaw('CASE WHEN LOWER(sku) = ? THEN 0 ELSE 1 END', [mb_strtolower($query)])
            ->orderBy('name')
            ->get();

        return view('search', [
            'query' => $query,
            'products' => $products,
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
            ->where(function ($outer) use ($words) {
                foreach ($words as $word) {
                    $outer->where(function ($inner) use ($word) {
                        $like = '%' . mb_strtolower($word) . '%';

                        $inner->whereRaw('LOWER(sku) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(brand) LIKE ?', [$like]);
                    });
                }
            })
            ->limit(5)
            ->get(['id', 'name', 'sku', 'price', 'image']);

        $categories = Category::query()
            ->where(function ($outer) use ($words) {
                foreach ($words as $word) {
                    $outer->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($word) . '%']);
                }
            })
            ->limit(5)
            ->get(['name', 'slug']);

        return response()->json([
            'products' => $products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'price' => $p->price ? number_format($p->price, 2) : null,
                'image' => $p->image ? asset('storage/' . $p->image) : null,
                'url' => route('product.show', $p->id),
            ]),
            'categories' => $categories->map(fn ($c) => [
                'name' => $c->name,
                'url' => route('catalog.show', $c->slug),
            ]),
        ]);
    }
}