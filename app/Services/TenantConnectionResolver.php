<?php
namespace App\Services;

use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Movido de bridge-com-api el 13-sep-2026, sin cambios de lógica. Kernia lo
 * usa solo para lecturas cross-tenant de solo lectura (métricas) -- nunca
 * para escribir en la base de un tenant (eso pasa siempre por el contrato
 * HTTP interno hacia la app dueña del esquema, ver TenantAppClient).
 */
class TenantConnectionResolver
{
    public static function activarParaTenant(Tenant $tenant): void
    {
        Config::set('database.connections.tenant.driver', $tenant->db_driver);
        Config::set('database.connections.tenant.host', $tenant->db_host);
        Config::set('database.connections.tenant.port', $tenant->db_port);
        Config::set('database.connections.tenant.database', $tenant->db_database);
        Config::set('database.connections.tenant.username', $tenant->db_username);
        Config::set('database.connections.tenant.password', $tenant->db_password);

        DB::purge('tenant');
    }
}
