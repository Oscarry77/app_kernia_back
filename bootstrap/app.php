<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Internas con ambos prefijos (ver routes/internal.php).
            foreach (['api/internal', 'internal'] as $prefijo) {
                Route::middleware(['api', \App\Http\Middleware\ForceJsonResponse::class])
                    ->prefix($prefijo)
                    ->group(base_path('routes/internal.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'internal.token' => \App\Http\Middleware\VerificarTokenInterno::class,
            'internal.token.producto' => \App\Http\Middleware\VerificarTokenProducto::class,
            'permiso' => \App\Http\Middleware\VerificarPermiso::class,
            'cartera' => \App\Http\Middleware\VerificarCartera::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Errores de rutas internas/API siempre en JSON, incluido el 404 de
        // una ruta inexistente (ocurre antes del middleware de ruta).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'internal/*') || $request->expectsJson()
        );
    })->create();
