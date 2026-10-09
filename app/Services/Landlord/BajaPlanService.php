<?php
namespace App\Services\Landlord;

use App\Models\Landlord\Auditoria;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\ProductoPlan;
use App\Models\Landlord\SolicitudPlan;
use App\Models\Landlord\Suscripcion;
use App\Services\Correo\AvisosCliente;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Orquestación de la baja de plan con el estándar v2.2 (09-oct-2026).
 * Reglas en REGISTRO_DECISIONES_KERNIA.md §3 (05-oct) y ESTANDAR_v2.2 §3–§4:
 *
 *  - El asesor captura qué empresas conserva el cliente (`empresas_conservar`)
 *    al solicitar la baja o después, hasta que empieza la ejecución. Sin lista
 *    al llegar la fecha → `sin_seleccion`: la app bloquea todas.
 *  - Al llegar la fecha (corte de las 00:00), si las empresas que cuentan caben
 *    en el plan nuevo, solo se cambia el plan (sin cortar el servicio). Si no:
 *      aviso en la app → 5 min → `en_mantenimiento` → `ajuste-plan` → polling →
 *      plan aplicado → `activo` → correo al administrador (copia asesor y Dirección).
 *    Si la app falla, la suscripción regresa a `activo` con su plan anterior.
 *  - Desbloquear: Kernia audita la licencia ANTES de llamar a la app
 *    (activas + inactivas + las que se piden ≤ max_empresas); la app valida de nuevo.
 *  - Solo con apps que ya cumplen v2.2 (`productos.empresas_v22`). Quién
 *    respalda: en el patrón B, la app; en el patrón A, Kernia (RespaldoBaseService),
 *    ya en mantenimiento y antes de pedir el ajuste (09-oct-2026).
 */
class BajaPlanService
{
    /** Minutos de aviso en la app antes de pasar a mantenimiento. */
    public const MINUTOS_AVISO = 5;

    /** Minutos sin terminar tras los que se deja una alerta en la bitácora (se sigue esperando). */
    public const MINUTOS_ALERTA = 30;

    /** Estados de empresa que el cliente puede conservar (cuentan para la licencia). */
    private const CONSERVABLES = ['activa', 'inactiva'];

    public function __construct(
        private readonly ProductoAppClient $app,
        private readonly SuscripcionEstatusService $estatus,
        private readonly SuscripcionPlanService $planes,
    ) {
    }

    public function usaV22(Suscripcion $s): bool
    {
        return (bool) $s->producto->empresas_v22;
    }

    /** Empresas del cliente según la app (v2.2 §3.1). */
    public function empresas(Suscripcion $s): array
    {
        if (! $this->usaV22($s)) {
            throw new RuntimeException("{$s->producto->nombre} todavía no entrega la lista de empresas (estándar v2.2).");
        }

        return $this->app->empresas($s);
    }

