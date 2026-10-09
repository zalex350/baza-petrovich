<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function ($middleware) {

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\EnsureUserIsNotBlocked::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {

        // Общий объём запроса больше post_max_size (несколько больших фото сразу). Сессия
        // ещё не запущена — flash не сохранить, поэтому простая страница с объяснением.
        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            return response()->view('errors.post_too_large', ['limit' => ini_get('post_max_size')], 413);
        });

    })->create();
