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
 *  - 05-oct-2026: un plan CON clientes no puede perder módulos ni reducir
 *    límites desde el catálogo (afectaría a todos de golpe, sin autorización).
 *    Para eso se crea otro plan y se cambia a cada cliente con una solicitud
 *    autorizada por el escalafón. Agregar módulos o ampliar límites sí se puede.
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
        if ($plan && ($clientes = $this->suscripcionesConPlan($producto, $plan->codigo)) > 0) {
            $this->impedirReduccion($producto, $plan, $modulos, $limites, $clientes);
        }

        return DB::transaction(function () use ($producto, $plan, $datos, $modulos, $limites) {
            $antes = $plan?->modulos ?? [];

            $plan ??= new ProductoPlan(['producto_id' => $producto->id, 'codigo' => $datos['codigo']]);
            $plan->fill([
                'nombre' => $datos['nombre'],
                'descripcion' => array_key_exists('descripcion', $datos) ? $datos['descripcion'] : $plan->descripcion,
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

    /** @param array<string,int|null> $limites */
    private function impedirReduccion(Producto $producto, ProductoPlan $plan, array $modulos, array $limites, int $clientes): void
    {
        $catalogo = $producto->modulos()->pluck('nombre', 'clave');
        $quitados = array_diff($plan->modulos ?? [], $modulos);
        $reducidos = [];
        foreach ($plan->limites ?? [] as $clave => $antes) {
            $despues = array_key_exists($clave, $limites) ? $limites[$clave] : null;
            // Quitar la clave equivale a "sin límite" (ampliar); null → número o un número menor es reducir.
            if ($despues !== null && ($antes === null || $despues < $antes)) {
                $reducidos[] = $clave;
            }
        }

        if (! $quitados && ! $reducidos) {
            return;
        }

        $detalle = array_filter([
            $quitados ? 'quita '.implode(', ', array_map(fn ($c) => $catalogo[$c] ?? $c, $quitados)) : null,
            $reducidos ? 'reduce '.implode(', ', $reducidos) : null,
        ]);
        $n = $clientes === 1 ? '1 cliente' : "{$clientes} clientes";

        throw new RuntimeException("El plan {$plan->nombre} tiene {$n}: no se puede guardar porque ".implode(' y ', $detalle)
            .'. Crea un plan nuevo y cambia a cada cliente con una solicitud de cambio de plan.');
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
