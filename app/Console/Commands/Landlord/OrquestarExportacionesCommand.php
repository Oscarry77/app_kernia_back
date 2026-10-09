<?php
namespace App\Console\Commands\Landlord;

use App\Services\Landlord\ExportacionService;
use Illuminate\Console\Command;

/**
 * (09-oct-2026) Avanza las exportaciones v2.3: pedir a la app, polling,
 * reintentos con contraseña nueva y correos (carta y, aparte, la contraseña).
 * Con `--diarias`: recordatorios de descarga y paso a `finiquitado`.
 */
class OrquestarExportacionesCommand extends Command
{
    protected $signature = 'landlord:orquestar-exportaciones {--diarias : recordatorios de descarga y vencimiento del plazo}';

    protected $description = 'Avanza las exportaciones v2.3 del finiquito; con --diarias, recordatorios y paso a finiquitado.';

    public function handle(ExportacionService $exportaciones): int
    {
        if ($this->option('diarias')) {
            $r = $exportaciones->tareasDiarias();
            $this->info("Recordatorios enviados: {$r['recordatorios']}; pasadas a finiquitado: {$r['finiquitadas']}.");

            return self::SUCCESS;
        }

        foreach ($exportaciones->avanzar() as $exp) {
            $this->line("Exportación {$exp->id} ({$exp->suscripcion->cliente?->slug}/{$exp->suscripcion->producto->slug}): {$exp->estado}"
                .($exp->error ? " — {$exp->error}" : ''));
        }

        return self::SUCCESS;
    }
}
