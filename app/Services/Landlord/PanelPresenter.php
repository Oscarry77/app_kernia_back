<?php
namespace App\Services\Landlord;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use App\Models\Landlord\SolicitudPlan;
use App\Models\Landlord\SolicitudSalida;
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

    /** @param bool $completo incluye planes/extras inactivos y cuántos clientes usan cada plan (pantalla de catálogo) */
    public function producto(Producto $p, bool $completo = false): array
    {
        $catalogo = app(CatalogoService::class);

        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'nombre' => $p->nombre,
            'nombre_corto' => $p->nombre_corto,
            'descripcion' => $p->descripcion,
            'permite_ws_cntpaq' => (bool) $p->permite_ws_cntpaq,
            'estatus_salida' => (bool) $p->estatus_salida,
            'modo_datos' => $p->modo_datos,
            'modulos' => $p->modulos()->orderBy('id')->get(['clave', 'nombre', 'requiere'])->toArray(),
            'planes' => $p->planes()->when(! $completo, fn ($q) => $q->where('activo', true))->orderBy('orden')->get()
                ->map(fn ($pl) => [
                    'codigo' => $pl->codigo, 'nombre' => $pl->nombre, 'descripcion' => $pl->descripcion, 'modulos' => $pl->modulos, 'limites' => $pl->limites,
                    'orden' => $pl->orden, 'activo' => (bool) $pl->activo,
                    ...($completo ? ['clientes' => $catalogo->suscripcionesConPlan($p, $pl->codigo)] : []),
                ])
                ->all(),
            'extras' => $p->extras()->when(! $completo, fn ($q) => $q->where('activo', true))->get()
                ->map(fn ($e) => ['codigo' => $e->codigo, 'nombre' => $e->nombre, 'limite' => $e->limite, 'incremento' => $e->incremento, 'activo' => (bool) $e->activo])
                ->all(),
        ];
    }

    public function cliente(Cliente $c, bool $detalle = false): array
    {
        $suscripciones = $c->suscripciones()->with('producto')->orderBy('id')->get();

        $datos = [
            'id' => $c->id,
            'slug' => $c->slug,
            'nombre' => $c->nombre,
            'tipo_persona' => $c->tipo_persona,
            'rfc' => $c->rfc,
            'nombre_comercial' => $c->nombre_comercial,
            'estatus' => $c->estatus,
            'tipo' => $c->tipo ?? Cliente::TIPO_COMERCIAL,
            'datos_fiscales_completos' => $c->datosFiscalesCompletos(),
            'creado' => $c->created_at?->toDateString(),
            'suscripciones' => $suscripciones->map(fn ($s) => $detalle ? $this->suscripcion($s) : $this->resumen($s))->all(),
        ];

        if ($detalle) {
            $datos['notas'] = $c->notas;
            $datos['fiscal'] = [
                ...$c->only(Cliente::CAMPOS_FISCALES),
                'fecha_inicio_operaciones' => $c->fecha_inicio_operaciones?->toDateString(),
                'regimen_fiscal_nombre' => collect(config('catalogos_fiscales.regimenes_fiscales'))->firstWhere('clave', $c->regimen_fiscal)['nombre'] ?? null,
            ];
        }

        return $datos;
    }

    /** Vigencia de una suscripción (fase 2). */
    public function vigencia(Suscripcion $s): array
    {
        $vigencias = app(VigenciaService::class);

        return [
            'modalidad_pago' => $s->modalidad_pago,
            'fecha_contratacion' => $s->fecha_contratacion?->toDateString(),
            'fecha_proximo_pago' => $s->fecha_proximo_pago?->toDateString(),
            'dias_restantes' => $vigencias->diasRestantes($s),
            'dias_gracia' => (int) $s->dias_gracia,
            'suspension_automatica' => (bool) $s->suspension_automatica,
            'suspension_motivo' => $s->suspension_motivo,
            'activa_hasta' => $s->activa_hasta?->toDateString(),
            'aviso' => $vigencias->aviso($s),
        ];
    }

    public function resumen(Suscripcion $s): array
    {
        $plan = $s->producto->planVigente($s->plan);

        return [
            'id' => $s->id,
            'producto' => $s->producto->slug,
            'producto_nombre' => $s->producto->nombre,
            'estatus' => $s->estatusEfectivo(),
            'plan' => $s->plan,
            'plan_nombre' => $plan?->nombre,
            'aviso_pendiente' => $s->estatus_por_notificar,
            'fecha_proximo_pago' => $s->fecha_proximo_pago?->toDateString(),
            'dias_restantes' => app(VigenciaService::class)->diasRestantes($s),
        ];
    }

    public function suscripcion(Suscripcion $s): array
    {
        return [
            ...$this->resumen($s),
            'estatus_almacenado' => $s->estatus,
            'modo_datos' => $s->producto->modo_datos,
            'db_driver' => $s->db_driver,
            'permite_ws_cntpaq' => (bool) $s->producto->permite_ws_cntpaq,
            'ws_cntpaq_habilitado' => (bool) $s->ws_cntpaq_habilitado,
            'ref_externa' => $s->ref_externa,
            'admin_email' => $s->admin_email,
            'modulos' => $s->clavesModulosActivos(),
            'limites' => $this->planes->limitesEfectivos($s),
            'extras' => $this->planes->extrasContratados($s),
            ...$this->vigencia($s),
            'provisionada_en' => $s->provisionada_en?->toDateTimeString(),
            'aviso_intentos' => $s->estatus_notificacion_intentos,
            'aviso_error' => $s->estatus_notificacion_error,
            'cambio_plan' => ($abierta = SolicitudPlan::with('solicitante:id,nombre')->where('suscripcion_id', $s->id)
                ->whereIn('estado', SolicitudPlan::ABIERTAS)->latest('id')->first()) ? $this->solicitudPlan($abierta) : null,
            // 08-oct-2026: estados de salida.
            'descarga_hasta' => $s->descarga_hasta?->toDateString(),
            'salida' => ($salida = SolicitudSalida::with('solicitante:id,nombre')->where('suscripcion_id', $s->id)
                ->whereIn('estado', SolicitudSalida::ABIERTAS)->latest('id')->first()) ? $this->solicitudSalida($salida) : null,
            'salidas_posibles' => app(SalidaService::class)->opciones($s),
        ];
    }

    /** Solicitud de salida: retiro, reactivación o finiquito (08-oct-2026). */
    public function solicitudSalida(SolicitudSalida $sol): array
    {
        return [
            'id' => $sol->id,
            'suscripcion_id' => $sol->suscripcion_id,
            'tipo' => $sol->tipo,
            'estatus_anterior' => $sol->estatus_anterior,
            'motivo' => $sol->motivo,
            'conformidad_tipo' => $sol->conformidad_tipo,
            'conformidad_referencia' => $sol->conformidad_referencia,
            'estado' => $sol->estado,
            'fecha_efectiva' => $sol->fecha_efectiva?->toDateString(),
            'solicitada_por' => $sol->solicitante?->nombre,
            'resuelta_por' => $sol->resolutor?->nombre,
            'nivel_autorizacion' => $sol->nivel_autorizacion,
            'comentario_resolucion' => $sol->comentario_resolucion,
            'error' => $sol->error,
            'solicitada_en' => $sol->created_at?->toIso8601String(),
            'resuelta_en' => $sol->resuelta_en?->toIso8601String(),
            'aplicada_en' => $sol->aplicada_en?->toIso8601String(),
        ];
    }

    /** Solicitud de cambio de plan (05-oct-2026). */
    public function solicitudPlan(SolicitudPlan $sol): array
    {
        $producto = $sol->suscripcion->producto;

        return [
            'id' => $sol->id,
            'suscripcion_id' => $sol->suscripcion_id,
            'plan_actual' => $sol->plan_actual,
            'plan_actual_nombre' => $producto->planVigente($sol->plan_actual)?->nombre,
            'plan_nuevo' => $sol->plan_nuevo,
            'plan_nuevo_nombre' => $producto->planVigente($sol->plan_nuevo)?->nombre,
            'direccion' => $sol->direccion,
            'aplicacion' => $sol->aplicacion,
            'fecha_efectiva' => $sol->fecha_efectiva?->toDateString(),
            'motivo' => $sol->motivo,
            'estado' => $sol->estado,
            'solicitada_por' => $sol->solicitante?->nombre,
            'resuelta_por' => $sol->resolutor?->nombre,
            'nivel_autorizacion' => $sol->nivel_autorizacion,
            'comentario_resolucion' => $sol->comentario_resolucion,
            'error' => $sol->error,
            'solicitada_en' => $sol->created_at?->toIso8601String(),
            'resuelta_en' => $sol->resuelta_en?->toIso8601String(),
            'aplicada_en' => $sol->aplicada_en?->toIso8601String(),
        ];
    }
}
