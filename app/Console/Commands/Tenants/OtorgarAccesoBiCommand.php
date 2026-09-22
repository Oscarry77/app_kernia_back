<?php
namespace App\Console\Commands\Tenants;

use App\Models\Landlord\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Console\Command;

class OtorgarAccesoBiCommand extends Command
{
    protected $signature = 'tenants:otorgar-acceso-bi
        {slug : Slug del tenant ya existente}
        {--rotar : Si ya tenía acceso BI, genera un usuario/password nuevo y revoca el anterior}';

    protected $description = 'Crea (o rota) un usuario de SOLO LECTURA para que el tenant conecte una herramienta de BI directo a su base -- nunca reutiliza el usuario de la app.';

    public function handle(): int
    {
        $slug = strtolower((string) $this->argument('slug'));
        $tenant = Tenant::where('slug', $slug)->first();

        if (! $tenant) {
            $this->error("No existe un tenant con el slug '{$slug}'.");

            return self::FAILURE;
        }

        if ($tenant->tieneAccesoBiConfigurado() && ! $this->option('rotar')) {
            $this->error("El tenant '{$slug}' ya tiene acceso BI configurado (usuario: {$tenant->db_bi_username}). Usa --rotar si quieres reemplazarlo.");

            return self::FAILURE;
        }

        $adminUsuario = env('TENANT_PROVISION_DB_ADMIN_USERNAME');
        $adminPassword = env('TENANT_PROVISION_DB_ADMIN_PASSWORD');

        if (! $adminUsuario || ! $adminPassword) {
            $this->error('Requiere TENANT_PROVISION_DB_ADMIN_USERNAME y TENANT_PROVISION_DB_ADMIN_PASSWORD en .env (credenciales de administrador del servidor, nunca las del tenant).');

            return self::FAILURE;
        }

        $usuarioBi = TenantProvisioningService::generarUsuario($slug, 'bi');
        $passwordBi = TenantProvisioningService::generarPassword();

        $this->info("Creando usuario de solo lectura para '{$slug}' en {$tenant->db_driver}://{$tenant->db_host}...");

        try {
            TenantProvisioningService::otorgarAccesoBi(
                driver: $tenant->db_driver,
                host: $tenant->db_host,
                puerto: (int) $tenant->db_port,
                adminUsuario: $adminUsuario,
                adminPassword: $adminPassword,
                baseDatos: $tenant->db_database,
                usuarioBi: $usuarioBi,
                passwordBi: $passwordBi,
            );
        } catch (\Throwable $e) {
            $this->error('Falló el otorgamiento de acceso BI: '.$e->getMessage());

            return self::FAILURE;
        }

        $tenant->update([
            'db_bi_username' => $usuarioBi,
            'db_bi_password' => $passwordBi,
        ]);

        $this->info('Listo. Acceso de solo lectura creado.');
        $this->warn('Esta es la ÚNICA vez que se muestra el password en claro, guárdalo ahora:');
        $this->line("  host: {$tenant->db_host}:{$tenant->db_port}");
        $this->line("  base: {$tenant->db_database}");
        $this->line("  usuario: {$usuarioBi}");
        $this->line("  password: {$passwordBi}");

        return self::SUCCESS;
    }
}
