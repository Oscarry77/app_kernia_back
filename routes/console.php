<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Polling de aprovisionamientos asíncronos (HRM con base por cliente, 28-sep-2026).
Schedule::command('landlord:sincronizar-aprovisionamientos')->everyMinute()->withoutOverlapping();
