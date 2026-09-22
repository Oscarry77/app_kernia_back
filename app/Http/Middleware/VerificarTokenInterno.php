<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege las rutas internas (/internal/*) que consumen los backends de
 * producto (hoy solo bridge-com-api) -- nunca JWT de usuario, un secreto
 * compartido fijo por .env (INTERNAL_SERVICE_TOKEN) en ambos lados.
 */
class VerificarTokenInterno
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Internal-Token');
        $esperado = config('services.bridge_com_api.internal_token');

        if (! $esperado || ! $token || ! hash_equals($esperado, $token)) {
            return response()->json(['message' => 'Token interno inválido o ausente.'], 401);
        }

        return $next($request);
    }
}