    /**
     * Valida la lista de empresas a conservar contra la app y el plan nuevo.
     * null = el cliente aún no decide. Devuelve los ids ordenados.
     */
    public function validarLista(Suscripcion $s, string $planNuevo, ?array $ids): ?array
    {
        if ($ids === null) {
            return null;
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        $estados = collect($this->empresas($s)['data'] ?? [])->pluck('estado', 'id');
        foreach ($ids as $id) {
            if (! $estados->has($id)) {
                throw new RuntimeException("La empresa {$id} no pertenece al cliente.");
            }
            if (! in_array($estados[$id], self::CONSERVABLES, true)) {
                throw new RuntimeException("La empresa {$id} está {$estados[$id]}; solo se conservan empresas activas o inactivas.");
            }
        }

        $max = $this->maxEmpresasCon($s, $planNuevo);
        if ($max !== null && count($ids) > $max) {
            throw new RuntimeException('Elegiste '.count($ids)." empresas; el plan nuevo permite {$max}.");
        }

        return $ids;
    }

    /** Captura o corrige la lista mientras la baja no ha empezado a ejecutarse. */
    public function capturarEmpresas(SolicitudPlan $sol, ?array $ids): SolicitudPlan
    {
        if ($sol->direccion !== SolicitudPlan::BAJADA || ! in_array($sol->estado, [SolicitudPlan::SOLICITADA, SolicitudPlan::PROGRAMADA], true)) {
            throw new RuntimeException('Solo se capturan empresas en una baja pendiente o programada.');
        }

        $sol->update(['empresas_conservar' => $this->validarLista($sol->suscripcion, $sol->plan_nuevo, $ids)]);

        return $sol->fresh();
    }

    /**
     * ¿Hace falta bloquear empresas para aplicar esta baja? Consulta la app:
     * solo si las empresas que cuentan rebasan el límite del plan nuevo.
     */
    public function requiereAjuste(SolicitudPlan $sol): bool
    {
        $s = $sol->suscripcion;
        if ($sol->direccion !== SolicitudPlan::BAJADA || ! $this->usaV22($s)) {
            return false;
        }
        $max = $this->maxEmpresasCon($s, $sol->plan_nuevo);

        return $max !== null && (int) ($this->empresas($s)['cuentan_para_limite'] ?? 0) > $max;
    }

    /** Arranca la ejecución: la app muestra el aviso durante MINUTOS_AVISO. */
    public function iniciar(SolicitudPlan $sol): SolicitudPlan
    {
        $s = $sol->suscripcion;
        if ($s->estatus !== Suscripcion::ESTATUS_ACTIVO) {
            return $this->fallar($sol, "La suscripción está '{$s->estatus}'; la baja requiere que esté activa.");
        }

        $sol->update(['estado' => SolicitudPlan::EN_EJECUCION, 'fase' => SolicitudPlan::FASE_AVISO, 'fase_desde' => now(), 'error' => null]);
        $this->registrar('baja_plan.iniciada', $sol, ['empresas_conservar' => $sol->empresas_conservar]);

        return $sol->fresh();
    }

    /**
     * Avanza cada baja en ejecución un paso. Lo llama `landlord:orquestar-bajas`
     * cada minuto; idempotente. Un error de red no cambia el estado: se anota
     * y se reintenta en la siguiente vuelta.
     *
     * @return list<SolicitudPlan>
     */
    public function avanzar(): array
    {
        return SolicitudPlan::with('suscripcion.producto', 'suscripcion.cliente')
            ->where('estado', SolicitudPlan::EN_EJECUCION)->orderBy('id')->get()
            ->map(function (SolicitudPlan $sol) {
                try {
                    return match ($sol->fase) {
                        SolicitudPlan::FASE_AVISO => $this->pasarAMantenimiento($sol),
                        SolicitudPlan::FASE_MANTENIMIENTO => $this->pedirAjuste($sol),
                        SolicitudPlan::FASE_AJUSTANDO => $this->revisarAjuste($sol),
                        default => $sol,
                    };
                } catch (Throwable $e) {
                    $sol->update(['error' => mb_substr($e->getMessage(), 0, 300)]);
                    Log::warning('baja_plan.reintento', ['solicitud_plan_id' => $sol->id, 'error' => $sol->error]);

                    return $sol->fresh();
                }
            })->all();
    }

    /**
     * Desbloquea empresas con la auditoría de licencia de Kernia: nunca se
     * piden más de las que permite el plan vigente (plan + extras).
     */
    public function desbloquear(Suscripcion $s, array $ids, string $motivo, LandlordAdmin $operador): array
    {
        if ($s->estatus !== Suscripcion::ESTATUS_ACTIVO) {
            throw new RuntimeException('Solo se desbloquean empresas de una suscripción activa.');
        }
        if (! trim($motivo)) {
            throw new RuntimeException('Indica el motivo del desbloqueo.');
        }
        if (SolicitudPlan::where('suscripcion_id', $s->id)->where('estado', SolicitudPlan::EN_EJECUCION)->exists()) {
            throw new RuntimeException('Hay un ajuste de plan en curso; espera a que termine.');
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $ids) {
            throw new RuntimeException('Elige al menos una empresa.');
        }

        $lista = $this->empresas($s);
        $estados = collect($lista['data'] ?? [])->pluck('estado', 'id');
        foreach ($ids as $id) {
            if (($estados[$id] ?? null) !== 'bloqueada_plan') {
                throw new RuntimeException("La empresa {$id} no está bloqueada por el plan.");
            }
        }

        $max = $this->planes->limitesEfectivos($s)['max_empresas'] ?? null;
        $cuentan = (int) ($lista['cuentan_para_limite'] ?? 0);
        $auditoria = ['empresas' => $ids, 'cuentan' => $cuentan, 'max_empresas' => $max, 'motivo' => trim($motivo)];
        if ($max !== null && $cuentan + count($ids) > $max) {
            Auditoria::registrar('licencia.desbloqueo_rechazado', null, $s, null, $auditoria);
            throw new RuntimeException("Rebasa la licencia: el cliente usa {$cuentan} de {$max} empresas y se piden ".count($ids).
                ' más. Sube de plan, contrata empresas adicionales o archiva alguna.');
        }

        $this->app->desbloquear($s, $ids);
        Auditoria::registrar('empresas.desbloqueadas', null, $s, null, [...$auditoria, 'operador' => $operador->email]);

        return $this->empresas($s);
    }

    /** Banner del `resolve` mientras la baja se ejecuta (v2.2 §3.2). */
    public function aviso(Suscripcion $s): ?array
    {
        $sol = SolicitudPlan::where('suscripcion_id', $s->id)->where('estado', SolicitudPlan::EN_EJECUCION)->first();
        if (! $sol) {
            return null;
        }

        return [
            'nivel' => 'critico',
            'tipo' => 'mantenimiento',
            'mensaje' => $sol->fase === SolicitudPlan::FASE_AVISO
                ? 'En unos minutos ajustaremos tu servicio por el cambio de plan. Guarda tu trabajo; el sistema cerrará las sesiones.'
                : 'Estamos ajustando tu servicio. Vuelve a intentar en unos minutos.',
        ];
    }

    // ── Pasos ──

    private function pasarAMantenimiento(SolicitudPlan $sol): SolicitudPlan
    {
        if ($sol->fase_desde->copy()->addMinutes(self::MINUTOS_AVISO)->isFuture()) {
            return $sol;
        }

        $this->estatus->cambiarEstatus($sol->suscripcion, Suscripcion::ESTATUS_EN_MANTENIMIENTO, 'Ajuste por cambio de plan');
        $sol->update(['fase' => SolicitudPlan::FASE_MANTENIMIENTO, 'fase_desde' => now()]);
        $this->registrar('baja_plan.mantenimiento', $sol);

        return $sol->fresh();
    }

    private function pedirAjuste(SolicitudPlan $sol): SolicitudPlan
    {
        $s = $sol->suscripcion->fresh();
        // La app debe saber que está en mantenimiento; si aún no confirma, se espera.
        if ($s->estatus_por_notificar !== null) {
            return $this->demora($sol);
        }

        // 09-oct-2026: patrón A (Kernia creó la base): Kernia respalda ANTES de pedir el ajuste y manda
        // `respaldo_id` (v2.2 §3.3, Revisión 3). Ya en mantenimiento, nadie escribe mientras se respalda.
        if ($s->producto->esDedicada() && ! $sol->respaldo_id) {
            try {
                $respaldo = app(RespaldoBaseService::class)->crear($s, 'ajuste_plan', $sol->id);
            } catch (RuntimeException $e) {
                return $this->revertir($sol, $e->getMessage());
            }
            $sol->update(['respaldo_id' => $respaldo->id]);
        }

        $lista = $sol->empresas_conservar;
        $r = $this->app->ajustePlan($s, [
            'solicitud_id' => $sol->id,
            'max_empresas' => $this->maxEmpresasCon($s, $sol->plan_nuevo),
            'empresas_conservar' => $lista ?? [],
            'motivo' => $lista === null ? 'sin_seleccion' : 'seleccion',
            ...($s->producto->esDedicada() ? ['respaldo_id' => $sol->respaldo_id] : []),
        ], "solicitud-plan-{$sol->id}");

        if (in_array($r['status'], [200, 202], true)) {
            $sol->update(['fase' => SolicitudPlan::FASE_AJUSTANDO, 'fase_desde' => now(), 'alerta_demora' => false, 'error' => null]);

            return $sol->fresh();
        }
        if ($r['status'] === 409) {
            return $this->demora($sol); // la app aún no ve el mantenimiento
        }

        return $this->revertir($sol, "La app rechazó el ajuste ({$r['status']}): ".mb_substr(json_encode($r['body'], JSON_UNESCAPED_UNICODE), 0, 200));
    }

    private function revisarAjuste(SolicitudPlan $sol): SolicitudPlan
    {
        $estado = $this->app->estadoAjustePlan($sol->suscripcion, $sol->id);

        return match ($estado['status'] ?? null) {
            'ready' => $this->finalizar($sol, $estado),
            'failed' => $this->revertir($sol, 'La app no pudo aplicar el ajuste: '.($estado['error'] ?? 'sin detalle')),
            default => $this->demora($sol),
        };
    }

    private function finalizar(SolicitudPlan $sol, array $estado): SolicitudPlan
    {
        $s = $sol->suscripcion;
        $antes = ['plan' => $s->plan, 'limites' => $this->planes->limitesEfectivos($s)];
        $errorPlan = null;
        try {
            $this->planes->aplicarPlan($s, $sol->plan_nuevo);
        } catch (RuntimeException $e) {
            // Las empresas ya se bloquearon; el plan anterior es más amplio, así que nada se rompe. Se deja registrado.
            $errorPlan = 'Empresas ajustadas, pero el plan no se pudo cambiar en Kernia: '.$e->getMessage();
        }

        $sol->update([
            'estado' => $errorPlan ? SolicitudPlan::FALLIDA : SolicitudPlan::APLICADA, 'error' => $errorPlan,
            'fase' => null, 'fase_desde' => null, 'aplicada_en' => now(),
            'respaldo_id' => $sol->respaldo_id ?? ($estado['respaldo_id'] ?? null), 'empresas_bloqueadas' => $estado['empresas_bloqueadas'] ?? [],
        ]);
        $this->estatus->cambiarEstatus($s->fresh(), Suscripcion::ESTATUS_ACTIVO, 'Ajuste de plan terminado');

        $s = $s->fresh();
        Auditoria::registrar('suscripcion.plan_cambiado', null, $s, $antes, [
            'solicitud_plan_id' => $sol->id, 'direccion' => $sol->direccion, 'plan' => $s->plan, 'limites' => $this->planes->limitesEfectivos($s),
            'respaldo_id' => $sol->respaldo_id, 'empresas_bloqueadas' => $sol->empresas_bloqueadas, 'error' => $errorPlan,
        ]);

        try {
            app(AvisosCliente::class)->planAjustado($sol->fresh());
        } catch (Throwable $e) {
            Log::warning('correo.plan_ajustado_no_generado', ['solicitud_plan_id' => $sol->id, 'error' => $e->getMessage()]);
        }

        return $sol->fresh();
    }

    /** La app no aplicó nada: el cliente regresa a operar con su plan anterior. */
    private function revertir(SolicitudPlan $sol, string $error): SolicitudPlan
    {
        $s = $sol->suscripcion->fresh();
        if ($s->estatus === Suscripcion::ESTATUS_EN_MANTENIMIENTO) {
            $this->estatus->cambiarEstatus($s, Suscripcion::ESTATUS_ACTIVO, 'Ajuste de plan no aplicado');
        }

        return $this->fallar($sol, $error);
    }

    private function fallar(SolicitudPlan $sol, string $error): SolicitudPlan
    {
        $sol->update(['estado' => SolicitudPlan::FALLIDA, 'fase' => null, 'fase_desde' => null, 'error' => mb_substr($error, 0, 300)]);
        $this->registrar('baja_plan.fallida', $sol, ['error' => $sol->error]);

        return $sol->fresh();
    }

    /** Sigue esperando; si pasa MINUTOS_ALERTA en la misma fase, deja una sola alerta en la bitácora. */
    private function demora(SolicitudPlan $sol): SolicitudPlan
    {
        if (! $sol->alerta_demora && $sol->fase_desde->copy()->addMinutes(self::MINUTOS_ALERTA)->isPast()) {
            $sol->update(['alerta_demora' => true]);
            $this->registrar('baja_plan.demora', $sol, ['fase' => $sol->fase, 'desde' => $sol->fase_desde->toIso8601String()]);
            Log::warning('baja_plan.demora', ['solicitud_plan_id' => $sol->id, 'fase' => $sol->fase]);
        }

        return $sol->fresh();
    }

    private function maxEmpresasCon(Suscripcion $s, string $codigo): ?int
    {
        $plan = $s->producto->planVigente($codigo);

        return $plan instanceof ProductoPlan ? ($this->planes->limitesConPlan($s, $plan)['max_empresas'] ?? null) : null;
    }

    private function registrar(string $accion, SolicitudPlan $sol, array $extra = []): void
    {
        Auditoria::registrar($accion, null, $sol->suscripcion, null, ['solicitud_plan_id' => $sol->id, 'fase' => $sol->fase, ...$extra]);
    }
}
