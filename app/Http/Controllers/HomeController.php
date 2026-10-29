<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Services\CartSummary;
use App\Services\Catalog\KeyAttributes;

/**
 * Главная: слайдер баннеров (без баннеров — прежний первый экран), карусели «Акции»,
 * «Новинки», «Хиты» (по CAROUSEL товаров, ссылка «Все»), «Популярные товары», категории.
 * Пустой блок не выводится.
 */
class HomeController extends Controller
{
    public const CAROUSEL = 10;

    public function index(KeyAttributes $keyAttributes, CartSummary $cart)
    {
        $categories = Category::whereNull('parent_id')
            ->visible()
            ->with('children')
            ->get();

        $shown = fn () => Product::with(['mainImage', 'category'])
            ->where('is_active', true)
            ->where('is_published', true);

        $carousels = [
            ['title' => 'Акции', 'url' => '/promo', 'products' => $shown()->where('is_promo', true)->latest()->orderByDesc('id')->take(self::CAROUSEL)->get()],
            ['title' => 'Новинки', 'url' => '/new', 'products' => $shown()->where('is_new', true)->latest()->orderByDesc('id')->take(self::CAROUSEL)->get()],
            ['title' => 'Хиты', 'url' => '/hits', 'products' => $shown()->where('is_hit', true)->latest()->orderByDesc('id')->take(self::CAROUSEL)->get()],
        ];

        $featuredProducts = $shown()->latest()->take(4)->get();

        // Строки товаров (x-product-row): характеристики — из фильтров категории каждого товара
        $keyAttributes->load($featuredProducts);

        return view('home', [
            'banners' => Banner::shown()->get(),
            'carousels' => $carousels,
            'categories' => $categories,
            'featuredProducts' => $featuredProducts,
            'cartItems' => $cart->items(auth()->user()),
        ]);
    }
}
