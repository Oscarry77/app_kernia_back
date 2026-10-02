<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `permiso:clave` (fase 3, 02-oct-2026): el operador autenticado debe tener
 * el permiso en su rol (config/kernia_acl.php). Denegación por defecto.
 */
class VerificarPermiso
{
    public function handle(Request $request, Closure $next, string $permiso): Response
    {
        $operador = $request->user('api');

        if (! $operador || ! $operador->activo || ! $operador->puede($permiso)) {
            return response()->json(['message' => 'No tienes permiso para esta acción.', 'codigo' => 'SIN_PERMISO'], 403);
        }

        return $next($request);
    }
}
