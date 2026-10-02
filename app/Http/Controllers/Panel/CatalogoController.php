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
}
