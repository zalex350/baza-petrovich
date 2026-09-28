<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Services\CatalogMenu;

class CatalogController extends Controller
{
    public function index()
    {
        $parents = Category::whereNull('parent_id')
            ->with(['children' => function ($q) {
                $q->withCount(['products' => fn($q2) => $q2->where('is_active', 1)])
                  ->orderBy('name');
            }])
            ->orderBy('name')
            ->get();

        return view('catalog_index', compact('parents'));
    }

    /**
     * Товары категории вместе со всеми вложенными подкатегориями —
     * «весь раздел» из мегаменю и мобильного меню.
     */
    public function show($slug, CatalogMenu $menu)
    {
        $category = Category::where(
            'slug',
            $slug
        )->firstOrFail();

        $products = Product::with('category')
            ->whereIn('category_id', $menu->descendantIds($category))
            ->where('is_active', 1)
            ->where('is_published', true)
            ->latest()
            ->paginate(24);

        return view('catalog', compact(
            'category',
            'products'
        ));
    }
}