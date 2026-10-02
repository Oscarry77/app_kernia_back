<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Polling de aprovisionamientos asíncronos (HRM con base por cliente, 28-sep-2026).
Schedule::command('landlord:sincronizar-aprovisionamientos')->everyMinute()->withoutOverlapping();

// Reintento de avisos de estatus no confirmados por la app (01-oct-2026).
Schedule::command('landlord:reintentar-notificaciones-estatus')->everyMinute()->withoutOverlapping();

// Corte de vencimientos (fase 2, 02-oct-2026): a las 00:00 de México y cada
// hora para recuperar un corte perdido si el servidor estuvo abajo.
Schedule::command('landlord:procesar-vencimientos')->dailyAt('00:00')->timezone(config('kernia.zona_horaria'))->withoutOverlapping();
Schedule::command('landlord:procesar-vencimientos')->hourlyAt(5)->withoutOverlapping();
