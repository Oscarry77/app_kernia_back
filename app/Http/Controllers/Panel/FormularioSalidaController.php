<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\FormularioSalida;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\FormularioSalidaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Formulario de salida (08-oct-2026):
 *  - Panel: catálogo de motivos, enlace de un solo uso para el cliente y la
 *    vista "Motivos de salida" de Dirección.
 *  - Público: el cliente abre su enlace y contesta una sola vez.
 */
class FormularioSalidaController extends Controller
{
    public function __construct(private readonly FormularioSalidaService $formularios)
    {
    }

    public function motivos(): JsonResponse
    {
        return response()->json(['data' => collect($this->formularios->motivos())
            ->map(fn ($nombre, $clave) => ['clave' => $clave, 'nombre' => $nombre])->values()]);
    }

    /** Enlace para el cliente de una app que salió. El token se muestra UNA vez. */
    public function generarEnlace(Request $request, Suscripcion $suscripcion): JsonResponse
    {
        $evento = match ($suscripcion->estatus) {
            Suscripcion::ESTATUS_RETIRADO => FormularioSalida::EVENTO_RETIRO,
            Suscripcion::ESTATUS_EN_FINIQUITO, Suscripcion::ESTATUS_FINIQUITADO => FormularioSalida::EVENTO_FINIQUITO,
            default => null,
        };
        if (! $evento) {
            return response()->json(['message' => 'El enlace se genera para una app retirada o en finiquito.'], 422);
        }

        $enlace = $this->formularios->generarEnlace($suscripcion, $evento, $request->user('api'));
        Auditoria::registrar('formulario_salida.enlace_generado', null, $suscripcion, null, ['evento' => $evento, 'expira_en' => $enlace['expira_en']]);

        return response()->json(['data' => $enlace]);
    }

    /** Vista "Motivos de salida" (Dirección): respuestas con filtros y resumen. */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'origen' => ['nullable', 'in:asesor,cliente'],
            'evento' => ['nullable', 'in:retiro,finiquito,baja_plan,archivo'],
            'producto' => ['nullable', 'string', 'exists:productos,slug'],
        ]);

        $consulta = FormularioSalida::query()
            ->whereIn('cliente_id', Cliente::visiblesPara($request->user('api'))->select('id'))
            ->when($filtros['desde'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filtros['hasta'] ?? null, fn ($q, $h) => $q->whereDate('created_at', '<=', $h))
            ->when($filtros['origen'] ?? null, fn ($q, $o) => $q->where('origen', $o))
            ->when($filtros['evento'] ?? null, fn ($q, $e) => $q->where('evento', $e))
            ->when($filtros['producto'] ?? null, fn ($q, $p) => $q->whereHas('suscripcion.producto', fn ($q) => $q->where('slug', $p)));

        $motivos = $this->formularios->motivos();
        $filas = (clone $consulta)->with(['cliente:id,nombre,slug', 'suscripcion.producto:id,slug,nombre', 'registrador:id,nombre',
            'solicitudSalida:id,estado', 'solicitudPlan:id,estado'])->latest('id')->limit(500)->get()
            ->map(fn (FormularioSalida $f) => [
                'id' => $f->id,
                'fecha' => $f->created_at?->toIso8601String(),
                'cliente_id' => $f->cliente_id,
                'cliente' => $f->cliente?->nombre,
                'producto' => $f->suscripcion?->producto?->nombre,
                'origen' => $f->origen,
                'evento' => $f->evento,
                'motivo' => $f->motivo,
                'motivo_nombre' => $motivos[$f->motivo] ?? $f->motivo,
                'detalle' => $f->detalle,
                'calificacion' => $f->calificacion,
                'mejora' => $f->mejora,
                'recomendaria' => $f->recomendaria,
                'registrado_por' => $f->registrador?->nombre,
                // Estado de la solicitud del asesor: una rechazada o cancelada no fue una salida real.
                'estado_solicitud' => $f->solicitudSalida?->estado ?? $f->solicitudPlan?->estado,
            ]);

        return response()->json(['data' => $filas, 'resumen' => $this->formularios->resumen($consulta)]);
    }

    // ── Público ──

    public function mostrar(string $token): JsonResponse
    {
        $enlace = $this->formularios->buscarEnlace($token);
        if (! $enlace) {
            return response()->json(['message' => 'Este enlace no existe.'], 404);
        }
        if (! $enlace->vigente()) {
            return response()->json(['message' => $enlace->usado_en ? 'Ya recibimos tus respuestas. ¡Gracias!' : 'Este enlace venció.'], 410);
        }

        return response()->json(['data' => [
            'cliente' => $enlace->cliente->nombre,
            'app' => $enlace->suscripcion->producto->nombre,
            'motivos' => collect($this->formularios->motivos(paraCliente: true))
                ->map(fn ($nombre, $clave) => ['clave' => $clave, 'nombre' => $nombre])->values(),
        ]]);
    }

    public function responder(Request $request, string $token): JsonResponse
    {
        $enlace = $this->formularios->buscarEnlace($token);
        if (! $enlace) {
            return response()->json(['message' => 'Este enlace no existe.'], 404);
        }

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'max:30'],
            'detalle' => ['nullable', 'string', 'max:2000'],
            'calificacion' => ['nullable', 'integer', 'between:1,5'],
            'mejora' => ['nullable', 'string', 'max:2000'],
            'recomendaria' => ['nullable', 'boolean'],
        ]);

        try {
            $this->formularios->registrarCliente($enlace, $datos);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], $enlace->fresh()->vigente() ? 422 : 410);
        }

        return response()->json(['message' => 'Gracias por tus respuestas.'], 201);
    }
}
