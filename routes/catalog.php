<?php

use Illuminate\Support\Facades\Route;
use App\Models\Product;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ProductController;

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

Route::get(
    '/product/{id}',
    [ProductController::class, 'show']
)->name('product.show');