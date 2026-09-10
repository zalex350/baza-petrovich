<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SearchController;

/*
|--------------------------------------------------------------------------
| Search Routes
|--------------------------------------------------------------------------
*/

Route::get(
    '/search',
    [SearchController::class, 'index']
)->name('search.index');