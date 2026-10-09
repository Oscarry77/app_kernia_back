<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\Exportacion;
use App\Models\Landlord\SolicitudRespaldo;
use App\Services\Landlord\RespaldoSoporteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * (09-oct-2026) Soporte sobre un respaldo con autorización del escalafón:
 * reenviar la contraseña al administrador del cliente o entregar el 7z
 * cifrado a soporte (estándar v2.3 §4.3 y §7). Reglas en RespaldoSoporteService.
 */
class RespaldosController extends Controller
{
    public function __construct(private readonly RespaldoSoporteService $soporte)
    {
    }

    public function pendientes(Request $request): JsonResponse
    {
        $visibles = Cliente::visiblesPara($request->user('api'))->select('id');

        return response()->json(['data' => SolicitudRespaldo::with(['solicitante:id,nombre', 'suscripcion.cliente', 'suscripcion.producto', 'exportacion'])
            ->where('estado', SolicitudRespaldo::SOLICITADA)
            ->whereHas('suscripcion', fn ($q) => $q->whereIn('cliente_id', $visibles))
            ->orderBy('id')->get()->map(fn ($s) => [...$this->forma($s),
                'cliente_id' => $s->suscripcion->cliente_id, 'cliente' => $s->suscripcion->cliente->nombre,
                'producto_nombre' => $s->suscripcion->producto->nombre, 'sha256' => $s->exportacion->sha256,
            ])]);
    }

    /** Solicitudes de un respaldo (para la tarjeta del cliente). */
    public function index(Exportacion $exportacion): JsonResponse
    {
        return response()->json(['data' => SolicitudRespaldo::with(['solicitante:id,nombre', 'resolutor:id,nombre'])
            ->where('exportacion_id', $exportacion->id)->orderByDesc('id')->get()->map(fn ($s) => $this->forma($s))]);
    }

    public function solicitar(Request $request, Exportacion $exportacion): JsonResponse
    {
        $datos = $request->validate([
            'tipo' => ['required', 'in:'.implode(',', SolicitudRespaldo::TIPOS)],
            'motivo' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $sol = $this->soporte->solicitar($exportacion, $datos['tipo'], $datos['motivo'], $request->user('api'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        Auditoria::registrar('respaldo.solicitud', null, $exportacion->suscripcion, null, ['solicitud_respaldo_id' => $sol->id, 'tipo' => $sol->tipo, 'motivo' => $sol->motivo]);

        return response()->json(['data' => $this->forma($sol->fresh(['solicitante:id,nombre']))], 201);
    }

    public function resolver(Request $request, SolicitudRespaldo $respaldo): JsonResponse
    {
        $datos = $request->validate([
            'accion' => ['required', 'in:autorizar,rechazar'],
            'email' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
            'comentario' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $sol = $this->soporte->resolver($respaldo, $datos['accion'], $datos['email'], $datos['password'], $datos['comentario'] ?? null,
                $request->user('api'), $request->ip());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        Auditoria::registrar("respaldo.{$sol->estado}", null, $sol->suscripcion, null, ['solicitud_respaldo_id' => $sol->id, 'tipo' => $sol->tipo, 'resuelta_por' => $sol->resuelta_por]);

        return response()->json(['data' => $this->forma($sol->fresh(['solicitante:id,nombre', 'resolutor:id,nombre']))]);
    }

    public function cancelar(Request $request, SolicitudRespaldo $respaldo): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:1000']]);
        try {
            $sol = $this->soporte->cancelar($respaldo, $datos['motivo']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->forma($sol)]);
    }

    public function reintentar(SolicitudRespaldo $respaldo): JsonResponse
    {
        try {
            $sol = $this->soporte->reintentarReenvio($respaldo);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->forma($sol)]);
    }

    /** (09-oct-2026) Copia a petición del cliente: una por trimestre incluida. */
    public function pedirCopia(Request $request, \App\Models\Landlord\Suscripcion $suscripcion): JsonResponse
    {
        try {
            $exp = app(\App\Services\Landlord\ExportacionService::class)->iniciarCopia($suscripcion, $request->user('api'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['exportacion_id' => $exp->id, 'estado' => $exp->estado, 'disponible_hasta' => $exp->disponible_hasta->toDateString()]], 201);
    }

    /** El 7z cifrado, en flujo desde la app: solo quien lo pidió, una vez, dentro de 24 h. Kernia no lo guarda. */
    public function archivo(Request $request, SolicitudRespaldo $respaldo): StreamedResponse|JsonResponse
    {
        try {
            $e = $this->soporte->abrirEntrega($respaldo, $request->user('api'));
        } catch (RuntimeException $ex) {
            return response()->json(['message' => $ex->getMessage()], 422);
        }

        return response()->streamDownload(function () use ($e) {
            while (! $e['flujo']->eof()) {
                echo $e['flujo']->read(65536);
                flush();
            }
        }, $e['nombre'], ['Content-Type' => 'application/x-7z-compressed', 'X-Huella-SHA256' => (string) $e['sha256']]);
    }

    private function forma(SolicitudRespaldo $s): array
    {
        return [
            'id' => $s->id, 'exportacion_id' => $s->exportacion_id, 'suscripcion_id' => $s->suscripcion_id, 'tipo' => $s->tipo,
            'motivo' => $s->motivo, 'estado' => $s->estado, 'solicitada_por' => $s->solicitante?->nombre, 'solicitada_por_id' => $s->solicitada_por,
            'resuelta_por' => $s->resolutor?->nombre, 'comentario_resolucion' => $s->comentario_resolucion,
            'solicitada_en' => $s->created_at?->toIso8601String(), 'resuelta_en' => $s->resuelta_en?->toIso8601String(),
            'aplicada_en' => $s->aplicada_en?->toIso8601String(), 'vigente_hasta' => $s->vigente_hasta?->toIso8601String(),
        ];
    }
}
