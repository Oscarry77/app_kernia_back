<?php
namespace App\Services\Landlord;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use App\Services\TenantProvisioningService;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Alta de una suscripción (Cliente x Producto) contra el contrato v1 (guía
 * §4.2 y §5). Generaliza a TenantOnboardingService (que solo conocía el
 * modelo viejo de Comercializa vía `tenants`) al modelo nuevo
 * clientes/productos/suscripciones, para las 3 apps.
 *
 * Modo dedicada: Kernia crea la base física + usuario de app (mismo
 * TenantProvisioningService de siempre, PDO puro) ANTES de llamar a la app.
 * Modo compartida: no hay `conexion`, la app crea su propio tenant local y
 * devuelve `ref_externa`.
 *
 * Falla -> la suscripción queda `fallido`, registrada y reintentable (nunca
 * `activo` a medias, guía §4.2).
 */
class SuscripcionOnboardingService
{
    public function __construct(private readonly ProductoAppClient $appClient)
    {
    }

    /**
     * `password_temporal` es opcional: si el caller la manda (el comando de
     * alta, para mostrarla UNA vez al operador -- 30-sep-2026) se usa esa;
     * si no, se genera aquí. Kernia nunca la guarda.
     *
     * @param array{nombre:string, email:string, password_temporal?:string} $adminInicial
     */
    public function aprovisionar(Cliente $cliente, Producto $producto, array $adminInicial, ?string $plan = null): Suscripcion
    {
        if (Suscripcion::where('cliente_id', $cliente->id)->where('producto_id', $producto->id)->exists()) {
            throw new RuntimeException(
                "Ya existe una suscripción de '{$cliente->slug}' a '{$producto->slug}'. Si quedó 'fallido', usa landlord:reintentar-aprovisionamiento."
            );
        }

        // Estándar v2.1: con catálogo de planes, el plan es obligatorio y se
        // valida ANTES de crear base o llamar a la app.
        if ($producto->usaPlanes() && ! $producto->plan($plan)) {
            $validos = $producto->planes()->where('activo', true)->orderBy('orden')->pluck('codigo')->implode(', ');
            throw new RuntimeException("'{$producto->slug}' requiere un plan válido (--plan=). Válidos: {$validos}.");
        }

        $datosConexion = $producto->esDedicada()
            ? $this->crearBaseDedicada($cliente, $producto)
            : [];

        $suscripcion = Suscripcion::create([
            'cliente_id' => $cliente->id,
            'producto_id' => $producto->id,
            'estatus' => Suscripcion::ESTATUS_EN_APROVISIONAMIENTO,
            'plan' => $plan,
            'admin_email' => $adminInicial['email'],
            'fecha_contratacion' => now()->toDateString(),
            ...$datosConexion,
        ]);

        // Los módulos salen del plan (v2.1), nunca se eligen a mano.
        if ($producto->usaPlanes()) {
            app(SuscripcionPlanService::class)->aplicarPlan($suscripcion, $plan);
        }

        return $this->llamarProvision($suscripcion, $adminInicial['nombre'], $adminInicial['password_temporal'] ?? null);
    }

    /**
     * Reintenta una suscripción `fallido` con el MISMO Idempotency-Key
     * (`suscripcion-{id}-provision`): la app no duplica tenant/base/semillas.
     * La password temporal sí es nueva -- Kernia no la guarda; la app debe
     * ignorarla si el tenant ya existe (RESPUESTA_KERNIA_A_HRM_MULTITENANT
     * _2026-09-28.md §3.5). La base dedicada, si aplica, ya se creó en el
     * primer intento y no se vuelve a crear.
     */
    public function reintentar(Suscripcion $suscripcion, string $adminNombre, ?string $passwordTemporal = null): Suscripcion
    {
        if ($suscripcion->estatus !== Suscripcion::ESTATUS_FALLIDO) {
            throw new RuntimeException(
                "Solo se reintenta una suscripción 'fallido' (id={$suscripcion->id} está '{$suscripcion->estatus}')."
            );
        }

        $suscripcion->update(['estatus' => Suscripcion::ESTATUS_EN_APROVISIONAMIENTO]);

        return $this->llamarProvision($suscripcion, $adminNombre, $passwordTemporal);
    }

    /**
     * Polling de un aprovisionamiento asíncrono. Devuelve la suscripción con
     * su estatus actualizado; si la app aún no la conoce (404) o sigue
     * preparando la base, no cambia nada.
     */
    public function sincronizar(Suscripcion $suscripcion): Suscripcion
    {
        $cuerpo = $this->appClient->estadoAprovisionamiento($suscripcion);

        if ($cuerpo !== null) {
            $this->aplicarRespuesta($suscripcion, $cuerpo);
        }

        return $suscripcion->fresh();
    }

