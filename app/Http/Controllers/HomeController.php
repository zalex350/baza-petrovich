<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Product;
use App\Models\Review;
use App\Models\Work;
use App\Services\CartSummary;
use App\Services\Catalog\KeyAttributes;
use App\Services\CatalogMenu;
use App\Services\CategoryTiles;
use App\Services\ClientCodes;

/**
 * Главная: слайдер баннеров (без баннеров — прежний первый экран), карусели «Акции»,
 * «Новинки», «Хиты» (по CAROUSEL товаров, ссылка «Все»), «Популярные товары», категории.
 * Пустой блок не выводится.
 */
class HomeController extends Controller
{
    public const CAROUSEL = 10;

    public function index(KeyAttributes $keyAttributes, CartSummary $cart, CatalogMenu $menu, CategoryTiles $tiles)
    {
        // Плитки разделов — из дерева меню (кэш): видимые разделы с товарами в порядке CategoryOrder; фото — CategoryTiles, без фото — иконка
        $categories = $menu->tree();

        // Цена — покупателя (тип цены организации), одним join
        $shown = fn () => Product::with(['mainImage', 'category'])
            ->withEffectivePrice()
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

        // «Ваш код» в каруселях и строках — коды организации клиента одной выборкой
        app(ClientCodes::class)->load(collect($carousels)->pluck('products')->flatten(1)->concat($featuredProducts));

        return view('home', [
            'banners' => Banner::shown()->get(),
            'carousels' => $carousels,
            'categories' => $categories,
            'tiles' => $tiles->forTree($categories),
            'featuredProducts' => $featuredProducts,
            'cartItems' => $cart->items(),
            'works' => Work::published()->ordered()->with('cover')->withCount('images')->limit(6)->get(),
            'reviews' => Review::published()->ordered()->with('work')->limit(4)->get(),
        ]);
    }
}
