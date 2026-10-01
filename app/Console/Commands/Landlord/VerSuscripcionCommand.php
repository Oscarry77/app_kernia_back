<?php
namespace App\Console\Commands\Landlord;

use App\Console\Commands\Landlord\Concerns\BuscaSuscripcion;
use App\Services\Landlord\SuscripcionPlanService;
use Illuminate\Console\Command;

class VerSuscripcionCommand extends Command
{
    use BuscaSuscripcion;

    protected $signature = 'landlord:ver-suscripcion {cliente_slug} {producto_slug}';

    protected $description = 'Muestra estatus, plan, módulos, extras y límites efectivos de una suscripción (sin credenciales).';

    public function handle(SuscripcionPlanService $planes): int
    {
        $s = $this->buscarSuscripcion($this->argument('cliente_slug'), $this->argument('producto_slug'));
        if (! $s) {
            return self::FAILURE;
        }

        $plan = $s->producto->plan($s->plan);

        $this->table(['Campo', 'Valor'], [
            ['Cliente', "{$s->cliente->slug} (id {$s->cliente->id})"],
            ['Producto', $s->producto->slug],
            ['Estatus efectivo', $s->estatusEfectivo()],
            ['Plan', $plan ? "{$plan->codigo} ({$plan->nombre})" : ($s->plan ?? 'sin plan')],
            ['Módulos activos', implode(', ', $s->clavesModulosActivos() ?? []) ?: '—'],
            ['Extras contratados', json_encode($planes->extrasContratados($s))],
            ['Límites efectivos', json_encode($planes->limitesEfectivos($s))],
            ['Aviso pendiente a la app', $s->estatus_por_notificar ?? '—'],
        ]);

        return self::SUCCESS;
    }
}
