<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Producto;
use App\Services\Landlord\PanelPresenter;
use Illuminate\Http\JsonResponse;

/** GET /api/catalogo/productos — apps, sus módulos, planes y extras (para los formularios del panel). */
class CatalogoController extends Controller
{
    public function productos(PanelPresenter $presenter): JsonResponse
    {
        return response()->json([
            'data' => Producto::orderBy('id')->get()->map(fn ($p) => $presenter->producto($p))->all(),
        ]);
    }

    /** GET /api/catalogo/fiscal — listas del SAT para los datos del cliente (02-oct-2026). */
    public function fiscal(): JsonResponse
    {
        return response()->json(['data' => config('catalogos_fiscales')]);
    }
}
