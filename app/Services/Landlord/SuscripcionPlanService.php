<?php
namespace App\Services\Landlord;

use App\Models\Landlord\ProductoPlan;
use App\Models\Landlord\Suscripcion;
use App\Models\Landlord\SuscripcionExtra;
use App\Models\Landlord\SuscripcionModulo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Plan (edición) + extras de una suscripción (estándar v2.1, 01-oct-2026).
 *
 *  - Los MÓDULOS salen del plan, nunca se eligen a mano: así es imposible,
 *    p. ej., Viáticos sin Tesorería en Comercializa.
 *  - Límite efectivo = límite base del plan + Σ(extras × incremento).
 *    Un límite base null es "sin límite" y los extras no lo cambian.
 *  - Las apps lo reciben en `resolve` (`limites`) en ≤ 60 s; no hay push.
 *  - Nada se borra: un módulo que el plan ya no incluye queda `activo=false`.
 */
class SuscripcionPlanService
{
    public function aplicarPlan(Suscripcion $suscripcion, string $codigo): ProductoPlan
    {
        $producto = $suscripcion->producto;
        $plan = $producto->plan($codigo);

        if (! $plan) {
            $validos = $producto->planes()->where('activo', true)->orderBy('orden')->pluck('codigo')->implode(', ');
            throw new RuntimeException("Plan '{$codigo}' no existe para '{$producto->slug}'. Válidos: {$validos}.");
        }

        $incluidos = $plan->modulos ?? [];
        // Orden del catálogo (id), el mismo con el que se crearon las filas de
        // los clientes existentes y con el que `resolve` las devuelve.
        $existentes = $producto->modulos()->orderBy('id')->pluck('clave')->all();
        if ($faltantes = array_diff($incluidos, $existentes)) {
            throw new RuntimeException("El plan '{$codigo}' incluye módulos inexistentes: ".implode(', ', $faltantes).'.');
        }

        DB::transaction(function () use ($suscripcion, $plan, $existentes, $incluidos) {
            $suscripcion->update(['plan' => $plan->codigo]);

            foreach ($existentes as $clave) {
                SuscripcionModulo::updateOrCreate(
                    ['suscripcion_id' => $suscripcion->id, 'modulo_clave' => $clave],
                    ['activo' => in_array($clave, $incluidos, true)]
                );
            }
        });

        return $plan;
    }

    /** @param int $cantidad movimiento: positivo agrega, negativo retira */
    public function agregarExtra(Suscripcion $suscripcion, string $codigo, int $cantidad, ?string $motivo = null, ?string $registradoPor = null): int
    {
        if ($cantidad === 0) {
            throw new RuntimeException('La cantidad no puede ser 0.');
        }

        $extra = $suscripcion->producto->extras()->where('codigo', $codigo)->where('activo', true)->first();
        if (! $extra) {
            throw new RuntimeException("Extra '{$codigo}' no existe para '{$suscripcion->producto->slug}'.");
        }

        return DB::transaction(function () use ($suscripcion, $extra, $cantidad, $motivo, $registradoPor) {
            $total = $this->totalExtra($suscripcion, $extra->id) + $cantidad;
            if ($total < 0) {
                throw new RuntimeException("No se pueden retirar más extras de los contratados (quedaría en {$total}).");
            }

            SuscripcionExtra::create([
                'suscripcion_id' => $suscripcion->id,
                'producto_extra_id' => $extra->id,
                'cantidad' => $cantidad,
                'motivo' => $motivo,
                'registrado_por' => $registradoPor,
            ]);

            return $total;
        });
    }

    /**
     * Límites efectivos que viajan en `resolve`. Null si el producto no usa
     * catálogo de planes o la suscripción aún no tiene plan (transición).
     *
     * @return array<string, int|null>|null
     */
    public function limitesEfectivos(Suscripcion $suscripcion): ?array
    {
        $plan = $suscripcion->producto->planVigente($suscripcion->plan);
        if (! $plan) {
            return null;
        }

        $limites = $plan->limites ?? [];

        // Todos los extras, activos o no: desactivar un extra impide venderlo
        // de nuevo, pero lo ya contratado sigue sumando (02-oct-2026).
        foreach ($suscripcion->producto->extras()->get() as $extra) {
            $total = $this->totalExtra($suscripcion, $extra->id);
            if ($total === 0 || ! array_key_exists($extra->limite, $limites) || $limites[$extra->limite] === null) {
                continue;
            }

            $limites[$extra->limite] += $total * $extra->incremento;
        }

        return $limites;
    }

    /** @return array<string,int> codigo del extra => total contratado */
    public function extrasContratados(Suscripcion $suscripcion): array
    {
        $totales = [];
        foreach ($suscripcion->producto->extras as $extra) {
            $totales[$extra->codigo] = $this->totalExtra($suscripcion, $extra->id);
        }

        return $totales;
    }

    private function totalExtra(Suscripcion $suscripcion, int $extraId): int
    {
        return (int) SuscripcionExtra::where('suscripcion_id', $suscripcion->id)
            ->where('producto_extra_id', $extraId)
            ->sum('cantidad');
    }
}
