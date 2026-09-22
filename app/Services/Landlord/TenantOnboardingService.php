<?php
namespace App\Services\Landlord;

use App\Models\Landlord\Tenant;
use App\Services\TenantAppClient;
use App\Services\TenantProvisioningService;
use RuntimeException;

/**
 * Orquesta el alta completa de un tenant. Reescrito el 13-sep-2026 al mudar
 * este servicio de bridge-com-api a kernia-api (extracción de Kernia): antes
 * corría `Artisan::call('migrate'/'db:seed')` del propio esquema de producto
 * desde el lado landlord -- eso ya no es posible ni correcto con Kernia como
 * servicio independiente, que no conoce el esquema de ningún producto.
 *
 * Ahora la responsabilidad se invierte: Kernia solo crea la BD vacía +
 * usuarios (TenantProvisioningService, PDO puro) y registra el Tenant, y le
 * pide al backend del producto (TenantAppClient) que se migre/siembre a sí
 * mismo contra esa BD nueva.
 */
class TenantOnboardingService
{
    public function __construct(private readonly TenantAppClient $tenantAppClient)
    {
    }

    /**
     * @param array{
     *   slug: string, nombre: string, db_host: string, db_database: string,
     *   db_username?: ?string, db_password?: ?string, puerto?: int, driver?: string,
     *   auto_provisionar?: bool, con_bi?: bool,
     * } $datos
     * @return array{tenant: Tenant, usuario_bi: ?string, password_bi: ?string}
     *
     * @throws RuntimeException si el slug ya existe, faltan credenciales, o falla el
     *         aprovisionamiento/migraciones/seeders (el tenant queda registrado pero
     *         SUSPENDIDO en ese último caso, nunca a medias sin registro).
     */
    public function aprovisionar(array $datos): array
    {
        $slug = strtolower($datos['slug']);
        $driver = $datos['driver'] ?? 'sqlsrv';
        $autoProvisionar = (bool) ($datos['auto_provisionar'] ?? false);
        $conBi = (bool) ($datos['con_bi'] ?? false);

        if (! in_array($driver, ['sqlsrv', 'mysql'], true)) {
            throw new RuntimeException("El driver debe ser 'sqlsrv' o 'mysql'.");
        }

        if (Tenant::where('slug', $slug)->exists()) {
            throw new RuntimeException("Ya existe un tenant con el slug '{$slug}'.");
        }

        $dbUsername = $datos['db_username'] ?? null;
        $dbPassword = $datos['db_password'] ?? null;

        if ($autoProvisionar) {
            $dbUsername ??= TenantProvisioningService::generarUsuario($slug, 'app');
            $dbPassword ??= TenantProvisioningService::generarPassword();
        } elseif ($dbUsername === null || $dbPassword === null) {
            throw new RuntimeException('db_username y db_password son obligatorios cuando no se usa auto_provisionar.');
        }

        $usuarioBi = null;
        $passwordBi = null;

        if ($conBi) {
            if (! $autoProvisionar) {
                throw new RuntimeException('con_bi solo aplica junto con auto_provisionar.');
            }

            $usuarioBi = TenantProvisioningService::generarUsuario($slug, 'bi');
            $passwordBi = TenantProvisioningService::generarPassword();
        }

        if ($autoProvisionar) {
            $adminUsuario = env('TENANT_PROVISION_DB_ADMIN_USERNAME');
            $adminPassword = env('TENANT_PROVISION_DB_ADMIN_PASSWORD');

            if (! $adminUsuario || ! $adminPassword) {
                throw new RuntimeException('auto_provisionar requiere TENANT_PROVISION_DB_ADMIN_USERNAME y TENANT_PROVISION_DB_ADMIN_PASSWORD en .env (credenciales de administrador del servidor, nunca las del tenant).');
            }

            TenantProvisioningService::crearBaseYUsuarios(
                driver: $driver,
                host: $datos['db_host'],
                puerto: (int) ($datos['puerto'] ?? 1433),
                adminUsuario: $adminUsuario,
                adminPassword: $adminPassword,
                baseDatos: $datos['db_database'],
                usuarioApp: $dbUsername,
                passwordApp: $dbPassword,
                usuarioBi: $usuarioBi,
                passwordBi: $passwordBi,
            );
        }

        $tenant = Tenant::create([
            'nombre_cliente'  => $datos['nombre'],
            'slug'            => $slug,
            'db_host'         => $datos['db_host'],
            'db_port'         => $datos['puerto'] ?? 1433,
            'db_driver'       => $driver,
            'db_database'     => $datos['db_database'],
            'db_username'     => $dbUsername,
            'db_password'     => $dbPassword,
            'db_bi_username'  => $usuarioBi,
            'db_bi_password'  => $passwordBi,
            'estatus'         => Tenant::ESTATUS_EN_APROVISIONAMIENTO,
        ]);

        try {
            $this->tenantAppClient->provisionarTenant([
                'db_driver'   => $driver,
                'db_host'     => $datos['db_host'],
                'db_port'     => $datos['puerto'] ?? 1433,
                'db_database' => $datos['db_database'],
                'db_username' => $dbUsername,
                'db_password' => $dbPassword,
            ]);
        } catch (\Throwable $e) {
            $tenant->update(['estatus' => Tenant::ESTATUS_SUSPENDIDO]);

            throw new RuntimeException("El backend del producto no pudo migrar/sembrar la base -- el tenant '{$slug}' quedó registrado pero SUSPENDIDO. Detalle: ".$e->getMessage());
        }

        $tenant->update(['estatus' => Tenant::ESTATUS_ACTIVO]);

        return [
            'tenant'       => $tenant->fresh(),
            'usuario_bi'   => $usuarioBi,
            'password_bi'  => $passwordBi,
        ];
    }
}
