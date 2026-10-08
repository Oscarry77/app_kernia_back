<?php

use App\Http\Controllers\Landlord\LandlordAuthController;
use App\Http\Controllers\Landlord\LandlordTenantController;
use App\Http\Controllers\Landlord\SolicitarPasswordController;
use App\Http\Controllers\Panel\BovedaController;
use App\Http\Controllers\Panel\CambiosPlanController;
use App\Http\Controllers\Panel\CatalogoController;
use App\Http\Controllers\Panel\ClientesController;
use App\Http\Controllers\Panel\SuscripcionesController;
use App\Http\Controllers\Panel\AuditoriaController;
use App\Http\Controllers\Panel\EscalafonController;
use App\Http\Controllers\Panel\OperadoresController;
use App\Http\Controllers\Panel\ProrrogasController;
use App\Http\Controllers\Panel\VigenciasController;
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

// `cartera`: un vendedor solo alcanza clientes de su cartera; fuera de ella,
// 404 (como si no existiera).
Route::middleware(['auth:api', 'cartera'])->group(function () {
    Route::post('auth/logout', [LandlordAuthController::class, 'logout']);
    Route::post('auth/refresh', [LandlordAuthController::class, 'refresh']);
    Route::get('auth/me', [LandlordAuthController::class, 'me']);

    // Panel v2 (02-oct-2026). Fase 3: cada ruta exige un permiso del rol
    // (config/kernia_acl.php) y los controladores aplican la cartera.

    // Consulta (todos los roles tienen clientes.ver)
    Route::middleware('permiso:clientes.ver')->group(function () {
        Route::get('catalogo/productos', [CatalogoController::class, 'productos']);
        Route::get('catalogo/fiscal', [CatalogoController::class, 'fiscal']);
        Route::get('clientes', [ClientesController::class, 'index']);
        Route::get('clientes/{cliente}', [ClientesController::class, 'show']);
        Route::get('vigencias', [VigenciasController::class, 'index']);
        Route::get('suscripciones/{suscripcion}/pagos', [VigenciasController::class, 'pagos']);
        Route::get('suscripciones/{suscripcion}/metricas', [SuscripcionesController::class, 'metricas']);
        Route::get('suscripciones/{suscripcion}/prorrogas', [ProrrogasController::class, 'index']);
        Route::get('prorrogas/motivos', [ProrrogasController::class, 'motivos']);
        Route::get('suscripciones/{suscripcion}/plan/vista-previa', [CambiosPlanController::class, 'vistaPrevia']);
        Route::get('suscripciones/{suscripcion}/cambios-plan', [CambiosPlanController::class, 'index']);
    });

    Route::post('clientes', [ClientesController::class, 'store'])->middleware('permiso:clientes.crear');
    Route::put('clientes/{cliente}', [ClientesController::class, 'update'])->middleware('permiso:clientes.editar');

    Route::middleware('permiso:suscripciones.gestionar')->group(function () {
        Route::post('clientes/{cliente}/suscripciones', [SuscripcionesController::class, 'store']);
        Route::post('suscripciones/{suscripcion}/extras', [SuscripcionesController::class, 'agregarExtra']);
        Route::patch('suscripciones/{suscripcion}/ws-cntpaq', [SuscripcionesController::class, 'wsCntpaq']);
        Route::post('suscripciones/{suscripcion}/reintentar', [SuscripcionesController::class, 'reintentar']);
    });
    Route::patch('suscripciones/{suscripcion}/estatus', [SuscripcionesController::class, 'cambiarEstatus'])->middleware('permiso:suscripciones.estatus');
    Route::post('suscripciones/{suscripcion}/restablecer-admin', [SuscripcionesController::class, 'restablecerAdmin'])->middleware('permiso:suscripciones.restablecer_admin');

    // Vigencias (fase 2)
    Route::put('suscripciones/{suscripcion}/vigencia', [VigenciasController::class, 'actualizar'])->middleware('permiso:vigencias.gestionar');
    Route::post('suscripciones/{suscripcion}/pagos', [VigenciasController::class, 'registrarPago'])->middleware('permiso:pagos.registrar');

    // Prórrogas (fase 3): solicitar desde la sesión; autorizar/rechazar exige
    // además las credenciales y el nivel de quien autoriza.
    Route::middleware('permiso:prorrogas.solicitar')->group(function () {
        Route::post('suscripciones/{suscripcion}/prorrogas', [ProrrogasController::class, 'solicitar']);
        Route::post('prorrogas/{prorroga}/resolver', [ProrrogasController::class, 'resolver']);
        Route::get('prorrogas/pendientes', [ProrrogasController::class, 'pendientes']);
    });

    // Cambio de plan (05-oct-2026): por solicitud con autorización del
    // escalafón, para subir y para bajar. El cambio directo queda solo para
    // el superadmin (correcciones), con bitácora.
    Route::middleware('permiso:planes.solicitar')->group(function () {
        Route::post('suscripciones/{suscripcion}/cambios-plan', [CambiosPlanController::class, 'solicitar']);
        Route::post('cambios-plan/{solicitud}/resolver', [CambiosPlanController::class, 'resolver']);
        Route::post('cambios-plan/{solicitud}/cancelar', [CambiosPlanController::class, 'cancelar']);
        Route::get('cambios-plan/pendientes', [CambiosPlanController::class, 'pendientes']);
    });
    Route::patch('suscripciones/{suscripcion}/plan', [SuscripcionesController::class, 'cambiarPlan'])->middleware('permiso:planes.aplicar_directo');

    // Catálogo
    Route::middleware('permiso:catalogo.gestionar')->group(function () {
        Route::put('catalogo/productos/{producto:slug}', [CatalogoController::class, 'actualizarProducto']);
        Route::post('catalogo/productos/{producto:slug}/planes', [CatalogoController::class, 'crearPlan']);
        Route::put('catalogo/productos/{producto:slug}/planes/{codigo}', [CatalogoController::class, 'actualizarPlan']);
        Route::post('catalogo/productos/{producto:slug}/extras', [CatalogoController::class, 'crearExtra']);
        Route::put('catalogo/productos/{producto:slug}/extras/{codigo}', [CatalogoController::class, 'actualizarExtra']);
    });

    // Operadores, cartera y escalafón
    Route::middleware('permiso:operadores.gestionar')->group(function () {
        Route::get('operadores', [OperadoresController::class, 'index']);
        Route::post('operadores', [OperadoresController::class, 'store']);
        Route::put('operadores/{operador}', [OperadoresController::class, 'update']);
        Route::get('operadores/{operador}/cartera', [OperadoresController::class, 'cartera']);
        Route::put('operadores/{operador}/cartera', [OperadoresController::class, 'cartera']);
    });
    Route::middleware('permiso:escalafon.gestionar')->group(function () {
        Route::get('escalafon', [EscalafonController::class, 'index']);
        Route::post('escalafon', [EscalafonController::class, 'store']);
        Route::put('escalafon/{nivel}', [EscalafonController::class, 'update']);
    });

    // Bóveda (07-oct-2026): solo el superadmin. Los secretos se escriben,
    // nunca se leen; escribir exige su contraseña de nuevo.
    Route::middleware('permiso:boveda.gestionar')->group(function () {
        Route::get('boveda', [BovedaController::class, 'index']);
        Route::get('boveda/{secreto}/accesos', [BovedaController::class, 'accesos']);
        Route::put('boveda/correo', [BovedaController::class, 'guardarCorreo']);
        Route::post('boveda/correo/probar', [BovedaController::class, 'probarCorreo']);
    });

    // Bitácora
    Route::get('auditoria', [AuditoriaController::class, 'index'])->middleware('permiso:auditoria.ver');

    // Modelo heredado "tenants" -- SOLO CONSULTA (02-oct-2026), solo para
    // quien ve la bitácora. Se retiraron alta, edición y estatus: escribían en
    // `tenants`, que ninguna app consulta ya.
    Route::middleware('permiso:auditoria.ver')->group(function () {
        Route::get('tenants', [LandlordTenantController::class, 'index']);
        Route::get('tenants/{tenant}', [LandlordTenantController::class, 'show']);
        Route::get('tenants/{tenant}/metricas', [LandlordTenantController::class, 'metricas']);
        Route::get('tenants/{tenant}/empresas', [LandlordEmpresaController::class, 'index']);
        Route::put('tenants/{tenant}/empresas/{empresa}/ws-cntpaq', [LandlordEmpresaController::class, 'actualizarWsCntpaq']);
    });
});
