<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use App\Services\Landlord\LandlordTenantMetricsService;
use App\Services\Landlord\TenantOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class LandlordTenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenants = Tenant::orderBy('nombre_cliente')->paginate(
            $request->integer('per_page', 20)
        );

        return response()->json($tenants);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json(['data' => $tenant]);
    }

    public function store(Request $request, TenantOnboardingService $onboarding): JsonResponse
    {
        $datos = $request->validate([
            'slug'             => ['required', 'string', 'max:63', 'alpha_dash'],
            'nombre'           => ['required', 'string', 'max:255'],
            'db_host'          => ['required', 'string', 'max:255'],
            'db_database'      => ['required', 'string', 'max:255'],
            'db_username'      => ['nullable', 'string', 'max:255'],
            'db_password'      => ['nullable', 'string', 'max:255'],
            'puerto'           => ['nullable', 'integer'],
            'driver'           => ['required', 'in:sqlsrv,mysql'],
            'auto_provisionar' => ['nullable', 'boolean'],
            'con_bi'           => ['nullable', 'boolean'],
        ]);

        try {
            $resultado = $onboarding->aprovisionar($datos);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data'        => $resultado['tenant'],
            'usuario_bi'  => $resultado['usuario_bi'],
            'password_bi' => $resultado['password_bi'],
        ], 201);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $datos = $request->validate([
            'nombre_cliente' => ['sometimes', 'required', 'string', 'max:255'],
            'plan'           => ['sometimes', 'nullable', 'string', 'max:60'],
            'notas'          => ['sometimes', 'nullable', 'string'],
        ]);

        $tenant->update($datos);

        return response()->json(['data' => $tenant->fresh()]);
    }

    public function cambiarEstatus(Request $request, Tenant $tenant): JsonResponse
    {
        $datos = $request->validate([
            'estatus' => ['required', 'in:' . Tenant::ESTATUS_ACTIVO . ',' . Tenant::ESTATUS_SUSPENDIDO],
        ]);

        if ($tenant->estatus === Tenant::ESTATUS_EN_APROVISIONAMIENTO) {
            return response()->json([
                'message' => 'Este tenant sigue en aprovisionamiento -- espera a que termine antes de cambiar su estatus.',
            ], 422);
        }

        $tenant->update(['estatus' => $datos['estatus']]);

        return response()->json(['data' => $tenant->fresh()]);
    }

    public function metricas(Tenant $tenant, LandlordTenantMetricsService $metricas): JsonResponse
    {
        return response()->json(['data' => $metricas->obtener($tenant)]);
    }
}
