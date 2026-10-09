<?php
namespace App\Services\Landlord;

use App\Models\Landlord\DiaInhabil;
use App\Models\Landlord\Pago;
use App\Models\Landlord\Suscripcion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Vigencias (fase 2, 02-oct-2026; decisiones del 21-sep confirmadas el
 * 02-oct, ver REGISTRO_DECISIONES_KERNIA.md §4).
 *
 *  - "Hoy" es la fecha en America/Mexico_City.
 *  - Días restantes = fecha de próximo pago − hoy (se calculan, no se guardan).
 *  - Suspensión automática a las 00:00 del día de la fecha de próximo pago
 *    (más días de gracia), solo si la suscripción está activa, tiene la
 *    suspensión automática encendida y NO está en prórroga (activa_hasta).
 *    Sin fecha de próximo pago no se suspende nunca.
 *  - Un pago cubre un periodo de la modalidad a partir de la fecha de próximo
 *    pago, la recorre y, si la suspensión fue por vencimiento, reactiva.
 */
class VigenciaService
{
    public function __construct(private readonly SuscripcionEstatusService $estatus)
    {
    }

    public static function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now(config('kernia.zona_horaria'))->startOfDay();
    }

    public function diasRestantes(Suscripcion $s): ?int
    {
        if (! $s->fecha_proximo_pago) {
            return null;
        }

        return (int) self::hoy()->diffInDays(CarbonImmutable::parse($s->fecha_proximo_pago->toDateString(), config('kernia.zona_horaria')), false);
    }

    /** Días hábiles (sin sábados, domingos ni días inhábiles) desde mañana hasta la fecha de próximo pago, inclusive. */
    public function diasHabilesRestantes(Suscripcion $s): ?int
    {
        $restantes = $this->diasRestantes($s);
        if ($restantes === null || $restantes <= 0) {
            return $restantes;
        }

        $hoy = self::hoy();
        $limite = $hoy->addDays($restantes);
        $inhabiles = DiaInhabil::whereBetween('fecha', [$hoy->toDateString(), $limite->toDateString()])
            ->pluck('fecha')->map(fn ($f) => $f->toDateString())->all();

        $habiles = 0;
        for ($d = $hoy->addDay(); $d->lte($limite); $d = $d->addDay()) {
            if (! $d->isWeekend() && ! in_array($d->toDateString(), $inhabiles, true)) {
                $habiles++;
            }
        }

        return $habiles;
    }

    public function enProrroga(Suscripcion $s): bool
    {
        return $s->activa_hasta !== null && $s->activa_hasta->toDateString() >= self::hoy()->toDateString();
    }

    /**
     * `aviso` del resolve (banner no bloqueante en las apps, guía v1 §5.1):
     * info desde 30 días, advertencia desde 5 días hábiles, crítico en prórroga.
     */
    public function aviso(Suscripcion $s): ?array
    {
        // 05-oct-2026: el aviso de vencimiento manda; si no hay, el de una baja de plan programada.
        // 08-oct-2026: antes que ambos, el de una salida (retiro o finiquito programado, o el plazo de descarga).
        // 09-oct-2026: después, el de una baja de plan en ejecución (mantenimiento).
        return app(SalidaService::class)->aviso($s) ?? app(BajaPlanService::class)->aviso($s) ?? $this->avisoVencimiento($s) ?? ($s->estatus === Suscripcion::ESTATUS_ACTIVO
            ? app(CambioPlanService::class)->avisoProgramado($s)
            : null);
    }

    private function avisoVencimiento(Suscripcion $s): ?array
    {
        if ($s->estatus !== Suscripcion::ESTATUS_ACTIVO || ! $s->fecha_proximo_pago) {
            return null;
        }
        // 05-oct-2026: demo, capacitación y prueba no reciben avisos de cobro.
        if ($s->cliente && ! $s->cliente->esComercial()) {
            return null;
        }

        $dias = $this->diasRestantes($s);
        $fecha = $s->fecha_proximo_pago->toDateString();

        if ($this->enProrroga($s)) {
            return ['nivel' => 'critico', 'dias_restantes' => $dias, 'fecha_proximo_pago' => $fecha,
                'mensaje' => 'Tu acceso está activo de forma provisional hasta el '.$s->activa_hasta->format('d/m/Y').'. Regulariza tu pago para evitar la suspensión.'];
        }

        if ($dias > config('kernia.aviso_info_dias')) {
            return null;
        }

        $habiles = $this->diasHabilesRestantes($s);
        $nivel = $habiles <= config('kernia.aviso_advertencia_dias_habiles') ? 'advertencia' : 'info';
        $mensaje = match (true) {
            $dias <= 0 => 'Tu suscripción venció hoy. Regulariza tu pago para conservar el acceso.',
            $dias === 1 => 'Tu suscripción vence mañana. Regulariza tu pago para evitar la suspensión.',
            default => "Tu suscripción vence en {$dias} días ({$s->fecha_proximo_pago->format('d/m/Y')}).",
        };

        return ['nivel' => $nivel, 'dias_restantes' => $dias, 'fecha_proximo_pago' => $fecha, 'mensaje' => $mensaje];
    }

    /** Define o corrige la vigencia (alta inicial o ajuste manual). */
    public function actualizar(Suscripcion $s, array $datos): Suscripcion
    {
        $this->validarModalidad($datos['modalidad_pago']);

        $s->update([
            'modalidad_pago' => $datos['modalidad_pago'],
            'fecha_contratacion' => $datos['fecha_contratacion'] ?? $s->fecha_contratacion,
            'fecha_proximo_pago' => $datos['fecha_proximo_pago'],
            'dias_gracia' => $datos['dias_gracia'] ?? $s->dias_gracia,
            'suspension_automatica' => $datos['suspension_automatica'] ?? $s->suspension_automatica,
        ]);

        return $s->fresh();
    }

    /**
     * Registra un pago: cubre un periodo de la modalidad desde la fecha de
     * próximo pago, la recorre y, si estaba suspendida por vencimiento,
     * reactiva (con aviso a la app). Cierra una prórroga vigente.
     *
     * @return array{pago: Pago, reactivada: bool, app_confirmo: bool|null}
     */
    public function registrarPago(Suscripcion $s, array $datos, ?int $operadorId): array
    {
        if (! $s->modalidad_pago || ! $s->fecha_proximo_pago) {
            throw new RuntimeException('Define primero la modalidad y la fecha de próximo pago de esta suscripción.');
        }
        if (! in_array($s->estatus, [Suscripcion::ESTATUS_ACTIVO, Suscripcion::ESTATUS_SUSPENDIDO], true)) {
            throw new RuntimeException("No se registran pagos de una suscripción '{$s->estatus}'.");
        }

        $meses = config('kernia.modalidades')[$s->modalidad_pago];
        $desde = CarbonImmutable::parse($s->fecha_proximo_pago->toDateString());
        $hasta = $desde->addMonthsNoOverflow($meses);

        $pago = DB::transaction(function () use ($s, $datos, $operadorId, $desde, $hasta) {
            $pago = Pago::create([
                'suscripcion_id' => $s->id,
                'fecha_pago' => $datos['fecha_pago'] ?? self::hoy()->toDateString(),
                'periodo_desde' => $desde->toDateString(),
                'periodo_hasta' => $hasta->toDateString(),
                'modalidad' => $s->modalidad_pago,
                'monto' => $datos['monto'] ?? null,
                'moneda' => $datos['moneda'] ?? 'MXN',
                'referencia' => $datos['referencia'],
                'notas' => $datos['notas'] ?? null,
                'registrado_por' => $operadorId,
            ]);

            $s->update(['fecha_proximo_pago' => $hasta->toDateString(), 'activa_hasta' => null]);
            // Fase 3: el pago cierra la prórroga vigente y cancela solicitudes pendientes.
            app(ProrrogaService::class)->cerrarPorPago($s);

            return $pago;
        });

        $reactivar = $s->estatus === Suscripcion::ESTATUS_SUSPENDIDO
            && $s->suspension_motivo === SuscripcionEstatusService::CAUSA_VENCIMIENTO
            && $this->diasRestantes($s->fresh()) > 0;

        $confirmo = $reactivar
            ? $this->estatus->cambiarEstatus($s->fresh(), Suscripcion::ESTATUS_ACTIVO, "Pago registrado ({$datos['referencia']})")
            : null;

        return ['pago' => $pago, 'reactivada' => $reactivar, 'app_confirmo' => $confirmo];
    }

    /**
     * Suspende las suscripciones vencidas. Idempotente: puede correr a las
     * 00:00 y cada hora (recupera un corte perdido si el servidor estuvo abajo).
     *
     * @return list<array{suscripcion: int, cliente: string, producto: string, app_confirmo: bool}>
     */
    public function procesarVencimientos(): array
    {
        $hoy = self::hoy()->toDateString();
        $suspendidas = [];
        // Fase 3: las prórrogas cuyo último día ya pasó quedan vencidas.
        app(ProrrogaService::class)->marcarVencidas();

        $candidatas = Suscripcion::with(['cliente', 'producto'])
            ->where('estatus', Suscripcion::ESTATUS_ACTIVO)
            ->where('suspension_automatica', true)
            // 05-oct-2026: solo clientes comerciales; demo, capacitación y prueba nunca se suspenden por vencimiento.
            ->whereHas('cliente', fn ($q) => $q->where('tipo', \App\Models\Landlord\Cliente::TIPO_COMERCIAL))
            ->whereNotNull('fecha_proximo_pago')
            ->where(fn ($q) => $q->whereNull('activa_hasta')->orWhere('activa_hasta', '<', $hoy))
            ->get();

        foreach ($candidatas as $s) {
            $corte = CarbonImmutable::parse($s->fecha_proximo_pago->toDateString())->addDays((int) $s->dias_gracia)->toDateString();
            if ($corte > $hoy) {
                continue;
            }

            $confirmo = $this->estatus->cambiarEstatus($s, Suscripcion::ESTATUS_SUSPENDIDO,
                "Vencimiento del {$s->fecha_proximo_pago->format('d/m/Y')}", SuscripcionEstatusService::CAUSA_VENCIMIENTO);

            $suspendidas[] = ['suscripcion' => $s->id, 'cliente' => $s->cliente->slug, 'producto' => $s->producto->slug, 'app_confirmo' => $confirmo];
        }

        return $suspendidas;
    }

    private function validarModalidad(?string $modalidad): void
    {
        if (! array_key_exists((string) $modalidad, config('kernia.modalidades'))) {
            throw new RuntimeException('Modalidad no válida. Usa: '.implode(', ', array_keys(config('kernia.modalidades'))).'.');
        }
    }
}
