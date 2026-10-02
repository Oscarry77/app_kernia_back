<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bitácora del panel (fase 3, 02-oct-2026), filtrable por cliente, suscripción, operador y acción. */
class AuditoriaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'cliente_id' => ['nullable', 'integer'],
            'suscripcion_id' => ['nullable', 'integer'],
            'usuario_id' => ['nullable', 'integer'],
            'accion' => ['nullable', 'string', 'max:60'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ]);

        $pagina = Auditoria::with('usuario:id,nombre')
            ->when($f['cliente_id'] ?? null, fn ($q, $v) => $q->where('cliente_id', $v))
            ->when($f['suscripcion_id'] ?? null, fn ($q, $v) => $q->where('suscripcion_id', $v))
            ->when($f['usuario_id'] ?? null, fn ($q, $v) => $q->where('usuario_id', $v))
            ->when($f['accion'] ?? null, fn ($q, $v) => $q->where('accion', 'like', "{$v}%"))
            ->orderByDesc('id')
            ->paginate(50, ['*'], 'pagina', $f['pagina'] ?? 1);

        return response()->json([
            'data' => collect($pagina->items())->map(fn ($a) => [
                'id' => $a->id,
                'fecha' => $a->created_at?->toIso8601String(),
                'usuario' => $a->usuario?->nombre,
                'accion' => $a->accion,
                'cliente_id' => $a->cliente_id,
                'suscripcion_id' => $a->suscripcion_id,
                'antes' => $a->antes,
                'despues' => $a->despues,
                'ip' => $a->ip,
            ]),
            'pagina' => $pagina->currentPage(),
            'paginas' => $pagina->lastPage(),
            'total' => $pagina->total(),
        ]);
    }
}
