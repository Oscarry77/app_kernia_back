<?php

use App\Http\Controllers\Internal\ProductoResolveController;
use App\Http\Controllers\Internal\TenantResolveController;
use Illuminate\Support\Facades\Route;

// Rutas servidor-a-servidor (apps de producto -> Kernia). Se registran DOS
// veces en bootstrap/app.php, bajo `/api/internal` y bajo `/internal`
// (30-sep-2026): SVI configuró `KERNIA_API_URL` sin `/api`, la guía no lo
// aclaraba, y HRM/SVI ya publican sus rutas internas con ambos prefijos.
// Siempre JSON (ForceJsonResponse), igual que se exige a las apps (§4.3).

// Alias legacy -- solo bridge-com-api hasta que migre al contrato v1 de
// abajo (ver ADDENDUM_CARTA_COMERCIALIZA_CONSOLIDACION_ACL_2026-09-22.md).
Route::middleware('internal.token')->group(function () {
    Route::get('tenants/resolve/{slug}', [TenantResolveController::class, 'resolver']);
});

// Contrato v1 (guía §4.1) -- consumido por las apps de producto.
Route::middleware('internal.token.producto')->prefix('v1')->group(function () {
    Route::get('productos/{producto}/resolve/{slug}', [ProductoResolveController::class, 'resolver']);
});
