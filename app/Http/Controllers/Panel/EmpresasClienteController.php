<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\BajaPlanService;
use App\Services\Landlord\SuscripcionPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * (09-oct-2026) Expediente de empresas del cliente en una app v2.2: la lista
 * la entrega la app en vivo (Kernia no la guarda) y el desbloqueo pasa por la
 * auditoría de licencia de Kernia.
 */
class EmpresasClienteController extends Controller
{
    public function __construct(private readonly BajaPlanService $bajas)
    {
    }

    public function index(Suscripcion $suscripcion): JsonResponse
    {
        try {
            $lista = $this->bajas->empresas($suscripcion);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([...$lista,
            // El límite contratado según Kernia (plan + extras), para compararlo con el que aplica la app.
            'max_empresas_kernia' => app(SuscripcionPlanService::class)->limitesEfectivos($suscripcion)['max_empresas'] ?? null,
        ]);
    }

    public function desbloquear(Request $request, Suscripcion $suscripcion): JsonResponse
    {
        $datos = $request->validate([
            'empresas' => ['required', 'array', 'min:1', 'max:500'],
            'empresas.*' => ['integer'],
            'motivo' => ['required', 'string', 'max:500'],
        ]);

        try {
            $lista = $this->bajas->desbloquear($suscripcion, $datos['empresas'], $datos['motivo'], $request->user('api'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($lista);
    }
}
