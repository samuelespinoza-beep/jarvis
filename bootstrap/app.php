<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SesionEscritorio;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['sidebar_state']);

        // Antes de exigir sesión, para que en modo escritorio se entre sin login.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, SesionEscritorio::class);

        $middleware->web(append: [
            SesionEscritorio::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // El chat se llama con fetch y pide text/event-stream: sus errores (por
        // ejemplo, un documento no válido) se devuelven en JSON, no con redirección.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'chat', 'chat/*') || $request->expectsJson(),
        );
    })->create();
