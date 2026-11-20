<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ProductController;
use App\Models\Product;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Catalog Routes
|--------------------------------------------------------------------------
*/

Route::get(
    '/catalog',
    [CatalogController::class, 'index']
)->name('catalog.index');

// Дерево категорий для мобильного меню (drill-down), до /catalog/{slug}
Route::get(
    '/catalog/menu.json',
    [CatalogController::class, 'menu']
)->name('catalog.menu');

Route::get(
    '/catalog/{slug}',
    [CatalogController::class, 'show']
)->name('catalog.show');

// Карточка: /product/{slug}; /product/{id} — 301 на ЧПУ (ProductController::show)
Route::get(
    '/product/{key}',
    [ProductController::class, 'show']
)->where('key', '[^/]+')->name('product.show');

// Подборки по флагу товара: «Акции», «Новинки» — список с фильтрами, как в категории
foreach (array_keys(CatalogController::COLLECTIONS) as $key) {
    Route::get('/'.$key, [CatalogController::class, 'collection'])->defaults('key', $key)->name('collection.'.$key);
}
