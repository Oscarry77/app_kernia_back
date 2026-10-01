<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\SuscripcionEstatusService;
use Illuminate\Console\Command;

class ReintentarNotificacionesEstatusCommand extends Command
{
    protected $signature = 'landlord:reintentar-notificaciones-estatus';

    protected $description = 'Reintenta los avisos de estatus (suspensión/reactivación) que una app no confirmó, con espera creciente.';

    public function handle(SuscripcionEstatusService $estatus): int
    {
        $resultado = $estatus->reintentarPendientes();

        if ($resultado['confirmadas'] + $resultado['fallidas'] > 0) {
            $this->line("Confirmadas: {$resultado['confirmadas']} · siguen pendientes: {$resultado['fallidas']}");
        }

        foreach (Suscripcion::with(['cliente', 'producto'])->whereNotNull('estatus_por_notificar')->get() as $s) {
            $this->warn("Pendiente: {$s->cliente->slug}/{$s->producto->slug} -> {$s->estatus_por_notificar} (intentos={$s->estatus_notificacion_intentos})");
        }

        return self::SUCCESS;
    }
}
