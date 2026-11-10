<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Product;
use App\Observers\CatalogMenuObserver;
use App\Services\ClientCodes;
use App\View\Composers\CatalogMenuComposer;
use App\View\Composers\HeaderComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Коды клиента: «есть ли коды у организации» — один запрос за HTTP-запрос
        $this->app->scoped(ClientCodes::class);
    }

    public function boot(): void
    {
        View::composer('partials.header', HeaderComposer::class);
        View::composer('partials.header', CatalogMenuComposer::class);

        Category::observe(CatalogMenuObserver::class);
        Product::observe(CatalogMenuObserver::class);
    }
}
