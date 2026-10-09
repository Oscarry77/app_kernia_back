<?php
namespace App\Http\Middleware;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Prorroga;
use App\Models\Landlord\SolicitudPlan;
use App\Models\Landlord\SolicitudSalida;
use App\Models\Landlord\Exportacion;
use App\Models\Landlord\SolicitudRespaldo;
use App\Models\Landlord\Suscripcion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cartera (fase 3, 02-oct-2026): si la ruta apunta a un cliente, una
 * suscripción, una prórroga o una solicitud, el operador debe poder ver a ese cliente. Un
 * vendedor solo ve su cartera; fuera de ella responde 404, igual que si el
 * registro no existiera (no se revela qué clientes hay).
 *
 * 05-oct-2026: aplica a todos los roles, porque los clientes de prueba solo
 * los ve el superadmin; y cubre las solicitudes de cambio de plan.
 */
class VerificarCartera
{
    public function handle(Request $request, Closure $next): Response
    {
        $operador = $request->user('api');
        if (! $operador || $operador->esSuperadmin()) {
            return $next($request);
        }

        $route = $request->route();
        $cliente = match (true) {
            $route?->parameter('cliente') instanceof Cliente => $route->parameter('cliente'),
            $route?->parameter('suscripcion') instanceof Suscripcion => $route->parameter('suscripcion')->cliente,
            $route?->parameter('prorroga') instanceof Prorroga => $route->parameter('prorroga')->suscripcion->cliente,
            $route?->parameter('solicitud') instanceof SolicitudPlan => $route->parameter('solicitud')->suscripcion->cliente,
            $route?->parameter('salida') instanceof SolicitudSalida => $route->parameter('salida')->suscripcion->cliente,
            $route?->parameter('exportacion') instanceof Exportacion => $route->parameter('exportacion')->suscripcion->cliente,
            $route?->parameter('respaldo') instanceof SolicitudRespaldo => $route->parameter('respaldo')->suscripcion->cliente,
            default => null,
        };

        if ($cliente && ! $operador->puedeVerCliente($cliente)) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        return $next($request);
    }
}
