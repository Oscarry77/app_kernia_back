<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\PanelPresenter;
use App\Services\Landlord\VigenciaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Vigencias (fase 2, 02-oct-2026): vista general de vencimientos, definición
 * de la vigencia de una suscripción y registro de pagos.
 */
class VigenciasController extends Controller
{
    public function __construct(
        private readonly VigenciaService $vigencias,
        private readonly PanelPresenter $presenter,
    ) {
    }

    /** GET /api/vigencias?filtro=todas|por_vencer|vencidas|suspendidas|prorroga|sin_definir&dias=30 */
    public function index(Request $request): JsonResponse
    {
        $filtro = $request->query('filtro', 'todas');
        $dias = max(1, min(366, (int) $request->query('dias', 30)));

        $filas = Suscripcion::with(['cliente', 'producto'])
            ->whereNotIn('estatus', [Suscripcion::ESTATUS_CANCELADO, Suscripcion::ESTATUS_FALLIDO])
            // 05-oct-2026: solo clientes comerciales (demo, capacitación y prueba no tienen vigencia).
            ->whereIn('cliente_id', Cliente::visiblesPara($request->user('api'))->where('tipo', Cliente::TIPO_COMERCIAL)->select('id'))
            ->get()
            ->map(fn (Suscripcion $s) => [
                'id' => $s->id,
                'cliente_id' => $s->cliente_id,
                'cliente' => $s->cliente->nombre,
                'cliente_slug' => $s->cliente->slug,
                'producto' => $s->producto->slug,
                'producto_nombre' => $s->producto->nombre,
                'plan_nombre' => $s->producto->planVigente($s->plan)?->nombre,
                'estatus' => $s->estatusEfectivo(),
                ...$this->presenter->vigencia($s),
                'en_prorroga' => $this->vigencias->enProrroga($s),
            ])
            ->filter(fn ($f) => match ($filtro) {
                'por_vencer' => $f['estatus'] === 'activo' && $f['dias_restantes'] !== null && $f['dias_restantes'] >= 0 && $f['dias_restantes'] <= $dias,
                'vencidas' => $f['dias_restantes'] !== null && $f['dias_restantes'] < 0,
                'suspendidas' => $f['estatus'] === 'suspendido',
                'prorroga' => $f['en_prorroga'],
                'sin_definir' => $f['fecha_proximo_pago'] === null,
                default => true,
            })
            ->sortBy(fn ($f) => $f['dias_restantes'] ?? PHP_INT_MAX)
            ->values();

        return response()->json([
            'data' => $filas,
            'hoy' => VigenciaService::hoy()->toDateString(),
            'modalidades' => array_keys(config('kernia.modalidades')),
        ]);
    }

    public function actualizar(Request $request, Suscripcion $suscripcion): JsonResponse
    {
        $datos = $request->validate([
            'modalidad_pago' => ['required', Rule::in(array_keys(config('kernia.modalidades')))],
            'fecha_contratacion' => ['nullable', 'date'],
            'fecha_proximo_pago' => ['required', 'date'],
            'dias_gracia' => ['nullable', 'integer', 'between:0,30'],
            'suspension_automatica' => ['required', 'boolean'],
        ]);

        $antes = $this->presenter->vigencia($suscripcion);
        try {
            $s = $this->vigencias->actualizar($suscripcion, $datos);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $campos = ['modalidad_pago', 'fecha_contratacion', 'fecha_proximo_pago', 'dias_gracia', 'suspension_automatica'];
        Auditoria::registrar('suscripcion.vigencia', null, $s, array_intersect_key($antes, array_flip($campos)), array_intersect_key($this->presenter->vigencia($s), array_flip($campos)));

        return response()->json(['data' => $this->presenter->suscripcion($s)]);
    }

    public function pagos(Suscripcion $suscripcion): JsonResponse
    {
        return response()->json(['data' => $suscripcion->pagos()->with('operador:id,nombre')->orderByDesc('periodo_hasta')->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'fecha_pago' => $p->fecha_pago->toDateString(),
                'periodo_desde' => $p->periodo_desde->toDateString(),
                'periodo_hasta' => $p->periodo_hasta->toDateString(),
                'modalidad' => $p->modalidad,
                'monto' => $p->monto,
                'moneda' => $p->moneda,
                'referencia' => $p->referencia,
                'notas' => $p->notas,
                'registrado_por' => $p->operador?->nombre,
            ])]);
    }

    public function registrarPago(Request $request, Suscripcion $suscripcion): JsonResponse
    {
        $datos = $request->validate([
            'referencia' => ['required', 'string', 'max:120'],
            'fecha_pago' => ['nullable', 'date', 'before_or_equal:'.VigenciaService::hoy()->toDateString()],
            'monto' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'moneda' => ['nullable', Rule::in(['MXN', 'USD'])],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);

        $antes = $this->presenter->vigencia($suscripcion);
        try {
            $r = $this->vigencias->registrarPago($suscripcion, $datos, auth('api')->id());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $s = $suscripcion->fresh();
        Auditoria::registrar('suscripcion.pago', null, $s,
            ['fecha_proximo_pago' => $antes['fecha_proximo_pago'], 'estatus' => $suscripcion->estatus],
            ['pago_id' => $r['pago']->id, 'referencia' => $datos['referencia'], 'monto' => $datos['monto'] ?? null,
                'periodo' => $r['pago']->periodo_desde->toDateString().' a '.$r['pago']->periodo_hasta->toDateString(),
                'fecha_proximo_pago' => $s->fecha_proximo_pago->toDateString(), 'reactivada' => $r['reactivada']]);

        return response()->json([
            'data' => $this->presenter->suscripcion($s),
            'reactivada' => $r['reactivada'],
            'app_confirmo' => $r['app_confirmo'],
        ], 201);
    }
}
