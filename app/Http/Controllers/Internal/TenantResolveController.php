<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Consumido por bridge-com-api (ResolverTenant) en TODO request tenant, para
 * saber a qué base física conectarse -- reemplaza la lectura local que antes
 * hacía esa app contra su propia conexión 'landlord'. Ver
 * App\Services\KerniaApiClient del lado de bridge-com-api.
 */
class TenantResolveController extends Controller
{
    public function resolver(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', strtolower($slug))->first();

        if (! $tenant) {
            return response()->json(['message' => 'Tenant no encontrado.'], 404);
        }

        return response()->json([
            'estatus'     => $tenant->estatus,
            'db_driver'   => $tenant->db_driver,
            'db_host'     => $tenant->db_host,
            'db_port'     => (int) $tenant->db_port,
            'db_database' => $tenant->db_database,
            'db_username' => $tenant->db_username,
            'db_password' => $tenant->db_password,
        ]);
    }
}
