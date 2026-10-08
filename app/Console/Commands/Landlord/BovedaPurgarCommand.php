<?php
namespace App\Console\Commands\Landlord;

use App\Services\Boveda\BovedaService;
use Illuminate\Console\Command;

/** Purga los secretos de la bóveda cuya retención venció (07-oct-2026). Idempotente. */
class BovedaPurgarCommand extends Command
{
    protected $signature = 'landlord:boveda-purgar';

    protected $description = 'Borra el valor de los secretos de la bóveda vencidos (conserva el registro para auditoría).';

    public function handle(BovedaService $boveda): int
    {
        $n = $boveda->purgarVencidos();
        $this->line($n === 0 ? 'Sin secretos vencidos.' : "Secretos purgados: {$n}.");

        return self::SUCCESS;
    }
}
