<?php
namespace App\Services\Landlord;

use App\Models\Landlord\Tenant;
use App\Services\TenantConnectionResolver;
use Illuminate\Support\Facades\DB;

/**
 * Métricas de uso por tenant. Movido de bridge-com-api el 13-sep-2026, sin
 * cambios de lógica: solo lectura (query builder crudo sobre la conexión
 * 'tenant', nunca Eloquent de modelos de tenant) -- este panel jamás debe
 * poder escribir en la base de un cliente.
 */
class LandlordTenantMetricsService
{
    public function obtener(Tenant $tenant): array
    {
        try {
            TenantConnectionResolver::activarParaTenant($tenant);

            $conexion = DB::connection('tenant');

            return [
                'ok'                          => true,
                'usuarios_total'              => $conexion->table('com_usuarios')->count(),
                'usuarios_activos'            => $conexion->table('com_usuarios')->where('activo', true)->count(),
                'clientes_total'              => $conexion->table('clientes')->count(),
                'productos_total'             => $conexion->table('productos')->count(),
                'documentos_total'            => $conexion->table('documentos')->count(),
                'documentos_ultimos_30_dias'  => $conexion->table('documentos')
                    ->where('created_at', '>=', now()->subDays(30))
                    ->count(),
                'ultimo_acceso'               => $conexion->table('com_usuarios')->max('ultimo_acceso'),
            ];
        } catch (\Throwable $e) {
            report($e);

            return [
                'ok'    => false,
                'error' => 'No se pudo conectar a la base de este tenant.',
            ];
        }
    }
}
