<?php
namespace App\Console\Commands\Tenants;

use App\Services\Landlord\TenantOnboardingService;
use Illuminate\Console\Command;
use RuntimeException;

class CrearTenantCommand extends Command
{
    protected $signature = 'tenants:crear
        {slug : Subdominio del cliente, ej. acme}
        {nombre : Nombre/razón social del cliente}
        {db_host : Host del servidor de base de datos del cliente}
        {db_database : Nombre de la base de datos del cliente}
        {db_username? : Usuario de la base -- omitir si se usa --auto-provisionar}
        {db_password? : Password de la base -- omitir si se usa --auto-provisionar}
        {--puerto=1433 : Puerto del servidor de base de datos}
        {--driver=sqlsrv : Motor de la base del tenant: sqlsrv o mysql}
        {--auto-provisionar : Crea la base y un usuario de app acotado automáticamente, usando credenciales de administrador (env TENANT_PROVISION_DB_ADMIN_USERNAME/PASSWORD)}
        {--con-bi : Junto con --auto-provisionar, también crea un usuario de solo lectura para herramientas de BI}';

    protected $description = 'Da de alta un cliente (tenant) nuevo: registra su base de datos y le pide al backend del producto que se migre/siembre contra ella.';

    // (13-sep-2026) Movido de bridge-com-api al extraer Kernia. La orquestación completa
    // (registro + aprovisionamiento + llamada al backend del producto) vive en
    // TenantOnboardingService -- este comando es un wrapper delgado sobre ese servicio,
    // que también usa LandlordTenantController::store(), para no duplicar la secuencia.
    public function handle(TenantOnboardingService $onboarding): int
    {
        $slug = strtolower((string) $this->argument('slug'));

        $this->info("Registrando tenant '{$slug}'...");

        try {
            $resultado = $onboarding->aprovisionar([
                'slug'             => $slug,
                'nombre'           => $this->argument('nombre'),
                'db_host'          => $this->argument('db_host'),
                'db_database'      => $this->argument('db_database'),
                'db_username'      => $this->argument('db_username'),
                'db_password'      => $this->argument('db_password'),
                'puerto'           => (int) $this->option('puerto'),
                'driver'           => $this->option('driver'),
                'auto_provisionar' => (bool) $this->option('auto-provisionar'),
                'con_bi'           => (bool) $this->option('con-bi'),
            ]);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Listo. El cliente '{$slug}' ya puede operar en https://{$slug}.<tu-dominio>");

        if ($resultado['usuario_bi'] !== null) {
            $this->warn('Usuario de BI creado -- esta es la ÚNICA vez que se muestra el password en claro, guárdalo ahora:');
            $this->line("  usuario: {$resultado['usuario_bi']}");
            $this->line("  password: {$resultado['password_bi']}");
        }

        return self::SUCCESS;
    }
}
