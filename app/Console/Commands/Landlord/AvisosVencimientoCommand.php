<?php
namespace App\Console\Commands\Landlord;

use App\Services\Correo\AvisosVencimientoService;
use Illuminate\Console\Command;

/**
 * (09-oct-2026) Avisos de vencimiento por correo (fase 4). Programado a las
 * 08:00 de México, en horario de oficina; idempotente: cada hito se entrega
 * una sola vez por ciclo.
 */
class AvisosVencimientoCommand extends Command
{
    protected $signature = 'landlord:avisos-vencimiento';

    protected $description = 'Envía por correo los avisos de vencimiento pendientes (30, 15, 7, 3, 1 y 0 días, y la suspensión).';

    public function handle(AvisosVencimientoService $avisos): int
    {
        $enviados = $avisos->enviarPendientes();
        foreach ($enviados as $r) {
            $r['entregado']
                ? $this->info("Aviso {$r['hito']} entregado (suscripción {$r['suscripcion']}).")
                : $this->warn("Aviso {$r['hito']} no entregado (suscripción {$r['suscripcion']}); se reintenta en la siguiente corrida.");
        }
        if (! $enviados) {
            $this->line('Sin avisos de vencimiento pendientes.');
        }

        return self::SUCCESS;
    }
}
