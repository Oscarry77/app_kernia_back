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

// Bóveda (07-oct-2026): purga diaria de secretos cuya retención venció.
Schedule::command('landlord:boveda-purgar')->dailyAt('00:15')->timezone(config('kernia.zona_horaria'))->withoutOverlapping();

// Baja de plan v2.2 (09-oct-2026): avanza cada baja en ejecución (aviso → mantenimiento → ajuste-plan → activo).
Schedule::command('landlord:orquestar-bajas')->everyMinute()->withoutOverlapping();

// Conciliación diaria de licencias (09-oct-2026): lo que reporta cada app contra lo contratado.
Schedule::command('landlord:conciliar-licencias')->dailyAt('01:00')->timezone(config('kernia.zona_horaria'))->withoutOverlapping();

// Avisos de vencimiento por correo (09-oct-2026, fase 4): a las 08:00 de México, en horario de oficina.
Schedule::command('landlord:avisos-vencimiento')->dailyAt('08:00')->timezone(config('kernia.zona_horaria'))->withoutOverlapping();


// Exportación v2.3 del finiquito (09-oct-2026): avance cada 5 minutos; recordatorios y vencimiento del plazo a las 08:10.
Schedule::command('landlord:orquestar-exportaciones')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('landlord:orquestar-exportaciones --diarias')->dailyAt('08:10')->timezone(config('kernia.zona_horaria'))->withoutOverlapping();

// Respaldos de base del patrón A (09-oct-2026): borra los vencidos.
Schedule::call(fn () => app(\App\Services\Landlord\RespaldoBaseService::class)->eliminarVencidos())
    ->name('respaldos-base-vencidos')->dailyAt('00:20')->timezone(config('kernia.zona_horaria'))->withoutOverlapping();
