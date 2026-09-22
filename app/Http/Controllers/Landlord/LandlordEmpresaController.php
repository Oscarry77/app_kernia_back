<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use App\Services\TenantAppClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Gestión cross-tenant de credenciales WS-CNTPAQ.Net desde Kernia (Fase 4 del
 * plan de extracción, 13-sep-2026). Nunca toca la BD del tenant directo --
 * todo pasa por el contrato HTTP interno de bridge-com-api, que es el único
 * que conoce el esquema y cifrado del modelo Empresa.
 */
class LandlordEmpresaController extends Controller
{
    public function index(Tenant $tenant, TenantAppClient $tenantApp): JsonResponse
    {
        try {
            $empresas = $tenantApp->listarEmpresas($tenant->datosConexion());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $empresas]);
    }

    public function actualizarWsCntpaq(Request $request, Tenant $tenant, int $empresa, TenantAppClient $tenantApp): JsonResponse
    {
        $credenciales = $request->validate([
            'ws_cntpaq_base_url'   => ['nullable', 'url', 'max:255'],
            'ws_cntpaq_key_id'     => ['nullable', 'string', 'max:100'],
            'ws_cntpaq_api_secret' => ['nullable', 'string', 'max:500'],
            'ws_cntpaq_cert_pem'   => ['nullable', 'string', 'starts_with:-----BEGIN CERTIFICATE-----'],
        ]);

        try {
            $resultado = $tenantApp->actualizarWsCntpaq($tenant->datosConexion(), $empresa, $credenciales);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $resultado]);
    }
}
