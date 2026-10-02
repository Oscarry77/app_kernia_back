<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // (02-oct-2026) "Olvidé mi contraseña" del panel: cualquiera que
        // conozca un correo puede pedir una contraseña nueva para esa cuenta.
        // Se limita por correo (3/hora) y por IP (10/hora) para que no se pueda
        // usar para estar cambiándole la contraseña a un operador una y otra vez.
        RateLimiter::for('solicitud-password', fn (Request $request) => [
            Limit::perHour(3)->by('correo:'.strtolower((string) $request->input('email'))),
            Limit::perHour(10)->by('ip:'.$request->ip()),
        ]);
    }
}
