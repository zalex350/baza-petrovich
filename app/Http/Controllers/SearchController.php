<?php

namespace App\Http\Controllers;

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
}