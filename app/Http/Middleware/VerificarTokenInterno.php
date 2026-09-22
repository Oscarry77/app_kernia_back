<?php
namespace App\Http\Middleware;

use App\Models\Landlord\Producto;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege el alias legacy /internal/tenants/resolve/{slug} (solo lo consume
 * bridge-com-api). Hasta el 22-sep-2026 validaba contra un secreto fijo en
 * .env (services.bridge_com_api.internal_token); ahora valida contra
 * productos.token_interno (slug 'comercializa'), la misma fuente de verdad
 * que usará el endpoint /internal/v1/productos/{producto}/resolve/{slug} --
 * evita dos secretos distintos para el mismo producto. Ver
 * RESPUESTA_COMERCIALIZA_ACL_A_KERNIA_2026-09-22.md §3.
 */
class VerificarTokenInterno
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Internal-Token');
        $esperado = Producto::where('slug', 'comercializa')->first()?->token_interno;

        if (! $esperado || ! $token || ! hash_equals($esperado, $token)) {
            return response()->json(['message' => 'Token interno inválido o ausente.'], 401);
        }

        return $next($request);
    }
}
