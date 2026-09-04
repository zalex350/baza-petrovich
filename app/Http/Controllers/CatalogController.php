<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;

class CatalogController extends Controller
{
    public function show($slug)
    {
        $category = Category::where(
            'slug',
            $slug
        )->firstOrFail();

        $products = Product::with('category')
            ->where('category_id', $category->id)
            ->where('is_active', 1)
            ->latest()
            ->get();

        return view('catalog', compact(
            'category',
            'products'
        ));
    }
}