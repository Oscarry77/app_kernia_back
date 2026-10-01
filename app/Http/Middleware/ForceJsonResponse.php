<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas internas: la respuesta es JSON aunque el caller no mande `Accept`
 * (lineamientos de seguridad §4.3) -- mismo nombre y criterio que HRM,
 * Comercializa y SVI.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
