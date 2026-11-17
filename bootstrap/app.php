<?php

use App\Http\ExchangeErrorResponse;
use App\Http\Middleware\EnsureCartAccess;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsNotBlocked;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // API обмена с 1С (docs/1C_EXCHANGE_SPEC.md): только он, поэтому префикс — на весь файл
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/1c/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function ($middleware) {

        // Staging/production работают за nginx-прокси с HTTPS:
        // доверяем заголовкам X-Forwarded-*, чтобы ссылки генерировались через https.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'cart.access' => EnsureCartAccess::class,
        ]);

        $middleware->web(append: [
            EnsureUserIsNotBlocked::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {

        // API обмена с 1С: любая ошибка — JSON {"error": "код", "message": "текст"} (раздел 2)
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/1c/*')) {
                return null;
            }

            return ExchangeErrorResponse::from($e);
        });

        // Общий объём запроса больше post_max_size (несколько больших фото сразу). Сессия
        // ещё не запущена — flash не сохранить, поэтому простая страница с объяснением.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            return response()->view('errors.post_too_large', ['limit' => ini_get('post_max_size')], 413);
        });

    })->create();
