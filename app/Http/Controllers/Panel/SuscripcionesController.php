<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\PanelPresenter;
use App\Services\Landlord\ProductoAppClient;
use App\Services\Landlord\SuscripcionEstatusService;
use App\Services\Landlord\SuscripcionOnboardingService;
use App\Services\Landlord\SuscripcionPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Operación de suscripciones (cliente x app) desde el panel (02-oct-2026):
 * las mismas reglas que los comandos `landlord:*`, con bitácora.
 *
 * Las contraseñas temporales (alta, reintento, restablecimiento) se devuelven
 * UNA vez en la respuesta para que el panel las muestre; Kernia no las guarda
 * ni las registra en la bitácora.
 */
class SuscripcionesController extends Controller
{
    public function __construct(
        private readonly PanelPresenter $presenter,
        private readonly SuscripcionPlanService $planes,
    ) {
    }

    public function store(Request $request, Cliente $cliente, SuscripcionOnboardingService $onboarding): JsonResponse
    {
        $datos = $request->validate([
            'producto' => ['required', 'string', 'exists:productos,slug'],
            'plan' => ['nullable', 'string', 'max:60'],
            'admin_nombre' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:150'],
        ]);

        $producto = Producto::where('slug', $datos['producto'])->firstOrFail();
        $password = SuscripcionOnboardingService::generarPasswordTemporal();

        try {
            $s = $onboarding->aprovisionar($cliente, $producto, [
                'nombre' => $datos['admin_nombre'],
                'email' => strtolower($datos['admin_email']),
                'password_temporal' => $password,
            ], $datos['plan'] ?? null);
        } catch (RuntimeException $e) {
            $fallida = Suscripcion::where('cliente_id', $cliente->id)->where('producto_id', $producto->id)->first();
            Auditoria::registrar('suscripcion.alta_fallida', $cliente, $fallida, null, ['producto' => $producto->slug, 'error' => mb_substr($e->getMessage(), 0, 300)]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        Auditoria::registrar('suscripcion.creada', $cliente, $s, null, ['producto' => $producto->slug, 'plan' => $s->plan, 'estatus' => $s->estatus, 'admin_email' => $s->admin_email]);

        return response()->json([
            'data' => $this->presenter->suscripcion($s),
            'password_temporal' => $password,
        ], 201);
    }

    public function cambiarPlan(Request $request, Suscripcion $suscripcion): JsonResponse
    {
        $datos = $request->validate(['plan' => ['required', 'string', 'max:60']]);
        $antes = ['plan' => $suscripcion->plan, 'limites' => $this->planes->limitesEfectivos($suscripcion)];

        try {
            $this->planes->aplicarPlan($suscripcion, $datos['plan']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $s = $suscripcion->fresh();
        Auditoria::registrar('suscripcion.plan_cambiado', null, $s, $antes, ['plan' => $s->plan, 'limites' => $this->planes->limitesEfectivos($s)]);

        return response()->json(['data' => $this->presenter->suscripcion($s)]);
    }

    public function agregarExtra(Request $request, Suscripcion $suscripcion): JsonResponse
    {
        $datos = $request->validate([
            'extra' => ['required', 'string', 'max:60'],
            'cantidad' => ['required', 'integer', 'not_in:0', 'between:-1000,1000'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);
        $antes = ['extras' => $this->planes->extrasContratados($suscripcion), 'limites' => $this->planes->limitesEfectivos($suscripcion)];

        try {
            $this->planes->agregarExtra($suscripcion, $datos['extra'], (int) $datos['cantidad'], $datos['motivo'], auth('api')->user()?->email);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $s = $suscripcion->fresh();
        Auditoria::registrar('suscripcion.extra', null, $s, $antes, [
            'extra' => $datos['extra'], 'cantidad' => (int) $datos['cantidad'], 'motivo' => $datos['motivo'],
            'extras' => $this->planes->extrasContratados($s), 'limites' => $this->planes->limitesEfectivos($s),
        ]);

        return response()->json(['data' => $this->presenter->suscripcion($s)]);
    }

    public function cambiarEstatus(Request $request, Suscripcion $suscripcion, SuscripcionEstatusService $estatus): JsonResponse
    {
        $datos = $request->validate([
            'estatus' => ['required', 'in:activo,suspendido'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        if (! in_array($suscripcion->estatus, [Suscripcion::ESTATUS_ACTIVO, Suscripcion::ESTATUS_SUSPENDIDO], true)) {
            return response()->json(['message' => "No se cambia el estatus de una suscripción '{$suscripcion->estatus}'."], 422);
        }

        $antes = ['estatus' => $suscripcion->estatus];
        $confirmado = $estatus->cambiarEstatus($suscripcion, $datos['estatus'], $datos['motivo']);
        $s = $suscripcion->fresh();
        Auditoria::registrar('suscripcion.estatus', null, $s, $antes, ['estatus' => $s->estatus, 'motivo' => $datos['motivo'], 'app_confirmo' => $confirmado]);

        return response()->json(['data' => $this->presenter->suscripcion($s), 'app_confirmo' => $confirmado]);
    }

    public function restablecerAdmin(Request $request, Suscripcion $suscripcion, ProductoAppClient $app): JsonResponse
    {
        $datos = $request->validate(['email' => ['nullable', 'email', 'max:150']]);

        if ($suscripcion->estatus !== Suscripcion::ESTATUS_ACTIVO) {
            return response()->json(['message' => 'Solo se restablece el acceso de una suscripción activa.'], 422);
        }

        $email = $datos['email'] ?? $suscripcion->admin_email;
        if (! $email) {
            return response()->json(['message' => 'La suscripción no tiene correo de administrador; indícalo.'], 422);
        }

        $password = SuscripcionOnboardingService::generarPasswordTemporal();

        try {
            $app->restablecerAdmin($suscripcion, $email, $password);
        } catch (RuntimeException $e) {
            Auditoria::registrar('suscripcion.admin_restablecimiento_fallido', null, $suscripcion, null, ['email' => $email, 'error' => mb_substr($e->getMessage(), 0, 300)]);

            return response()->json(['message' => $this->mensajeApp($e->getMessage())], 422);
        }

        Auditoria::registrar('suscripcion.admin_restablecido', null, $suscripcion, null, ['email' => $email]);

        return response()->json(['email' => $email, 'password_temporal' => $password]);
    }

    public function reintentar(Request $request, Suscripcion $suscripcion, SuscripcionOnboardingService $onboarding): JsonResponse
    {
        $datos = $request->validate(['admin_nombre' => ['required', 'string', 'max:120']]);
        $password = SuscripcionOnboardingService::generarPasswordTemporal();

        try {
            $s = $onboarding->reintentar($suscripcion, $datos['admin_nombre'], $password);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Auditoria::registrar('suscripcion.reintento', null, $s, ['estatus' => Suscripcion::ESTATUS_FALLIDO], ['estatus' => $s->estatus]);

        return response()->json(['data' => $this->presenter->suscripcion($s), 'password_temporal' => $password]);
    }

    /** WS-CNTPAQi.Net habilitado para este cliente en esta app (solo Comercializa y HRM, decisión del dueño 02-oct). */
    public function wsCntpaq(Request $request, Suscripcion $suscripcion): JsonResponse
    {
        $datos = $request->validate(['habilitado' => ['required', 'boolean']]);

        if (! $suscripcion->producto->permite_ws_cntpaq) {
            return response()->json(['message' => "{$suscripcion->producto->nombre} no usa WS-CNTPAQi.Net."], 422);
        }

        $antes = ['ws_cntpaq_habilitado' => (bool) $suscripcion->ws_cntpaq_habilitado];
        $suscripcion->update(['ws_cntpaq_habilitado' => $datos['habilitado']]);
        Auditoria::registrar('suscripcion.ws_cntpaq', null, $suscripcion, $antes, ['ws_cntpaq_habilitado' => $datos['habilitado']]);

        return response()->json(['data' => $this->presenter->suscripcion($suscripcion->fresh())]);
    }

    public function metricas(Suscripcion $suscripcion, ProductoAppClient $app): JsonResponse
    {
        return response()->json(['data' => $app->metricas($suscripcion)]);
    }

    /** Traduce las respuestas conocidas de la app a un mensaje para el operador. */
    private function mensajeApp(string $error): string
    {
        return match (true) {
            str_contains($error, 'ADMIN_INACTIVO') => 'El administrador está desactivado dentro de la app; reactivarlo es decisión del cliente.',
            str_contains($error, '(404)') => 'La app no reconoce ese correo como administrador del cliente.',
            str_contains($error, '(409)') => 'La app reporta al cliente como suspendido.',
            default => 'La app no pudo restablecer el acceso: '.mb_substr($error, 0, 200),
        };
    }
}
