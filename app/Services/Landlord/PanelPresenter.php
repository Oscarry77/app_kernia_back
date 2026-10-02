<?php
namespace App\Services\Landlord;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;

/**
 * Forma de los datos que ve el panel kernia-admin (02-oct-2026). NUNCA incluye
 * credenciales de base de datos ni el token interno de un producto.
 */
class PanelPresenter
{
    public function __construct(private readonly SuscripcionPlanService $planes)
    {
    }

    public function producto(Producto $p): array
    {
        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'nombre' => $p->nombre,
            'modo_datos' => $p->modo_datos,
            'modulos' => $p->modulos()->orderBy('id')->get(['clave', 'nombre'])->toArray(),
            'planes' => $p->planes()->where('activo', true)->orderBy('orden')->get()
                ->map(fn ($pl) => ['codigo' => $pl->codigo, 'nombre' => $pl->nombre, 'modulos' => $pl->modulos, 'limites' => $pl->limites])
                ->all(),
            'extras' => $p->extras()->where('activo', true)->get()
                ->map(fn ($e) => ['codigo' => $e->codigo, 'nombre' => $e->nombre, 'limite' => $e->limite, 'incremento' => $e->incremento])
                ->all(),
        ];
    }

    public function cliente(Cliente $c, bool $detalle = false): array
    {
        $suscripciones = $c->suscripciones()->with('producto')->orderBy('id')->get();

        return [
            'id' => $c->id,
            'slug' => $c->slug,
            'nombre' => $c->nombre,
            'rfc' => $c->rfc,
            'estatus' => $c->estatus,
            'notas' => $c->notas,
            'creado' => $c->created_at?->toDateString(),
            'suscripciones' => $suscripciones->map(fn ($s) => $detalle ? $this->suscripcion($s) : $this->resumen($s))->all(),
        ];
    }

    public function resumen(Suscripcion $s): array
    {
        $plan = $s->producto->plan($s->plan);

        return [
            'id' => $s->id,
            'producto' => $s->producto->slug,
            'producto_nombre' => $s->producto->nombre,
            'estatus' => $s->estatusEfectivo(),
            'plan' => $s->plan,
            'plan_nombre' => $plan?->nombre,
            'aviso_pendiente' => $s->estatus_por_notificar,
        ];
    }

    public function suscripcion(Suscripcion $s): array
    {
        return [
            ...$this->resumen($s),
            'estatus_almacenado' => $s->estatus,
            'modo_datos' => $s->producto->modo_datos,
            'ref_externa' => $s->ref_externa,
            'admin_email' => $s->admin_email,
            'modulos' => $s->clavesModulosActivos(),
            'limites' => $this->planes->limitesEfectivos($s),
            'extras' => $this->planes->extrasContratados($s),
            'fecha_contratacion' => $s->fecha_contratacion?->toDateString(),
            'fecha_proximo_pago' => $s->fecha_proximo_pago?->toDateString(),
            'provisionada_en' => $s->provisionada_en?->toDateTimeString(),
            'aviso_intentos' => $s->estatus_notificacion_intentos,
            'aviso_error' => $s->estatus_notificacion_error,
        ];
    }
}
