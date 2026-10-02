<?php
namespace App\Services\Landlord;

use App\Models\Landlord\Producto;
use App\Models\Landlord\ProductoExtra;
use App\Models\Landlord\ProductoPlan;
use App\Models\Landlord\Suscripcion;
use App\Models\Landlord\SuscripcionModulo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Catálogo de planes y extras editable desde el panel (02-oct-2026).
 *
 *  - El código de un plan o extra es inmutable: identifica lo contratado.
 *  - Los módulos de un plan deben existir y respetar sus dependencias
 *    (p. ej. Viáticos requiere Tesorería).
 *  - Cambiar los módulos de un plan los actualiza en TODOS los clientes que
 *    lo tienen (los módulos salen del plan). Los límites viajan solos en el
 *    siguiente `resolve`.
 *  - Desactivar un plan o extra impide asignarlo; lo ya contratado sigue
 *    vigente (Producto::planVigente y los extras se suman igual).
 */
class CatalogoService
{
    /** Claves de límite reconocidas por las apps (estándar v2 §4.2). */
    public const LIMITES = ['max_empresas', 'max_empleados', 'max_usuarios'];

    /** @return int clientes (suscripciones no canceladas) que usan el plan */
    public function suscripcionesConPlan(Producto $producto, string $codigo): int
    {
        return Suscripcion::where('producto_id', $producto->id)->where('plan', $codigo)
            ->where('estatus', '!=', Suscripcion::ESTATUS_CANCELADO)->count();
    }

    public function guardarPlan(Producto $producto, ?ProductoPlan $plan, array $datos): ProductoPlan
    {
        $modulos = array_values(array_unique($datos['modulos'] ?? []));
        $this->validarModulos($producto, $modulos);
        $limites = $this->normalizarLimites($datos['limites'] ?? []);

        return DB::transaction(function () use ($producto, $plan, $datos, $modulos, $limites) {
            $antes = $plan?->modulos ?? [];

            $plan ??= new ProductoPlan(['producto_id' => $producto->id, 'codigo' => $datos['codigo']]);
            $plan->fill([
                'nombre' => $datos['nombre'],
                'modulos' => $producto->modulos()->exists() ? $modulos : null,
                'limites' => $limites,
                'orden' => $datos['orden'] ?? $plan->orden ?? 0,
                'activo' => $datos['activo'] ?? $plan->activo ?? true,
            ])->save();

            sort($antes);
            $nuevos = $modulos;
            sort($nuevos);
            if ($plan->wasRecentlyCreated === false && $antes !== $nuevos) {
                $this->sincronizarModulos($producto, $plan);
            }

            return $plan;
        });
    }

    public function guardarExtra(Producto $producto, ?ProductoExtra $extra, array $datos): ProductoExtra
    {
        if (! in_array($datos['limite'], self::LIMITES, true)) {
            throw new RuntimeException("Límite '{$datos['limite']}' no reconocido.");
        }

        $extra ??= new ProductoExtra(['producto_id' => $producto->id, 'codigo' => $datos['codigo']]);
        $extra->fill([
            'nombre' => $datos['nombre'],
            'limite' => $datos['limite'],
            'incremento' => (int) $datos['incremento'],
            'activo' => $datos['activo'] ?? $extra->activo ?? true,
        ])->save();

        return $extra;
    }

    /** Aplica los módulos del plan a cada suscripción que lo tiene. */
    private function sincronizarModulos(Producto $producto, ProductoPlan $plan): void
    {
        $claves = $producto->modulos()->orderBy('id')->pluck('clave')->all();
        $incluidos = $plan->modulos ?? [];

        Suscripcion::where('producto_id', $producto->id)->where('plan', $plan->codigo)->each(
            function (Suscripcion $s) use ($claves, $incluidos) {
                foreach ($claves as $clave) {
                    SuscripcionModulo::updateOrCreate(
                        ['suscripcion_id' => $s->id, 'modulo_clave' => $clave],
                        ['activo' => in_array($clave, $incluidos, true)]
                    );
                }
            }
        );
    }

    private function validarModulos(Producto $producto, array $modulos): void
    {
        $catalogo = $producto->modulos()->get()->keyBy('clave');

        if ($catalogo->isEmpty()) {
            if ($modulos) {
                throw new RuntimeException("'{$producto->nombre}' no maneja módulos.");
            }

            return;
        }

        if (! $modulos) {
            throw new RuntimeException('Un plan debe incluir al menos un módulo.');
        }

        foreach ($modulos as $clave) {
            if (! $catalogo->has($clave)) {
                throw new RuntimeException("Módulo '{$clave}' no existe en '{$producto->nombre}'.");
            }
            foreach ($catalogo[$clave]->requiere ?? [] as $requerido) {
                if (! in_array($requerido, $modulos, true)) {
                    throw new RuntimeException("{$catalogo[$clave]->nombre} requiere {$catalogo[$requerido]->nombre} en el mismo plan.");
                }
            }
        }
    }

    /** @return array<string,int|null> solo claves reconocidas; null = sin límite */
    private function normalizarLimites(array $limites): array
    {
        $resultado = [];
        foreach ($limites as $clave => $valor) {
            if (! in_array($clave, self::LIMITES, true)) {
                throw new RuntimeException("Límite '{$clave}' no reconocido.");
            }
            if ($valor !== null && (! is_numeric($valor) || (int) $valor < 0)) {
                throw new RuntimeException("El límite '{$clave}' debe ser un número mayor o igual a 0, o vacío para 'sin límite'.");
            }
            $resultado[$clave] = $valor === null ? null : (int) $valor;
        }

        return $resultado;
    }
}
