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

    /**
     * Estándar v2 §6.2 (01-oct-2026): la app fija una nueva contraseña
     * temporal al administrador del workspace, obliga a cambiarla y revoca
     * sus sesiones. 204 sin cuerpo; 404 si el correo no es el administrador.
     */
    public function restablecerAdmin(Suscripcion $suscripcion, string $email, string $passwordTemporal): void
    {
        $producto = $suscripcion->producto;

        $respuesta = $this->cliente($producto)
            ->timeout(30)
            ->post($this->url($producto, "clientes/{$suscripcion->cliente->slug}/admin/restablecer"), [
                'email' => $email,
                'password_temporal' => $passwordTemporal,
            ]);

        if ($respuesta->failed() || $respuesta->status() >= 300) {
            throw new RuntimeException(
                "Restablecimiento falló en '{$producto->slug}' ({$respuesta->status()}): ".substr($respuesta->body(), 0, 300)
            );
        }
    }

    // ── Estándar v2.2 (09-oct-2026) ──

    /** GET …/clientes/{slug}/empresas → {data: [{id, rfc, nombre, estado, creada_en}], cuentan_para_limite, max_empresas} */
    public function empresas(Suscripcion $suscripcion): array
    {
        $producto = $suscripcion->producto;
        $respuesta = $this->cliente($producto)->timeout(20)->get($this->url($producto, "clientes/{$suscripcion->cliente->slug}/empresas"));

        return $this->asegurarJson($respuesta, $producto, 'empresas');
    }

    /**
     * POST …/ajuste-plan. Devuelve el código HTTP y el cuerpo: 202/200 se
     * aceptan; 409 (aún no está en mantenimiento) y 422 (regla) los decide quien llama.
     *
     * @return array{status: int, body: array}
     */
    public function ajustePlan(Suscripcion $suscripcion, array $cuerpo, string $idempotencyKey): array
    {
        $producto = $suscripcion->producto;
        $respuesta = $this->cliente($producto, ['Idempotency-Key' => $idempotencyKey])->timeout(30)
            ->post($this->url($producto, "clientes/{$suscripcion->cliente->slug}/ajuste-plan"), $cuerpo);

        return ['status' => $respuesta->status(), 'body' => $respuesta->json() ?? []];
    }

    /** GET …/ajuste-plan/{solicitud_id} → {status, respaldo_id, empresas_bloqueadas, error} */
    public function estadoAjustePlan(Suscripcion $suscripcion, int $solicitudId): array
    {
        $producto = $suscripcion->producto;
        $respuesta = $this->cliente($producto)->timeout(20)->get($this->url($producto, "clientes/{$suscripcion->cliente->slug}/ajuste-plan/{$solicitudId}"));

        return $this->asegurarJson($respuesta, $producto, 'ajuste-plan');
    }

    /** POST …/empresas/desbloquear → 204; 422 si la app rechaza (límite o empresas no bloqueadas). */
    public function desbloquear(Suscripcion $suscripcion, array $empresas): void
    {
        $producto = $suscripcion->producto;
        $respuesta = $this->cliente($producto)->timeout(30)
            ->post($this->url($producto, "clientes/{$suscripcion->cliente->slug}/empresas/desbloquear"), ['empresas' => array_values($empresas)]);

        if ($respuesta->failed() || $respuesta->status() >= 300) {
            throw new RuntimeException("La app rechazó el desbloqueo ({$respuesta->status()}): ".mb_substr($respuesta->body(), 0, 300));
        }
    }

    // ── Estándar v2.3 (09-oct-2026) ──

    /**
     * POST …/clientes/{slug}/exportaciones. La `clave_respaldo` viaja solo en
     * este cuerpo y nunca se registra. Devuelve el código y el cuerpo: 202 y
     * 200 se aceptan (v2.3 §4.2, idempotencia); lo demás lo decide quien llama.
     *
     * @return array{status: int, body: array}
     */
    public function exportar(Suscripcion $suscripcion, array $cuerpo, string $idempotencyKey): array
    {
        $producto = $suscripcion->producto;
        $respuesta = $this->cliente($producto, ['Idempotency-Key' => $idempotencyKey])->timeout(30)
            ->post($this->url($producto, "clientes/{$suscripcion->cliente->slug}/exportaciones"), $cuerpo);

        return ['status' => $respuesta->status(), 'body' => $respuesta->json() ?? []];
    }

    /** GET …/exportaciones/{id} → {status, tamano_bytes, sha256_7z, conteos, disponible_hasta, descargada_en, latido_en, error} */
    public function estadoExportacion(Suscripcion $suscripcion, string $exportacionId): array
    {
        $producto = $suscripcion->producto;
        $respuesta = $this->cliente($producto)->timeout(20)
            ->get($this->url($producto, "clientes/{$suscripcion->cliente->slug}/exportaciones/".rawurlencode($exportacionId)));

        return $this->asegurarJson($respuesta, $producto, 'exportaciones');
    }

    /** DELETE …/exportaciones/{id} (v2.3 §4.4). 204, o 404 si ya no existe: ambos cuentan como borrado. */
    public function eliminarExportacion(Suscripcion $suscripcion, string $exportacionId): void
    {
        $producto = $suscripcion->producto;
        $respuesta = $this->cliente($producto, ['Idempotency-Key' => "eliminar-exportacion-{$exportacionId}"])->timeout(30)
            ->delete($this->url($producto, "clientes/{$suscripcion->cliente->slug}/exportaciones/".rawurlencode($exportacionId)));

        if ($respuesta->status() !== 404 && ($respuesta->failed() || $respuesta->status() >= 300)) {
            throw new RuntimeException("La app no borró la exportación ({$respuesta->status()}).");
        }
    }

    /** POST …/finiquito/eliminar (v2.3 §4.6). @return int código HTTP */
    public function eliminarFiniquito(Suscripcion $suscripcion, int $solicitudId): int
    {
        $producto = $suscripcion->producto;

        return $this->cliente($producto, ['Idempotency-Key' => "finiquito-eliminar-{$solicitudId}"])->timeout(30)
            ->post($this->url($producto, "clientes/{$suscripcion->cliente->slug}/finiquito/eliminar"), ['solicitud_id' => $solicitudId])
            ->status();
    }

    /** GET …/finiquito/eliminar → {status: processing|ready|failed} */
    public function estadoEliminarFiniquito(Suscripcion $suscripcion): array
    {
        $producto = $suscripcion->producto;
        $respuesta = $this->cliente($producto)->timeout(20)->get($this->url($producto, "clientes/{$suscripcion->cliente->slug}/finiquito/eliminar"));

        return $this->asegurarJson($respuesta, $producto, 'finiquito/eliminar');
    }

    /**
     * GET …/exportaciones/{id}/archivo (v2.3 §4.3): el 7z cifrado, en flujo.
     * Kernia lo pasa al operador SIN guardarlo.
     */
    public function archivoExportacion(Suscripcion $suscripcion, string $exportacionId, int $solicitudId): \Psr\Http\Message\StreamInterface
    {
        $producto = $suscripcion->producto;
        $respuesta = $this->cliente($producto, ['X-Solicitud-Id' => (string) $solicitudId])->timeout(600)->withOptions(['stream' => true])
            ->get($this->url($producto, "clientes/{$suscripcion->cliente->slug}/exportaciones/".rawurlencode($exportacionId).'/archivo'));

        if ($respuesta->failed() || $respuesta->status() !== 200) {
            throw new RuntimeException("La app no entregó el archivo ({$respuesta->status()}).");
        }

        return $respuesta->toPsrResponse()->getBody();
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
