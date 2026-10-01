<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\SuscripcionOnboardingService;
use Illuminate\Console\Command;
use RuntimeException;

class ReintentarAprovisionamientoCommand extends Command
{
    protected $signature = 'landlord:reintentar-aprovisionamiento
        {suscripcion_id : Id de la suscripción en estatus fallido}
        {admin_nombre : Nombre del administrador inicial}';

    protected $description = 'Reintenta el provision de una suscripción fallido con el mismo Idempotency-Key (la app no duplica tenant ni base).';

    public function handle(SuscripcionOnboardingService $onboarding): int
    {
        $suscripcion = Suscripcion::find($this->argument('suscripcion_id'));
        if (! $suscripcion) {
            $this->error("No existe la suscripción id={$this->argument('suscripcion_id')}.");

            return self::FAILURE;
        }

        $passwordTemporal = SuscripcionOnboardingService::generarPasswordTemporal();

        try {
            $suscripcion = $onboarding->reintentar($suscripcion, (string) $this->argument('admin_nombre'), $passwordTemporal);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Listo. Suscripción id={$suscripcion->id}, estatus={$suscripcion->estatus}, ref_externa=".($suscripcion->ref_externa ?? 'null'));

        if ($suscripcion->estatus !== Suscripcion::ESTATUS_FALLIDO) {
            // Si el intento anterior ya había creado el tenant, la app conserva
            // la contraseña original e ignora esta (contrato §3.5).
            MostrarPasswordTemporal::mostrar($this, $suscripcion->admin_email, $passwordTemporal, $suscripcion->estatus, esReintento: true);
        }

        return self::SUCCESS;
    }
}
