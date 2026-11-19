<?php

namespace App\Http\Middleware;

use App\Support\Attribution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Запоминает источник визита (метки рекламы, внешний сайт) — App\Support\Attribution.
 * Только GET-страницы витрины; админка, API и служебные запросы (JSON, AJAX) — без изменений.
 */
class CaptureAttribution
{
    public function handle(Request $request, Closure $next): Response
    {
        $cookie = null;

        if ($request->isMethod('GET') && ! $request->ajax() && ! $request->expectsJson()
            && ! $request->is('admin', 'admin/*', 'api/*', 'sitemap.xml', 'robots.txt', 'storage/*', 'build/*')
            && ($source = Attribution::fromRequest($request))) {
            $cookie = Attribution::remember($request, $source);
        }

        $response = $next($request);

        // Cookie — прямо в ответ (шифрует EncryptCookies снаружи группы web)
        if ($cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
