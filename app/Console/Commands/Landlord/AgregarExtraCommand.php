<?php
namespace App\Console\Commands\Landlord;

use App\Console\Commands\Landlord\Concerns\BuscaSuscripcion;
use App\Services\Landlord\SuscripcionPlanService;
use Illuminate\Console\Command;
use RuntimeException;

class AgregarExtraCommand extends Command
{
    use BuscaSuscripcion;

    protected $signature = 'landlord:agregar-extra
        {cliente_slug} {producto_slug}
        {extra : Código del extra (p. ej. empresa_adicional)}
        {cantidad : Positivo agrega, negativo retira}
        {--motivo= : Motivo (p. ej. referencia de pago)}
        {--por= : Quién lo registra}';

    protected $description = 'Agrega o retira extras contratados (p. ej. empresas adicionales). Queda en bitácora; la app recibe el nuevo límite en su siguiente resolve.';

    public function handle(SuscripcionPlanService $planes): int
    {
        $suscripcion = $this->buscarSuscripcion($this->argument('cliente_slug'), $this->argument('producto_slug'));
        if (! $suscripcion) {
            return self::FAILURE;
        }

        try {
            $total = $planes->agregarExtra(
                $suscripcion,
                (string) $this->argument('extra'),
                (int) $this->argument('cantidad'),
                $this->option('motivo'),
                $this->option('por'),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Listo. '{$this->argument('extra')}' contratados en total: {$total}.");
        $this->line('Límites efectivos: '.json_encode($planes->limitesEfectivos($suscripcion)));

        return self::SUCCESS;
    }
}
