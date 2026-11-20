<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Один адрес у страницы: /catalog/profnastil/ → 301 /catalog/profnastil (старый сайт писал адреса со «/»).
 * Только GET и HEAD; параметры сохраняются.
 */
class RemoveTrailingSlash
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->getPathInfo();

        if (($request->isMethod('GET') || $request->isMethod('HEAD')) && $path !== '/' && str_ends_with($path, '/')) {
            $query = $request->getQueryString();

            return redirect()->to(rtrim($path, '/').($query ? '?'.$query : ''), 301);
        }

        return $next($request);
    }
}
