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
     * @param array{nombre:string, email:string} $adminInicial
     */
    public function aprovisionar(Cliente $cliente, Producto $producto, array $adminInicial, ?string $plan = null): Suscripcion
    {
        if (Suscripcion::where('cliente_id', $cliente->id)->where('producto_id', $producto->id)->exists()) {
            throw new RuntimeException(
                "Ya existe una suscripción de '{$cliente->slug}' a '{$producto->slug}'."
            );
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

        $passwordTemporal = Str::random(16);

        try {
            $refExterna = $this->appClient->provisionar($suscripcion, [
                'nombre' => $adminInicial['nombre'],
                'email' => $adminInicial['email'],
                'password_temporal' => $passwordTemporal,
            ]);
        } catch (Throwable $e) {
            $suscripcion->update(['estatus' => Suscripcion::ESTATUS_FALLIDO]);

            throw new RuntimeException(
                "Provisión falló, la suscripción (id={$suscripcion->id}) quedó 'fallido', registrada y reintentable: {$e->getMessage()}"
            );
        }

        $suscripcion->update([
            'estatus' => Suscripcion::ESTATUS_ACTIVO,
            'ref_externa' => $refExterna,
            'provisionada_en' => now(),
        ]);

        return $suscripcion->fresh();
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
        $baseDatos = "{$producto->prefijo_db}_{$cliente->slug}";
        $usuarioApp = TenantProvisioningService::generarUsuario("{$producto->prefijo_db}_{$cliente->slug}", 'app');
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
}
