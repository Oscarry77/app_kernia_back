<?php
namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /internal/v1/productos/{producto}/clientes -- estándar v2 §5.3
 * (01-oct-2026). Para las tareas programadas de una app que deben correr
 * por cliente (p. ej. `documentos:cancelar-vencidos` de Comercializa): la app
 * no mantiene su propia lista de clientes, se la pide a Kernia.
 *
 * Solo clientes con estatus EFECTIVO `activo` en ese producto. Sin conexión
 * ni secretos: para operar sobre cada uno, la app hace su `resolve` normal.
 */
class ProductoClientesController extends Controller
{
    public function __invoke(Request $request, string $producto): JsonResponse
    {
        /** @var Producto $producto */
        $producto = $request->attributes->get('producto');

        $clientes = Suscripcion::with('cliente')
            ->where('producto_id', $producto->id)
            ->where('estatus', Suscripcion::ESTATUS_ACTIVO)
            ->get()
            ->filter(fn (Suscripcion $s) => $s->estatusEfectivo() === Suscripcion::ESTATUS_ACTIVO)
            ->map(fn (Suscripcion $s) => ['id' => $s->cliente->id, 'slug' => $s->cliente->slug])
            ->sortBy('slug')
            ->values();

        return response()->json(['clientes' => $clientes]);
    }
}
