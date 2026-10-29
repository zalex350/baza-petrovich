<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PartnerController;
use App\Models\Page;
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

// Информационные страницы (главное меню, подвал): тексты — в админке «Страницы»
foreach (Page::MENU as $slug => $url) {
    Route::get($url, [PageController::class, 'show'])->defaults('slug', $slug);
}

Route::get('/page/{slug}', [PageController::class, 'show']);

// «Стать партнёром»: заявка организации; отправка — не чаще 5 раз за 10 минут с одного адреса
Route::get('/partner', [PartnerController::class, 'create']);
Route::post('/partner', [PartnerController::class, 'store'])->middleware('throttle:5,10');
