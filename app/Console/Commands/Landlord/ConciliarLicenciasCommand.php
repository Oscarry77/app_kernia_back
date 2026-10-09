<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\ProductoAppClient;
use App\Services\Landlord\SuscripcionPlanService;
use Illuminate\Console\Command;

/**
 * (09-oct-2026) Conciliación diaria de licencias (estándar v2.2 §4): compara
 * las empresas que reporta cada app (activas + inactivas) contra lo
 * contratado en Kernia. Si algo rebasa, queda en la bitácora como
 * `licencia.excedida` para Dirección. Solo lee; nunca bloquea.
 */
class ConciliarLicenciasCommand extends Command
{
    protected $signature = 'landlord:conciliar-licencias';

    protected $description = 'Compara las empresas que reporta cada app v2.2 contra lo contratado; registra las que rebasan.';

    public function handle(ProductoAppClient $app, SuscripcionPlanService $planes): int
    {
        $revisadas = 0;
        $excedidas = 0;

        $suscripciones = Suscripcion::with(['cliente', 'producto'])
            ->where('estatus', Suscripcion::ESTATUS_ACTIVO)
            ->whereHas('producto', fn ($q) => $q->where('empresas_v22', true))
            ->get();

        foreach ($suscripciones as $s) {
            $max = $planes->limitesEfectivos($s)['max_empresas'] ?? null;
            $m = $app->metricas($s);
            if ($max === null || ! ($m['ok'] ?? false)) {
                continue;
            }
            $revisadas++;
            $cuentan = (int) ($m['empresas_activas'] ?? 0) + (int) ($m['empresas_inactivas'] ?? 0);
            if ($cuentan > $max) {
                $excedidas++;
                Auditoria::registrar('licencia.excedida', null, $s, null, ['cuentan' => $cuentan, 'max_empresas' => $max, 'metricas' => $m]);
                $this->warn("Rebasa: {$s->cliente->slug}/{$s->producto->slug} usa {$cuentan} de {$max} empresas.");
            }
        }

        $this->info("Conciliación: {$revisadas} revisadas, {$excedidas} excedidas.");

        return self::SUCCESS;
    }
}