    private function llamarProvision(Suscripcion $suscripcion, string $adminNombre, ?string $passwordTemporal): Suscripcion
    {
        try {
            $cuerpo = $this->appClient->provisionar($suscripcion, [
                'nombre' => $adminNombre,
                'email' => $suscripcion->admin_email,
                'password_temporal' => $passwordTemporal ?? self::generarPasswordTemporal(),
            ]);
        } catch (Throwable $e) {
            $suscripcion->update(['estatus' => Suscripcion::ESTATUS_FALLIDO]);

            throw new RuntimeException(
                "Provisión falló, la suscripción (id={$suscripcion->id}) quedó 'fallido', registrada y reintentable: {$e->getMessage()}"
            );
        }

        $this->aplicarRespuesta($suscripcion, $cuerpo);

        return $suscripcion->fresh();
    }

    /**
     * `status` en el nivel superior decide (28-sep-2026, HRM asíncrono):
     * ready -> activo; pending/provisioning -> se queda en aprovisionamiento
     * (lo cierra el polling); failed -> fallido. Sin `status` es una app
     * síncrona (Comercializa, SVI): 2xx ya significa listo.
     */
    private function aplicarRespuesta(Suscripcion $suscripcion, array $cuerpo): void
    {
        $status = $cuerpo['status'] ?? 'ready';
        $cambios = [];

        if (isset($cuerpo['ref_externa'])) {
            $cambios['ref_externa'] = (string) $cuerpo['ref_externa'];
        }

        $cambios += match ($status) {
            'ready' => ['estatus' => Suscripcion::ESTATUS_ACTIVO, 'provisionada_en' => now()],
            'failed' => ['estatus' => Suscripcion::ESTATUS_FALLIDO],
            default => ['estatus' => Suscripcion::ESTATUS_EN_APROVISIONAMIENTO],
        };

        $suscripcion->update($cambios);
    }

    /** @return array<string,string> columnas db_* para Suscripcion::create() */
    private function crearBaseDedicada(Cliente $cliente, Producto $producto): array
    {
        $adminUsuario = env('TENANT_PROVISION_DB_ADMIN_USERNAME');
        $adminPassword = env('TENANT_PROVISION_DB_ADMIN_PASSWORD');

        if (! $adminUsuario || ! $adminPassword) {
            throw new RuntimeException(
                'Faltan TENANT_PROVISION_DB_ADMIN_USERNAME/PASSWORD en .env para aprovisionar en modo dedicada.'
            );
        }

        $host = env('TENANT_PROVISION_DB_HOST', '127.0.0.1');
        $puerto = (int) env('TENANT_PROVISION_DB_PORT', 3306);
        $baseDatos = self::nombreBaseDatos($producto->prefijo_db, $cliente->slug);
        $usuarioApp = TenantProvisioningService::generarUsuario($baseDatos, 'app');
        $passwordApp = TenantProvisioningService::generarPassword();

        TenantProvisioningService::crearBaseYUsuarios(
            driver: 'mysql',
            host: $host,
            puerto: $puerto,
            adminUsuario: $adminUsuario,
            adminPassword: $adminPassword,
            baseDatos: $baseDatos,
            usuarioApp: $usuarioApp,
            passwordApp: $passwordApp,
        );

        return [
            'db_driver' => 'mysql',
            'db_host' => $host,
            'db_port' => (string) $puerto,
            'db_database' => $baseDatos,
            'db_username' => $usuarioApp,
            'db_password' => $passwordApp,
        ];
    }

    /**
     * `clientes.slug` admite guiones (guía §2: 3-63 [a-z0-9-]), pero un
     * identificador de base de datos no -- se limpia aquí, nunca en el slug
     * mismo (ese sigue siendo el de cara al cliente/URL). Público y estático
     * para poder probarlo sin tocar MySQL.
     */
    public static function nombreBaseDatos(string $prefijoDb, string $slugCliente): string
    {
        $slugLimpio = preg_replace('/[^a-z0-9]/', '', strtolower($slugCliente));

        return "{$prefijoDb}_{$slugLimpio}";
    }

    /**
     * `Str::random()` no garantiza mayúscula+minúscula+número -- encontrado
     * en la prueba real contra HRM (22-sep-2026): su validación de
     * complejidad rechazó una temporal generada así, aunque era poco
     * probable, no imposible. Se garantiza aquí, no solo se espera por azar.
     */
    public static function generarPasswordTemporal(): string
    {
        $mayusculas = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $minusculas = 'abcdefghijkmnpqrstuvwxyz';
        $numeros = '23456789';
        // Símbolos seguros en JSON/shell/URL -- sin comillas, backslash ni &.
        $simbolos = '!@#%*-_=+';

        $obligatorios = [
            $mayusculas[random_int(0, strlen($mayusculas) - 1)],
            $minusculas[random_int(0, strlen($minusculas) - 1)],
            $numeros[random_int(0, strlen($numeros) - 1)],
            $simbolos[random_int(0, strlen($simbolos) - 1)],
        ];

        $caracteres = array_merge($obligatorios, str_split(Str::random(12)));
        shuffle($caracteres);

        return implode('', $caracteres);
    }
}
