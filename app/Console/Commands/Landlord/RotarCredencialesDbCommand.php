<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\Suscripcion;
use App\Services\TenantProvisioningService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Genera un usuario "app" acotado nuevo (o nueva contraseña para el mismo)
 * sobre la base dedicada de una suscripción y lo guarda cifrado en Kernia.
 * La contraseña nunca se imprime: la app la recibe en su siguiente `resolve`.
 * Creado el 28-sep-2026 para sacar a `root` de la suscripción `demo-svi`.
 */
class RotarCredencialesDbCommand extends Command
{
    protected $signature = 'landlord:rotar-credenciales-db {suscripcion_id : Id de la suscripción en modo dedicada}';

    protected $description = 'Crea/rota el usuario MySQL acotado de la base dedicada de una suscripción y guarda la credencial cifrada (no la imprime).';

    public function handle(): int
    {
        $suscripcion = Suscripcion::find($this->argument('suscripcion_id'));
        if (! $suscripcion || ! $suscripcion->db_database) {
            $this->error('La suscripción no existe o no tiene base dedicada.');

            return self::FAILURE;
        }

        if ($suscripcion->db_driver !== 'mysql') {
            $this->error("Solo MySQL está soportado (driver actual: {$suscripcion->db_driver}).");

            return self::FAILURE;
        }

        $adminUsuario = env('TENANT_PROVISION_DB_ADMIN_USERNAME');
        $adminPassword = env('TENANT_PROVISION_DB_ADMIN_PASSWORD');
        if (! $adminUsuario || ! $adminPassword) {
            $this->error('Faltan TENANT_PROVISION_DB_ADMIN_USERNAME/PASSWORD en .env.');

            return self::FAILURE;
        }

        $usuarioAnterior = $suscripcion->db_username;
        $usuarioApp = TenantProvisioningService::generarUsuario($suscripcion->db_database, 'app');
        $passwordApp = TenantProvisioningService::generarPassword();

        try {
            TenantProvisioningService::reemplazarUsuarioAppMysql(
                host: $suscripcion->db_host,
                puerto: (int) $suscripcion->db_port,
                adminUsuario: $adminUsuario,
                adminPassword: $adminPassword,
                baseDatos: $suscripcion->db_database,
                usuarioApp: $usuarioApp,
                passwordApp: $passwordApp,
            );
        } catch (Throwable $e) {
            $this->error("No se pudo crear el usuario en MySQL: {$e->getMessage()}");

            return self::FAILURE;
        }

        $suscripcion->update(['db_username' => $usuarioApp, 'db_password' => $passwordApp]);

        $this->info("Listo. {$suscripcion->db_database}: {$usuarioAnterior} -> {$usuarioApp}. La app recibirá la credencial nueva en su siguiente resolve (al expirar su caché).");
        if ($usuarioAnterior === $usuarioApp) {
            $this->warn('Fue una rotación del mismo usuario: la contraseña anterior dejó de funcionar de inmediato.');
        }

        return self::SUCCESS;
    }
}
