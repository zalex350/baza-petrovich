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

Route::get(
    '/catalog/{slug}',
    [CatalogController::class, 'show']
)->name('catalog.show');

Route::get(
    '/product/{id}',
    [ProductController::class, 'show']
)->name('product.show');