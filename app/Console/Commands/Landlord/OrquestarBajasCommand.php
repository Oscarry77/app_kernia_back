<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\SolicitudPlan;
use App\Services\Landlord\BajaPlanService;
use Illuminate\Console\Command;

/**
 * (09-oct-2026) Avanza un paso cada baja de plan v2.2 en ejecución:
 * aviso (5 min) → en_mantenimiento → ajuste-plan → polling → activo.
 * Programado cada minuto; idempotente.
 */
class OrquestarBajasCommand extends Command
{
    protected $signature = 'landlord:orquestar-bajas';

    protected $description = 'Avanza las bajas de plan v2.2 en ejecución (aviso, mantenimiento, ajuste y regreso a activo).';

    public function handle(BajaPlanService $bajas): int
    {
        foreach ($bajas->avanzar() as $sol) {
            $destino = "{$sol->suscripcion->cliente?->slug}/{$sol->suscripcion->producto->slug} (solicitud {$sol->id})";
            match (true) {
                $sol->estado === SolicitudPlan::APLICADA => $this->info("Baja aplicada: {$destino}"),
                $sol->estado === SolicitudPlan::FALLIDA => $this->warn("Baja fallida: {$destino}: {$sol->error}"),
                default => $this->line("En curso ({$sol->fase}): {$destino}".($sol->error ? " — {$sol->error}" : '')),
            };
        }

        return self::SUCCESS;
    }
}
