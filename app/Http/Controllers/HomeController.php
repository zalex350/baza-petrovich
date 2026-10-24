<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Services\CartSummary;
use App\Services\Catalog\KeyAttributes;

class HomeController extends Controller
{
    public function index(KeyAttributes $keyAttributes, CartSummary $cart)
    {
        $categories = Category::whereNull('parent_id')
            ->with('children')
            ->get();

        $featuredProducts = Product::with(['mainImage', 'category'])
            ->where('is_active', 1)
            ->where('is_published', true)
            ->latest()
            ->take(4)
            ->get();

        // Строки товаров (x-product-row): характеристики — из фильтров категории каждого товара
        $keyAttributes->load($featuredProducts);

        return view('home', [
            'categories' => $categories,
            'featuredProducts' => $featuredProducts,
            'cartItems' => $cart->items(auth()->user()),
        ]);
    }
}
