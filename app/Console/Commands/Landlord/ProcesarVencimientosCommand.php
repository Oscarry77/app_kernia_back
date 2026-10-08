<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\Auditoria;
use App\Models\Landlord\SolicitudPlan;
use App\Models\Landlord\SolicitudSalida;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\CambioPlanService;
use App\Services\Landlord\SalidaService;
use App\Services\Landlord\VigenciaService;
use Illuminate\Console\Command;

/**
 * Corte de vencimientos (fase 2): suspende las suscripciones cuya fecha de
 * próximo pago (+ días de gracia) ya llegó, salvo que estén en prórroga.
 * Programado a las 00:00 de México y cada hora (idempotente).
 *
 * 05-oct-2026: antes aplica los cambios de plan programados (bajas en la
 * renovación) cuya fecha ya llegó.
 *
 * 08-oct-2026: lo primero, las salidas programadas (retiro y finiquito): una
 * app que sale no debe recibir además un cambio de plan ni una suspensión.
 */
class ProcesarVencimientosCommand extends Command
{
    protected $signature = 'landlord:procesar-vencimientos';

    protected $description = 'Suspende las suscripciones vencidas (00:00 hora de México); idempotente.';

    public function handle(VigenciaService $vigencias, CambioPlanService $cambios, SalidaService $salidas): int
    {
        foreach ($salidas->aplicarProgramadas() as $sol) {
            $destino = "{$sol->suscripcion->cliente?->slug}/{$sol->suscripcion->producto->slug}";
            $sol->estado === SolicitudSalida::APLICADA
                ? $this->info("Salida aplicada ({$sol->tipo}): {$destino} → {$sol->suscripcion->fresh()->estatus}")
                : $this->warn("Salida {$sol->estado} ({$sol->tipo}): {$destino}".($sol->error ? " ({$sol->error})" : ''));
        }

        foreach ($cambios->aplicarProgramadas() as $sol) {
            $destino = "{$sol->suscripcion->cliente?->slug}/{$sol->suscripcion->producto->slug}: {$sol->plan_actual} → {$sol->plan_nuevo}";
            $sol->estado === SolicitudPlan::APLICADA
                ? $this->info("Cambio de plan aplicado: {$destino}")
                : $this->warn("Cambio de plan {$sol->estado}: {$destino}".($sol->error ? " ({$sol->error})" : ''));
        }

        $suspendidas = $vigencias->procesarVencimientos();

        foreach ($suspendidas as $r) {
            Auditoria::create([
                'usuario_id' => null,
                'accion' => 'suscripcion.suspendida_por_vencimiento',
                'suscripcion_id' => $r['suscripcion'],
                'cliente_id' => Suscripcion::find($r['suscripcion'])?->cliente_id,
                'despues' => ['estatus' => 'suspendido', 'causa' => 'vencimiento', 'app_confirmo' => $r['app_confirmo']],
            ]);
            $this->warn("Suspendida por vencimiento: {$r['cliente']}/{$r['producto']}".($r['app_confirmo'] ? '' : ' (aviso a la app pendiente; se reintenta)'));
        }

        if ($suspendidas === []) {
            $this->line('Sin vencimientos que procesar.');
        }

        return self::SUCCESS;
    }
}
