<?php
namespace App\Services\Landlord;

use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente HTTP saliente de Kernia hacia el backend de CUALQUIER producto
 * (guía §4.2) -- generaliza a TenantAppClient (que solo conocía a
 * bridge-com-api) para las 3 apps. Usa siempre el prefijo /api/internal/v1,
 * confirmado como el que las 3 apps exponen (bare + alias /api, ver
 * RESPUESTA_KERNIA_A_HRM_2026-09-22.md y RESPUESTA_KERNIA_A_SVI_2026-09-22.md).
 */
class ProductoAppClient
{
    /**
     * @param array{nombre:string, email:string, password_temporal:string} $adminInicial
     * @return string|null ref_externa que devuelve la app (null si no aplica)
     */
    public function provisionar(Suscripcion $suscripcion, array $adminInicial): ?string
    {
        $producto = $suscripcion->producto;

        $payload = [
            'cliente' => [
                'id' => $suscripcion->cliente->id,
                'slug' => $suscripcion->cliente->slug,
                'nombre' => $suscripcion->cliente->nombre,
            ],
            'plan' => $suscripcion->plan,
            'admin_inicial' => $adminInicial,
        ];

        if ($producto->esDedicada()) {
            $payload['conexion'] = $suscripcion->datosConexion();
        }

        $respuesta = Http::withHeaders([
                'X-Internal-Token' => $producto->token_interno,
                'Idempotency-Key' => "suscripcion-{$suscripcion->id}-provision",
            ])
            ->timeout(120)
            ->post($this->url($producto, 'provision'), $payload);

        if ($respuesta->failed()) {
            throw new RuntimeException(
                "provision falló en '{$producto->slug}' ({$respuesta->status()}): {$respuesta->body()}"
            );
        }

        return $respuesta->json('ref_externa');
    }

    public function notificarEstatus(Suscripcion $suscripcion, string $estatus, ?string $motivo = null): void
    {
        $producto = $suscripcion->producto;

        $respuesta = Http::withHeaders(['X-Internal-Token' => $producto->token_interno])
            ->timeout(30)
            ->patch($this->url($producto, "clientes/{$suscripcion->cliente->slug}/estatus"), [
                'estatus' => $estatus,
                'motivo' => $motivo,
                'efectivo_desde' => now()->toIso8601String(),
            ]);

        if ($respuesta->failed()) {
            throw new RuntimeException(
                "Notificación de estatus falló en '{$producto->slug}' ({$respuesta->status()}): {$respuesta->body()}"
            );
        }
    }

    /** Nunca lanza -- degrada a {ok:false} (guía §4.2). */
    public function metricas(Suscripcion $suscripcion): array
    {
        $producto = $suscripcion->producto;

        try {
            $respuesta = Http::withHeaders(['X-Internal-Token' => $producto->token_interno])
                ->timeout(15)
                ->get($this->url($producto, "metricas/{$suscripcion->cliente->slug}"));
        } catch (\Throwable) {
            return ['ok' => false];
        }

        return $respuesta->failed() ? ['ok' => false] : $respuesta->json();
    }

    public function health(Producto $producto): array
    {
        try {
            $respuesta = Http::withHeaders(['X-Internal-Token' => $producto->token_interno])
                ->timeout(10)
                ->get($this->url($producto, 'health'));
        } catch (\Throwable) {
            return ['ok' => false];
        }

        return $respuesta->failed() ? ['ok' => false] : $respuesta->json();
    }

    private function url(Producto $producto, string $ruta): string
    {
        return rtrim($producto->base_url_interna, '/').'/api/internal/v1/'.$ruta;
    }
}
