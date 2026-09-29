<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\SuscripcionOnboardingService;
use Illuminate\Console\Command;
use Throwable;

class SincronizarAprovisionamientosCommand extends Command
{
    protected $signature = 'landlord:sincronizar-aprovisionamientos';

    protected $description = 'Polling de aprovisionamientos asíncronos: consulta GET /internal/v1/provision/{cliente_id}/status de cada suscripción en_aprovisionamiento y la pasa a activo/fallido.';

    public function handle(SuscripcionOnboardingService $onboarding): int
    {
        $pendientes = Suscripcion::with(['cliente', 'producto'])
            ->where('estatus', Suscripcion::ESTATUS_EN_APROVISIONAMIENTO)
            ->get();

        foreach ($pendientes as $suscripcion) {
            $etiqueta = "{$suscripcion->cliente->slug}/{$suscripcion->producto->slug} (id={$suscripcion->id})";

            // Un error con una app no detiene el resto; se reintenta en la
            // siguiente corrida.
            try {
                $actualizada = $onboarding->sincronizar($suscripcion);
                $this->line("{$etiqueta}: {$actualizada->estatus}");
            } catch (Throwable $e) {
                $this->warn("{$etiqueta}: sin respuesta válida -- {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
