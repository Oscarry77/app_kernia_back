<?php

use App\Http\Controllers\Landlord\LandlordAuthController;
use App\Http\Controllers\Landlord\LandlordTenantController;
use App\Http\Controllers\Landlord\SolicitarPasswordController;
use App\Http\Controllers\Landlord\LandlordEmpresaController;
use Illuminate\Support\Facades\Route;

// Movido de bridge-com-api el 13-sep-2026 (extracción de Kernia). Ya no hay
// prefijo 'landlord' ni ResolverTenant que excluir -- esta es la única app,
// todo aquí es landlord por definición.

// Rutas internas (resolve) -> routes/internal.php, registradas en
// bootstrap/app.php bajo /api/internal y /internal (30-sep-2026).

Route::post('auth/login', [LandlordAuthController::class, 'login']);
// "Olvidé mi contraseña" del panel (02-oct-2026).
Route::post('auth/password/solicitar', SolicitarPasswordController::class)->middleware('throttle:solicitud-password');

Route::middleware('auth:api')->group(function () {
    Route::post('auth/logout', [LandlordAuthController::class, 'logout']);
    Route::post('auth/refresh', [LandlordAuthController::class, 'refresh']);
    Route::get('auth/me', [LandlordAuthController::class, 'me']);

    Route::get('tenants', [LandlordTenantController::class, 'index']);
    Route::post('tenants', [LandlordTenantController::class, 'store']);
    Route::get('tenants/{tenant}', [LandlordTenantController::class, 'show']);
    Route::put('tenants/{tenant}', [LandlordTenantController::class, 'update']);
    Route::patch('tenants/{tenant}/estatus', [LandlordTenantController::class, 'cambiarEstatus']);
    Route::get('tenants/{tenant}/metricas', [LandlordTenantController::class, 'metricas']);
    Route::get('tenants/{tenant}/empresas', [LandlordEmpresaController::class, 'index']);
    Route::put('tenants/{tenant}/empresas/{empresa}/ws-cntpaq', [LandlordEmpresaController::class, 'actualizarWsCntpaq']);
});
