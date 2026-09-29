<?php
namespace App\Services\Landlord;

use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente HTTP saliente de Kernia hacia el backend de CUALQUIER producto
 * (guía §4.2) -- generaliza a TenantAppClient (que solo conocía a
 * bridge-com-api) para las 3 apps. Usa siempre el prefijo /api/internal/v1,
 * confirmado como el que las 3 apps exponen (bare + alias /api, ver
 * RESPUESTA_KERNIA_A_HRM_2026-09-22.md y RESPUESTA_KERNIA_A_SVI_2026-09-22.md).
 *
 * Fuerza `Accept: application/json` y no sigue redirecciones (22-sep-2026):
 * con el token real de HRM, una validación fallida en su ProvisionController
 * respondía con un 302 a "/" en vez de un 422 JSON -- Guzzle lo seguía en
 * silencio, `failed()` nunca era true (3xx no cuenta como error), y
 * `provisionar()` interpretaba la página de bienvenida como éxito. Ver
 * RESPUESTA_HRM_A_CATALOGO_PLANES_KERNIA_2026-09-22.md y el hallazgo del
 * mismo día en la prueba real contra HRM.
 */
class ProductoAppClient
{
    /**
     * @param array{nombre:string, email:string, password_temporal:string} $adminInicial
     * @return array cuerpo JSON de la app: `ref_externa` y, si la app
     *               aprovisiona de forma asíncrona, `status` (ver
     *               RESPUESTA_KERNIA_A_HRM_MULTITENANT_2026-09-28.md §3.3)
     */
    public function provisionar(Suscripcion $suscripcion, array $adminInicial): array
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

        $respuesta = $this->cliente($producto, ['Idempotency-Key' => "suscripcion-{$suscripcion->id}-provision"])
            ->timeout(120)
            ->post($this->url($producto, 'provision'), $payload);

        return $this->asegurarJson($respuesta, $producto, 'provision') ?? [];
    }

    /**
     * Estado de un aprovisionamiento asíncrono (28-sep-2026, HRM con base por
     * cliente). Mismo cuerpo que `provision`. Devuelve null si la app aún no
     * conoce al cliente (404); lanza ante cualquier otro error.
     */
    public function estadoAprovisionamiento(Suscripcion $suscripcion): ?array
    {
        $producto = $suscripcion->producto;

        $respuesta = $this->cliente($producto)
            ->timeout(15)
            ->get($this->url($producto, "provision/{$suscripcion->cliente->id}/status"));

        if ($respuesta->status() === 404) {
            return null;
        }

        return $this->asegurarJson($respuesta, $producto, 'provision/status');
    }

    public function notificarEstatus(Suscripcion $suscripcion, string $estatus, ?string $motivo = null): void
    {
        $producto = $suscripcion->producto;

        $respuesta = $this->cliente($producto)
            ->timeout(30)
            ->patch($this->url($producto, "clientes/{$suscripcion->cliente->slug}/estatus"), [
                'estatus' => $estatus,
                'motivo' => $motivo,
                'efectivo_desde' => now()->toIso8601String(),
            ]);

        if ($respuesta->failed() || $respuesta->status() >= 300) {
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
            $respuesta = $this->cliente($producto)->timeout(15)->get($this->url($producto, "metricas/{$suscripcion->cliente->slug}"));

            return $this->asegurarJson($respuesta, $producto, 'metricas', lanzar: false) ?? ['ok' => false];
        } catch (\Throwable) {
            return ['ok' => false];
        }
    }

    public function health(Producto $producto): array
    {
        try {
            $respuesta = $this->cliente($producto)->timeout(10)->get($this->url($producto, 'health'));

            return $this->asegurarJson($respuesta, $producto, 'health', lanzar: false) ?? ['ok' => false];
        } catch (\Throwable) {
            return ['ok' => false];
        }
    }

    private function cliente(Producto $producto, array $headersExtra = [])
    {
        return Http::withHeaders(['X-Internal-Token' => $producto->token_interno, ...$headersExtra])
            ->acceptJson()
            ->withOptions(['allow_redirects' => false]);
    }

    /**
     * Guzzle no sigue esta redirección (allow_redirects=false), pero un 3xx
     * tampoco cuenta como `failed()` en Laravel -- se valida aquí de forma
     * explícita que la respuesta sea realmente JSON antes de confiar en ella.
     */
    private function asegurarJson(Response $respuesta, Producto $producto, string $endpoint, bool $lanzar = true): ?array
    {
        $esJson = str_contains($respuesta->header('Content-Type') ?? '', 'json');

        if ($respuesta->failed() || $respuesta->status() >= 300 || ! $esJson) {
            if (! $lanzar) {
                return null;
            }

            throw new RuntimeException(
                "{$endpoint} falló en '{$producto->slug}' ({$respuesta->status()}, content-type=".($respuesta->header('Content-Type') ?? 'ninguno')."): ".substr($respuesta->body(), 0, 300)
            );
        }

        return $respuesta->json();
    }

    private function url(Producto $producto, string $ruta): string
    {
        return rtrim($producto->base_url_interna, '/').'/api/internal/v1/'.$ruta;
    }
}
