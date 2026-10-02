<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\Prorroga;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\PanelPresenter;
use App\Services\Landlord\ProrrogaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Prórrogas (fase 3, 02-oct-2026). Solicitar es desde la sesión; autorizar o
 * rechazar exige además el correo y la contraseña de quien autoriza (aunque
 * sea el mismo de la sesión) y su nivel en el escalafón. Reglas en
 * ProrrogaService. La contraseña tecleada nunca se guarda ni se registra.
 */
class ProrrogasController extends Controller
{
    public function __construct(
        private readonly ProrrogaService $prorrogas,
        private readonly PanelPresenter $presenter,
    ) {
    }

    public function motivos(): JsonResponse
    {
        return response()->json([
            'data' => collect(config('kernia_acl.motivos_prorroga'))
                ->map(fn ($nombre, $clave) => ['clave' => $clave, 'nombre' => $nombre])->values(),
            'max_dias' => config('kernia_acl.prorroga_max_dias'),
        ]);
    }

    public function index(Suscripcion $suscripcion): JsonResponse
    {
        return response()->json(['data' => Prorroga::with(['solicitante:id,nombre', 'resolutor:id,nombre'])
            ->where('suscripcion_id', $suscripcion->id)->orderByDesc('id')->get()
            ->map(fn ($p) => $this->fila($p))]);
    }

    /** Solicitudes pendientes de resolver, solo de clientes que el operador puede ver. */
    public function pendientes(Request $request): JsonResponse
    {
        $visibles = Cliente::visiblesPara($request->user('api'))->select('id');

        return response()->json(['data' => Prorroga::with(['solicitante:id,nombre', 'suscripcion.cliente', 'suscripcion.producto'])
            ->where('estado', Prorroga::SOLICITADA)
            ->whereHas('suscripcion', fn ($q) => $q->whereIn('cliente_id', $visibles))
            ->orderBy('id')->get()
            ->map(fn ($p) => [...$this->fila($p),
                'cliente_id' => $p->suscripcion->cliente_id,
                'cliente' => $p->suscripcion->cliente->nombre,
                'producto' => $p->suscripcion->producto->slug,
                'producto_nombre' => $p->suscripcion->producto->nombre,
            ])]);
    }

    public function solicitar(Request $request, Suscripcion $suscripcion): JsonResponse
    {
        $datos = $request->validate([
            'dias' => ['required', 'integer', 'between:1,'.config('kernia_acl.prorroga_max_dias')],
            'motivo' => ['required', Rule::in(array_keys(config('kernia_acl.motivos_prorroga')))],
            'detalle' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $p = $this->prorrogas->solicitar($suscripcion, (int) $datos['dias'], $datos['motivo'], $datos['detalle'] ?? null, $request->user('api'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Auditoria::registrar('prorroga.solicitada', null, $suscripcion, null, ['prorroga_id' => $p->id, 'dias' => $p->dias, 'motivo' => $p->motivo]);

        return response()->json(['data' => $this->fila($p->fresh(['solicitante:id,nombre']))], 201);
    }

    public function resolver(Request $request, Prorroga $prorroga): JsonResponse
    {
        $datos = $request->validate([
            'accion' => ['required', 'in:autorizar,rechazar'],
            'email' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
            'comentario' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $r = $this->prorrogas->resolver($prorroga, $datos['accion'], $datos['email'], $datos['password'],
                $datos['comentario'] ?? null, $request->user('api'), $request->ip());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $p = $r['prorroga'];
        Auditoria::registrar("prorroga.{$p->estado}", null, $p->suscripcion, ['estado' => Prorroga::SOLICITADA], [
            'prorroga_id' => $p->id, 'estado' => $p->estado, 'resuelta_por' => $p->resuelta_por,
            'nivel' => $p->nivel_autorizacion, 'hasta' => $p->hasta?->toDateString(), 'app_confirmo' => $r['app_confirmo'],
        ]);

        return response()->json([
            'data' => $this->fila($p->fresh(['solicitante:id,nombre', 'resolutor:id,nombre'])),
            'suscripcion' => $this->presenter->suscripcion($p->suscripcion->fresh()),
            'app_confirmo' => $r['app_confirmo'],
        ]);
    }

    private function fila(Prorroga $p): array
    {
        return [
            'id' => $p->id,
            'suscripcion_id' => $p->suscripcion_id,
            'fecha_vencimiento' => $p->fecha_vencimiento?->toDateString(),
            'dias' => $p->dias,
            'motivo' => $p->motivo,
            'motivo_nombre' => config("kernia_acl.motivos_prorroga.{$p->motivo}", $p->motivo),
            'detalle' => $p->detalle,
            'estado' => $p->estado,
            'desde' => $p->desde?->toDateString(),
            'hasta' => $p->hasta?->toDateString(),
            'solicitada_por' => $p->solicitante?->nombre,
            'resuelta_por' => $p->resolutor?->nombre,
            'nivel_autorizacion' => $p->nivel_autorizacion,
            'comentario_resolucion' => $p->comentario_resolucion,
            'solicitada_en' => $p->created_at?->toIso8601String(),
            'resuelta_en' => $p->resuelta_en?->toIso8601String(),
        ];
    }
}
