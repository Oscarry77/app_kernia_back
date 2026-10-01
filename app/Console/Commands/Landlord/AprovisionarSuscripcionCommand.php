<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\SuscripcionOnboardingService;
use Illuminate\Console\Command;
use RuntimeException;

class AprovisionarSuscripcionCommand extends Command
{
    protected $signature = 'landlord:aprovisionar-suscripcion
        {cliente_slug : Slug del cliente en Kernia}
        {producto_slug : comercializa|hrm|svi}
        {admin_nombre : Nombre del administrador inicial}
        {admin_email : Correo del administrador inicial}
        {--plan= : Código de plan comercial}';

    protected $description = 'Da de alta una suscripción (Cliente x Producto) contra el contrato v1: crea la base dedicada si aplica y llama a POST /internal/v1/provision de la app.';

    public function handle(SuscripcionOnboardingService $onboarding): int
    {
        $clienteSlug = strtolower((string) $this->argument('cliente_slug'));
        $productoSlug = strtolower((string) $this->argument('producto_slug'));

        $cliente = Cliente::where('slug', $clienteSlug)->first();
        if (! $cliente) {
            $this->error("No existe un cliente con slug '{$clienteSlug}'.");

            return self::FAILURE;
        }

        $producto = Producto::where('slug', $productoSlug)->first();
        if (! $producto) {
            $this->error("No existe un producto con slug '{$productoSlug}'.");

            return self::FAILURE;
        }

        $this->info("Aprovisionando '{$clienteSlug}' en '{$productoSlug}' (modo {$producto->modo_datos})...");

        $passwordTemporal = SuscripcionOnboardingService::generarPasswordTemporal();

        try {
            $suscripcion = $onboarding->aprovisionar(
                $cliente,
                $producto,
                [
                    'nombre' => $this->argument('admin_nombre'),
                    'email' => $this->argument('admin_email'),
                    'password_temporal' => $passwordTemporal,
                ],
                $this->option('plan'),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Listo. Suscripción id={$suscripcion->id}, estatus={$suscripcion->estatus}, ref_externa=".($suscripcion->ref_externa ?? 'null'));

        if ($suscripcion->estatus !== Suscripcion::ESTATUS_FALLIDO) {
            MostrarPasswordTemporal::mostrar($this, $suscripcion->admin_email, $passwordTemporal, $suscripcion->estatus);
        }

        return self::SUCCESS;
    }
}
