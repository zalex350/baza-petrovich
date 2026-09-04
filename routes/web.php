<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;


Route::get('/', [HomeController::class, 'index']);

require __DIR__.'/auth.php';
require __DIR__.'/catalog.php';
require __DIR__.'/cart.php';
require __DIR__.'/orders.php';
require __DIR__.'/admin.php';