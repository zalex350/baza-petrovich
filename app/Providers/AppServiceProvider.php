<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Product;
use App\Observers\CatalogMenuObserver;
use App\Services\ClientCodes;
use App\Services\CurrentCart;
use App\Services\Pricing\Discounts;
use App\Services\Pricing\PriceContext;
use App\Support\Seo;
use App\View\Composers\CatalogMenuComposer;
use App\View\Composers\HeaderComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Коды клиента: «есть ли коды у организации» — один запрос за HTTP-запрос
        $this->app->scoped(ClientCodes::class);
        // Типы цен: справочник — один запрос; тип покупателя — один раз за HTTP-запрос
        $this->app->singleton(PriceContext::class);
        $this->app->singleton(Discounts::class);
        // Корзина посетителя: токен гостя, выданный в запросе, — до конца запроса
        $this->app->scoped(CurrentCart::class);
        // SEO страницы: заголовок, описание, canonical, schema.org — до конца запроса
        $this->app->scoped(Seo::class);
    }

    public function boot(): void
    {
        View::composer('partials.header', HeaderComposer::class);
        View::composer('partials.header', CatalogMenuComposer::class);

        Category::observe(CatalogMenuObserver::class);
        Product::observe(CatalogMenuObserver::class);

        // API обмена с 1С: запросов в минуту на токен (без токена — на IP); сверх — 429 с Retry-After
        RateLimiter::for('exchange-1c', fn (Request $request) => Limit::perMinute((int) config('shop.exchange_1c.rate_limit_per_minute', 60))
            ->by('exchange-1c:'.($request->attributes->get('exchange_token')?->id ?? $request->ip())));
    }
}
