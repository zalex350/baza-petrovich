<?php

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\WorkController;
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

// Авторы фото разделов (лицензии CC)
Route::get('/photo-credits', function () {
    $credits = json_decode((string) @file_get_contents(database_path('data/petrovich/category-photos/credits.json')), true) ?: [];

    return view('photo_credits', ['credits' => $credits]);
});

// Квиз: ответы → заявка
Route::post('/quiz/{id}', [QuizController::class, 'submit'])->whereNumber('id')->middleware('throttle:10,10')->name('quiz.submit');

// Наши работы и отзывы
Route::get('/nashi-raboty', [WorkController::class, 'index'])->name('works.index');
Route::get('/nashi-raboty/{slug}', [WorkController::class, 'show'])->name('works.show');
Route::get('/otzyvy', [WorkController::class, 'reviews'])->name('reviews.index');

// Статьи (страницы type = article)
Route::get('/stati', [ArticleController::class, 'index'])->name('articles.index');
Route::get('/stati/{slug}', [ArticleController::class, 'show'])->name('articles.show');

// Услуги (страницы type = service)
Route::get('/uslugi', [ServiceController::class, 'index'])->name('services.index');
Route::get('/uslugi/{slug}', [ServiceController::class, 'show'])->name('services.show');

// «Стать партнёром»: заявка организации; отправка — не чаще 5 раз за 10 минут с одного адреса
Route::get('/partner', [PartnerController::class, 'create']);

// Заявки с сайта: «Узнать цену», «Заказать услугу», «Обратный звонок»
Route::get('/price-request/{id}', [LeadController::class, 'price'])->whereNumber('id')->name('lead.price');
Route::get('/request/{type}', [LeadController::class, 'create'])->name('lead.create');
Route::post('/request', [LeadController::class, 'store'])->middleware('throttle:5,10')->name('lead.store');
Route::post('/partner', [PartnerController::class, 'store'])->middleware('throttle:5,10');

// SEO: карта сайта и правила для роботов (SeoController; файла public/robots.txt нет — его отдал бы nginx)
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
