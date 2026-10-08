<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\ProductoAppClient;
use App\Services\Landlord\SuscripcionOnboardingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Estándar v2 §6.2 (01-oct-2026): recuperar el acceso del administrador
 * inicial de un workspace -- para quien nunca recibió su temporal, para
 * soporte, y para los clientes creados antes de que Kernia la mostrara.
 * La nueva temporal se muestra UNA vez y nunca se guarda.
 */
class RestablecerAdminCommand extends Command
{
    protected $signature = 'landlord:restablecer-admin
        {cliente_slug : Slug del cliente en Kernia}
        {producto_slug : Slug del producto}
        {--email= : Correo del administrador (default: admin_email de la suscripción)}';

    protected $description = 'Pide a la app una nueva contraseña temporal para el administrador del workspace y la muestra una sola vez.';

    public function handle(ProductoAppClient $appClient): int
    {
        $cliente = Cliente::where('slug', strtolower((string) $this->argument('cliente_slug')))->first();
        $producto = Producto::where('slug', strtolower((string) $this->argument('producto_slug')))->first();
        $suscripcion = ($cliente && $producto)
            ? Suscripcion::where('cliente_id', $cliente->id)->where('producto_id', $producto->id)->first()
            : null;

        if (! $suscripcion) {
            $this->error('No existe esa suscripción.');

            return self::FAILURE;
        }

        if (! in_array($suscripcion->estatus, Suscripcion::ESTATUS_RESTABLECER_ADMIN, true)) {
            $this->error("La suscripción está '{$suscripcion->estatus}'; solo se restablece el acceso de una suscripción activa o en finiquito.");

            return self::FAILURE;
        }

        $email = $this->option('email') ?: $suscripcion->admin_email;
        if (! $email) {
            $this->error('La suscripción no tiene admin_email; indícalo con --email.');

            return self::FAILURE;
        }

        $passwordTemporal = SuscripcionOnboardingService::generarPasswordTemporal();

        try {
            $appClient->restablecerAdmin($suscripcion, $email, $passwordTemporal);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Rastro mínimo (sin la contraseña) hasta que exista la tabla `auditoria`.
        Log::info('landlord.restablecer_admin', [
            'cliente' => $cliente->slug,
            'producto' => $producto->slug,
            'email' => $email,
        ]);

        $this->info("Listo. La app confirmó el restablecimiento de {$email} en {$cliente->slug}/{$producto->slug}.");
        MostrarPasswordTemporal::mostrar($this, $email, $passwordTemporal, $suscripcion->estatus);

        return self::SUCCESS;
    }
}
