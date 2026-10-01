<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\SuscripcionPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /internal/v1/productos/{producto}/resolve/{slug} -- guía
 * GUIA_INTEGRACION_APP_KERNIA_v1.md §4.1. El {producto} ya viene validado
 * contra el token por VerificarTokenProducto (attributes.producto).
 *
 * `aviso` siempre viaja null por ahora: el motor de recordatorios/prórrogas
 * (guía §5.1 -- scheduler, plantillas_notificacion, prorrogas) todavía no se
 * construye. Las apps ya están preparadas para recibirlo nullable.
 */
class ProductoResolveController extends Controller
{
    public function resolver(Request $request, string $producto, string $slug): JsonResponse
    {
        /** @var Producto $producto */
        $producto = $request->attributes->get('producto');

        $cliente = Cliente::where('slug', strtolower($slug))->first();

        $suscripcion = $cliente
            ? Suscripcion::where('cliente_id', $cliente->id)
                ->where('producto_id', $producto->id)
                ->first()
            : null;

        // Mismo mensaje para "cliente no existe" y "no tiene este producto"
        // -- no se revela cuál de los dos casos ocurrió (guía §4.1).
        if (! $cliente || ! $suscripcion) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        $respuesta = [
            'cliente' => [
                'id' => $cliente->id,
                'slug' => $cliente->slug,
                'nombre' => $cliente->nombre,
            ],
            'suscripcion' => [
                'estatus' => $suscripcion->estatusEfectivo(),
                'plan' => $suscripcion->plan,
                'modo_datos' => $producto->modo_datos,
                'ref_externa' => $suscripcion->ref_externa,
                'fecha_proximo_pago' => $suscripcion->fecha_proximo_pago?->toDateString(),
            ],
            'aviso' => null,
        ];

        $modulos = $suscripcion->clavesModulosActivos();
        if ($modulos !== null) {
            $respuesta['modulos'] = $modulos;
        }

        // Estándar v2.1: límites efectivos (plan + extras). La app los aplica;
        // ausente si el producto no tiene catálogo o la suscripción no tiene plan.
        $limites = app(SuscripcionPlanService::class)->limitesEfectivos($suscripcion);
        if ($limites !== null) {
            $respuesta['limites'] = $limites;
        }

        if ($producto->esDedicada()) {
            $respuesta['conexion'] = $suscripcion->datosConexion();
        }

        return response()->json($respuesta);
    }
}
