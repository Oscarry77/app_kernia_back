<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\VigenciaService;
use Illuminate\Console\Command;

/**
 * Corte de vencimientos (fase 2): suspende las suscripciones cuya fecha de
 * próximo pago (+ días de gracia) ya llegó, salvo que estén en prórroga.
 * Programado a las 00:00 de México y cada hora (idempotente).
 */
class ProcesarVencimientosCommand extends Command
{
    protected $signature = 'landlord:procesar-vencimientos';

    protected $description = 'Suspende las suscripciones vencidas (00:00 hora de México); idempotente.';

    public function handle(VigenciaService $vigencias): int
    {
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
