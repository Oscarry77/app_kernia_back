<?php
namespace App\Http\Middleware;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Prorroga;
use App\Models\Landlord\Suscripcion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cartera (fase 3, 02-oct-2026): si la ruta apunta a un cliente, una
 * suscripción o una prórroga, el operador debe poder ver a ese cliente. Un
 * vendedor solo ve su cartera; fuera de ella responde 404, igual que si el
 * registro no existiera (no se revela qué clientes hay).
 */
class VerificarCartera
{
    public function handle(Request $request, Closure $next): Response
    {
        $operador = $request->user('api');
        if (! $operador || ! $operador->tieneCartera()) {
            return $next($request);
        }

        $route = $request->route();
        $cliente = match (true) {
            $route?->parameter('cliente') instanceof Cliente => $route->parameter('cliente'),
            $route?->parameter('suscripcion') instanceof Suscripcion => $route->parameter('suscripcion')->cliente,
            $route?->parameter('prorroga') instanceof Prorroga => $route->parameter('prorroga')->suscripcion->cliente,
            default => null,
        };

        if ($cliente && ! $operador->puedeVerCliente($cliente)) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return $next($request);
    }
}
