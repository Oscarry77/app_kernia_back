<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\SolicitudPlan;
use App\Models\Landlord\Suscripcion;
use App\Services\Correo\AvisosCliente;
use App\Services\Landlord\CambioPlanService;
use App\Services\Landlord\PanelPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cambio de plan por solicitud con autorización del escalafón (05-oct-2026).
 * Solicitar y cancelar es desde la sesión; autorizar o rechazar exige además
 * el correo y la contraseña de quien autoriza. Reglas en CambioPlanService.
 * La contraseña tecleada nunca se guarda ni se registra.
 */
class CambiosPlanController extends Controller
{
    public function __construct(
        private readonly CambioPlanService $cambios,
        private readonly PanelPresenter $presenter,
    ) {
    }

    /** GET /api/suscripciones/{s}/plan/vista-previa?plan=codigo */
    public function vistaPrevia(Request $request, Suscripcion $suscripcion): JsonResponse
    {
        $datos = $request->validate(['plan' => ['required', 'string', 'max:60']]);

        try {
            return response()->json(['data' => $this->cambios->previsualizar($suscripcion, $datos['plan'])]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function index(Suscripcion $suscripcion): JsonResponse
    {
        return response()->json(['data' => SolicitudPlan::with(['solicitante:id,nombre', 'resolutor:id,nombre'])
            ->where('suscripcion_id', $suscripcion->id)->orderByDesc('id')->get()
            ->map(fn ($sol) => $this->presenter->solicitudPlan($sol))]);
    }

    /** Solicitudes por resolver, solo de clientes que el operador puede ver, con el resumen de pagos. */
    public function pendientes(Request $request): JsonResponse
    {
        $visibles = Cliente::visiblesPara($request->user('api'))->select('id');

        return response()->json(['data' => SolicitudPlan::with(['solicitante:id,nombre', 'suscripcion.cliente', 'suscripcion.producto'])
            ->where('estado', SolicitudPlan::SOLICITADA)
            ->whereHas('suscripcion', fn ($q) => $q->whereIn('cliente_id', $visibles))
            ->orderBy('id')->get()
            ->map(fn ($sol) => [...$this->presenter->solicitudPlan($sol),
                'cliente_id' => $sol->suscripcion->cliente_id,
                'cliente' => $sol->suscripcion->cliente->nombre,
                'producto' => $sol->suscripcion->producto->slug,
                'producto_nombre' => $sol->suscripcion->producto->nombre,
                'pagos' => $this->cambios->resumenPagos($sol->suscripcion),
            ])]);
    }

    public function solicitar(Request $request, Suscripcion $suscripcion): JsonResponse
    {
        $datos = $request->validate([
            'plan' => ['required', 'string', 'max:60'],
            'aplicacion' => ['nullable', 'in:inmediata,renovacion'],
            'motivo' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $sol = $this->cambios->solicitar($suscripcion, $datos['plan'], $datos['aplicacion'] ?? SolicitudPlan::RENOVACION,
                $datos['motivo'], $request->user('api'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Auditoria::registrar('cambio_plan.solicitado', null, $suscripcion, ['plan' => $sol->plan_actual], [
            'solicitud_plan_id' => $sol->id, 'plan_nuevo' => $sol->plan_nuevo, 'direccion' => $sol->direccion, 'aplicacion' => $sol->aplicacion,
        ]);

        return response()->json(['data' => $this->presenter->solicitudPlan($sol->fresh(['solicitante:id,nombre']))], 201);
    }

    public function resolver(Request $request, SolicitudPlan $solicitud): JsonResponse
    {
        $datos = $request->validate([
            'accion' => ['required', 'in:autorizar,rechazar'],
            'email' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
            'comentario' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $r = $this->cambios->resolver($solicitud, $datos['accion'], $datos['email'], $datos['password'],
                $datos['comentario'] ?? null, $request->user('api'), $request->ip());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $sol = $r['solicitud'];
        Auditoria::registrar("cambio_plan.{$sol->estado}", null, $sol->suscripcion, ['estado' => SolicitudPlan::SOLICITADA], [
            'solicitud_plan_id' => $sol->id, 'estado' => $sol->estado, 'resuelta_por' => $sol->resuelta_por,
            'nivel' => $sol->nivel_autorizacion, 'fecha_efectiva' => $sol->fecha_efectiva?->toDateString(),
        ]);

        // 07-oct-2026: una baja programada se avisa por correo al cliente, a su asesor y a Dirección.
        // Un problema con el correo nunca deshace la autorización: queda en el registro de correos.
        $avisos = [];
        if ($sol->estado === SolicitudPlan::PROGRAMADA && $sol->direccion === SolicitudPlan::BAJADA) {
            try {
                $avisos = app(AvisosCliente::class)->cambioPlanProgramado($sol);
            } catch (RuntimeException $e) {
                Log::warning('correo.aviso_cambio_plan_no_generado', ['solicitud_plan_id' => $sol->id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'correos' => collect($avisos)->countBy('estado'),
            'data' => $this->presenter->solicitudPlan($sol->fresh(['solicitante:id,nombre', 'resolutor:id,nombre'])),
            'suscripcion' => $this->presenter->suscripcion($sol->suscripcion->fresh()),
            'aplicada' => $r['aplicada'],
            'pagos' => $sol->estado === SolicitudPlan::RECHAZADA ? $this->cambios->resumenPagos($sol->suscripcion) : null,
        ]);
    }

    public function cancelar(Request $request, SolicitudPlan $solicitud): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:1000']]);

        try {
            $sol = $this->cambios->cancelar($solicitud, $datos['motivo']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Auditoria::registrar('cambio_plan.cancelado', null, $sol->suscripcion, null, ['solicitud_plan_id' => $sol->id, 'motivo' => $datos['motivo']]);

        return response()->json(['data' => $this->presenter->solicitudPlan($sol->fresh(['solicitante:id,nombre', 'resolutor:id,nombre']))]);
    }
}
