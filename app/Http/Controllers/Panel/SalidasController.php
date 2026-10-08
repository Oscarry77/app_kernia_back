<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\SolicitudSalida;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\PanelPresenter;
use App\Services\Landlord\SalidaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Salida de una suscripción por solicitud con autorización del escalafón
 * (08-oct-2026): retirar la app, reactivarla o finiquitar. Solicitar y
 * cancelar es desde la sesión; autorizar o rechazar exige además el correo y
 * la contraseña de quien autoriza. Reglas en SalidaService. La contraseña
 * tecleada nunca se guarda ni se registra.
 */
class SalidasController extends Controller
{
    public function __construct(
        private readonly SalidaService $salidas,
        private readonly PanelPresenter $presenter,
    ) {
    }

    public function index(Suscripcion $suscripcion): JsonResponse
    {
        return response()->json(['data' => SolicitudSalida::with(['solicitante:id,nombre', 'resolutor:id,nombre'])
            ->where('suscripcion_id', $suscripcion->id)->orderByDesc('id')->get()
            ->map(fn ($sol) => $this->presenter->solicitudSalida($sol))]);
    }

    /** Solicitudes por resolver, solo de clientes que el operador puede ver. */
    public function pendientes(Request $request): JsonResponse
    {
        $visibles = Cliente::visiblesPara($request->user('api'))->select('id');

        return response()->json(['data' => SolicitudSalida::with(['solicitante:id,nombre', 'suscripcion.cliente', 'suscripcion.producto'])
            ->where('estado', SolicitudSalida::SOLICITADA)
            ->whereHas('suscripcion', fn ($q) => $q->whereIn('cliente_id', $visibles))
            ->orderBy('id')->get()
            ->map(fn ($sol) => [...$this->presenter->solicitudSalida($sol),
                'cliente_id' => $sol->suscripcion->cliente_id,
                'cliente' => $sol->suscripcion->cliente->nombre,
                'cliente_slug' => $sol->suscripcion->cliente->slug,
                'producto' => $sol->suscripcion->producto->slug,
                'producto_nombre' => $sol->suscripcion->producto->nombre,
            ])]);
    }

    public function solicitar(Request $request, Suscripcion $suscripcion): JsonResponse
    {
        $datos = $request->validate([
            'tipo' => ['required', 'in:'.implode(',', SolicitudSalida::TIPOS)],
            'motivo' => ['required', 'string', 'max:1000'],
            'motivo_salida' => ['nullable', 'string', 'max:30'],
            'conformidad_tipo' => ['nullable', 'in:correo,documento'],
            'conformidad_referencia' => ['nullable', 'string', 'max:500'],
            'confirmacion_slug' => ['nullable', 'string', 'max:60'],
        ]);

        try {
            $sol = $this->salidas->solicitar($suscripcion, $datos['tipo'], $datos, $request->user('api'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Auditoria::registrar('salida.solicitada', null, $suscripcion, ['estatus' => $suscripcion->estatus], [
            'solicitud_salida_id' => $sol->id, 'tipo' => $sol->tipo, 'motivo' => $sol->motivo,
            'conformidad_tipo' => $sol->conformidad_tipo, 'conformidad_referencia' => $sol->conformidad_referencia,
        ]);

        return response()->json(['data' => $this->presenter->solicitudSalida($sol->fresh(['solicitante:id,nombre']))], 201);
    }

    public function resolver(Request $request, SolicitudSalida $salida): JsonResponse
    {
        $datos = $request->validate([
            'accion' => ['required', 'in:autorizar,rechazar'],
            'email' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
            'comentario' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $r = $this->salidas->resolver($salida, $datos['accion'], $datos['email'], $datos['password'],
                $datos['comentario'] ?? null, $request->user('api'), $request->ip());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $sol = $r['solicitud'];
        Auditoria::registrar("salida.{$sol->estado}", null, $sol->suscripcion, ['estado' => SolicitudSalida::SOLICITADA], [
            'solicitud_salida_id' => $sol->id, 'tipo' => $sol->tipo, 'estado' => $sol->estado, 'resuelta_por' => $sol->resuelta_por,
            'nivel' => $sol->nivel_autorizacion, 'fecha_efectiva' => $sol->fecha_efectiva?->toDateString(),
        ]);

        return response()->json([
            'data' => $this->presenter->solicitudSalida($sol->fresh(['solicitante:id,nombre', 'resolutor:id,nombre'])),
            'suscripcion' => $this->presenter->suscripcion($sol->suscripcion->fresh()),
            'aplicada' => $r['aplicada'],
        ]);
    }

    public function cancelar(Request $request, SolicitudSalida $salida): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:1000']]);

        try {
            $sol = $this->salidas->cancelar($salida, $datos['motivo']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Auditoria::registrar('salida.cancelada', null, $sol->suscripcion, null, ['solicitud_salida_id' => $sol->id, 'tipo' => $sol->tipo, 'motivo' => $datos['motivo']]);

        return response()->json(['data' => $this->presenter->solicitudSalida($sol->fresh(['solicitante:id,nombre', 'resolutor:id,nombre']))]);
    }
}
