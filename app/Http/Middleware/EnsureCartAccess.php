<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Корзина: розница (shop.retail) — открыта гостю (корзина по cookie, App\Services\CurrentCart);
 * B2B — только после входа (гостя — на вход). Права внутри (клиент / администратор) проверяет CartController.
 */
class EnsureCartAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user() && ! config('shop.retail')) {
            return redirect()->guest(route('login'));
        }

        return $next($request);
    }
}
