<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente HTTP hacia el backend de un producto (hoy solo bridge-com-api,
 * APP Comercializa) para pedirle que se auto-migre/siembre contra una BD de
 * tenant recién creada por Kernia. Kernia solo crea la BD vacía + usuarios
 * (TenantProvisioningService) -- el esquema (migraciones/seeders) es
 * responsabilidad exclusiva del producto dueño de ese esquema, nunca de
 * Kernia (ver plan de extracción de Kernia, 13-sep-2026, Fase 3).
 *
 * Cuando exista un segundo producto SaaS, este cliente se generaliza para
 * resolver la URL/base según a qué producto pertenece el tenant.
 */
class TenantAppClient
{
    public function provisionarTenant(array $conexion): void
    {
        $respuesta = Http::withHeaders([
            'X-Internal-Token' => config('services.bridge_com_api.internal_token'),
        ])
            ->timeout(120)
            ->post(config('services.bridge_com_api.url').'/internal/tenants/provision', $conexion);

        if ($respuesta->failed()) {
            throw new RuntimeException(
                'bridge-com-api no pudo migrar/sembrar la base del tenant: '.$respuesta->body()
            );
        }
    }

    public function listarEmpresas(array $conexion): array
    {
        $respuesta = Http::withHeaders([
            'X-Internal-Token' => config('services.bridge_com_api.internal_token'),
        ])
            ->timeout(30)
            ->send('GET', config('services.bridge_com_api.url').'/internal/empresas', ['json' => $conexion]);

        if ($respuesta->failed()) {
            throw new RuntimeException(
                'bridge-com-api no pudo listar las empresas del tenant: '.$respuesta->body()
            );
        }

        return $respuesta->json('data', []);
    }

    public function actualizarWsCntpaq(array $conexion, int $empresaId, array $credenciales): array
    {
        $respuesta = Http::withHeaders([
            'X-Internal-Token' => config('services.bridge_com_api.internal_token'),
        ])
            ->timeout(30)
            ->put(
                config('services.bridge_com_api.url')."/internal/empresas/{$empresaId}/ws-cntpaq",
                $conexion + $credenciales
            );

        if ($respuesta->failed()) {
            throw new RuntimeException(
                'bridge-com-api no pudo actualizar las credenciales WS-CNTPAQ: '.$respuesta->body()
            );
        }

        return $respuesta->json('data', []);
    }
}
