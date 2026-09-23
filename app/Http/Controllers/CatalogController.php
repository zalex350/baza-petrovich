<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;

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

    public function show($slug)
    {
        $category = Category::where(
            'slug',
            $slug
        )->firstOrFail();

        $products = Product::with('category')
            ->where('category_id', $category->id)
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