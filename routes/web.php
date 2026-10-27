<?php

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index']);

Route::get('/dashboard', function () {
    return redirect('/catalog');
})->middleware(['auth'])->name('dashboard');

// Привычный адрес личного кабинета: клиенту — его заказы (кабинет), администратору — админка
Route::get('/account', function () {
    return redirect(auth()->user()->isAdmin() ? '/admin' : '/orders');
})->middleware(['auth']);

require __DIR__.'/auth.php';
require __DIR__.'/catalog.php';
require __DIR__.'/cart.php';
require __DIR__.'/orders.php';
require __DIR__.'/admin.php';
require __DIR__.'/search.php';
require __DIR__.'/profile.php';
