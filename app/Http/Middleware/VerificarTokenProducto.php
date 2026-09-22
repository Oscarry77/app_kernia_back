<?php
namespace App\Http\Middleware;

use App\Models\Landlord\Producto;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege /internal/v1/productos/{producto}/resolve/{slug} y el resto de las
 * rutas v1. El token debe corresponder exactamente al {producto} del path
 * (guía §4.1: "un token de un producto no puede resolver suscripciones de
 * otro"). Producto inexistente en el catálogo -> 404 (el slug de producto es
 * público, a diferencia del slug de cliente). Token ausente/incorrecto -> 401
 * genérico, sin detalles.
 */
class VerificarTokenProducto
{
    public function handle(Request $request, Closure $next): Response
    {
        $productoSlug = $request->route('producto');
        $producto = Producto::where('slug', $productoSlug)->first();

        if (! $producto) {
            return response()->json(['message' => 'Producto no encontrado.'], 404);
        }

        $token = $request->header('X-Internal-Token');

        if (! $producto->token_interno || ! $token || ! hash_equals($producto->token_interno, $token)) {
            return response()->json(['message' => 'Token interno inválido o ausente.'], 401);
        }

        $request->attributes->set('producto', $producto);

        return $next($request);
    }
}
