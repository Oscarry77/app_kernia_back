<?php
namespace App\Console\Commands\Landlord;

use App\Console\Commands\Landlord\Concerns\BuscaSuscripcion;
use App\Services\Landlord\SuscripcionPlanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CambiarPlanCommand extends Command
{
    use BuscaSuscripcion;

    protected $signature = 'landlord:cambiar-plan
        {cliente_slug} {producto_slug} {plan : Código del plan (p. ej. basico|profesional|corporativo)}';

    protected $description = 'Cambia el plan (edición) de una suscripción: ajusta sus módulos y límites. La app lo recibe en su siguiente resolve (≤ 60 s). No borra datos.';

    public function handle(SuscripcionPlanService $planes): int
    {
        $suscripcion = $this->buscarSuscripcion($this->argument('cliente_slug'), $this->argument('producto_slug'));
        if (! $suscripcion) {
            return self::FAILURE;
        }

        $anterior = $suscripcion->plan;

        try {
            $plan = $planes->aplicarPlan($suscripcion, (string) $this->argument('plan'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        Log::info('landlord.cambiar_plan', ['suscripcion' => $suscripcion->id, 'de' => $anterior, 'a' => $plan->codigo]);

        $this->info("Plan: ".($anterior ?? 'sin plan')." -> {$plan->codigo} ({$plan->nombre}).");
        $this->line('Módulos activos: '.implode(', ', $suscripcion->fresh()->clavesModulosActivos() ?? []));
        $this->line('Límites efectivos: '.json_encode($planes->limitesEfectivos($suscripcion->fresh())));

        return self::SUCCESS;
    }
}
