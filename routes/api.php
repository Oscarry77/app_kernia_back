<?php

use App\Http\Controllers\Landlord\LandlordAuthController;
use App\Http\Controllers\Landlord\LandlordTenantController;
use App\Http\Controllers\Landlord\SolicitarPasswordController;
use App\Http\Controllers\Panel\CatalogoController;
use App\Http\Controllers\Panel\ClientesController;
use App\Http\Controllers\Panel\SuscripcionesController;
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

    // Panel v2 (02-oct-2026): clientes y sus apps (modelo clientes/suscripciones).
    Route::get('catalogo/productos', [CatalogoController::class, 'productos']);
    Route::get('catalogo/fiscal', [CatalogoController::class, 'fiscal']);
    Route::put('catalogo/productos/{producto:slug}', [CatalogoController::class, 'actualizarProducto']);
    Route::post('catalogo/productos/{producto:slug}/planes', [CatalogoController::class, 'crearPlan']);
    Route::put('catalogo/productos/{producto:slug}/planes/{codigo}', [CatalogoController::class, 'actualizarPlan']);
    Route::post('catalogo/productos/{producto:slug}/extras', [CatalogoController::class, 'crearExtra']);
    Route::put('catalogo/productos/{producto:slug}/extras/{codigo}', [CatalogoController::class, 'actualizarExtra']);
    Route::patch('suscripciones/{suscripcion}/ws-cntpaq', [SuscripcionesController::class, 'wsCntpaq']);
    Route::get('clientes', [ClientesController::class, 'index']);
    Route::post('clientes', [ClientesController::class, 'store']);
    Route::get('clientes/{cliente}', [ClientesController::class, 'show']);
    Route::put('clientes/{cliente}', [ClientesController::class, 'update']);
    Route::post('clientes/{cliente}/suscripciones', [SuscripcionesController::class, 'store']);
    Route::patch('suscripciones/{suscripcion}/plan', [SuscripcionesController::class, 'cambiarPlan']);
    Route::post('suscripciones/{suscripcion}/extras', [SuscripcionesController::class, 'agregarExtra']);
    Route::patch('suscripciones/{suscripcion}/estatus', [SuscripcionesController::class, 'cambiarEstatus']);
    Route::post('suscripciones/{suscripcion}/restablecer-admin', [SuscripcionesController::class, 'restablecerAdmin']);
    Route::post('suscripciones/{suscripcion}/reintentar', [SuscripcionesController::class, 'reintentar']);
    Route::get('suscripciones/{suscripcion}/metricas', [SuscripcionesController::class, 'metricas']);

    // Modelo heredado "tenants" -- SOLO CONSULTA (02-oct-2026). Se retiraron
    // alta, edición y cambio de estatus: escribían en `tenants`, que ninguna
    // app consulta ya (la fuente de verdad es clientes/suscripciones), así que
    // "Suspender" no suspendía nada y "Nuevo tenant" creaba clientes fuera del
    // control v2. Los controladores se conservan hasta retirar el modelo.
    Route::get('tenants', [LandlordTenantController::class, 'index']);
    Route::get('tenants/{tenant}', [LandlordTenantController::class, 'show']);
    Route::get('tenants/{tenant}/metricas', [LandlordTenantController::class, 'metricas']);
    Route::get('tenants/{tenant}/empresas', [LandlordEmpresaController::class, 'index']);
    Route::put('tenants/{tenant}/empresas/{empresa}/ws-cntpaq', [LandlordEmpresaController::class, 'actualizarWsCntpaq']);
});
