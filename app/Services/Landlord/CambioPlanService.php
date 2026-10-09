<?php
namespace App\Services\Landlord;

use App\Models\Landlord\Auditoria;
use App\Models\Landlord\FormularioSalida;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\NivelAutorizacion;
use App\Models\Landlord\Pago;
use App\Models\Landlord\SolicitudPlan;
use App\Models\Landlord\SolicitudPlanIntento;
use App\Models\Landlord\Suscripcion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Cambio de plan con autorización del escalafón (05-oct-2026). Reglas en
 * REGISTRO_DECISIONES_KERNIA.md §3:
 *  - Subir y bajar de plan se SOLICITA y lo AUTORIZA una persona del
 *    escalafón tecleando su correo y contraseña (como las prórrogas).
 *  - Es BAJADA si el plan nuevo quita algún módulo o reduce algún límite;
 *    si no, SUBIDA. La subida se aplica al autorizarse. La bajada SIEMPRE la
 *    aplica el corte de las 00:00 (decisión del dueño, 05-oct): en la fecha de
 *    próximo pago ('renovacion') o en el siguiente corte ('inmediata').
 *  - No se autoriza la propia solicitud (salvo superadmin). Sin escalafón
 *    capturado, solo el superadmin autoriza. Todo intento queda registrado.
 *  - Una sola solicitud abierta (pendiente o programada) por suscripción.
 *
 * Pendiente del estándar v2.2: en una bajada con más empresas que el nuevo
 * límite, elegir las que se conservan y bloquear las demás en la app. Hasta
 * entonces la bajada funciona como el 01-oct: nada se bloquea, solo se
 * impide crear empresas nuevas.
 */
class CambioPlanService
{
    public function __construct(private readonly SuscripcionPlanService $planes)
    {
    }

    /**
     * Qué cambia si la suscripción pasa a `$codigo`: módulos que gana y
     * pierde, límites antes y después, y si es subida o bajada.
     */
    public function previsualizar(Suscripcion $s, string $codigo): array
    {
        $producto = $s->producto;
        $nuevo = $producto->plan($codigo);
        if (! $nuevo) {
            throw new RuntimeException("El plan '{$codigo}' no existe o no está disponible para {$producto->nombre}.");
        }
        if ($codigo === $s->plan) {
            throw new RuntimeException("El cliente ya tiene el plan {$nuevo->nombre}.");
        }

        $actual = $producto->planVigente($s->plan);
        $modulosAntes = $s->clavesModulosActivos() ?? [];
        $modulosDespues = $nuevo->modulos ?? [];
        $limitesAntes = $this->planes->limitesEfectivos($s) ?? [];
        $limitesDespues = $this->planes->limitesConPlan($s, $nuevo);

        $pierde = array_values(array_diff($modulosAntes, $modulosDespues));
        $reduce = [];
        foreach ($limitesDespues as $clave => $despues) {
            $antes = $limitesAntes[$clave] ?? null;
            // null = sin límite: pasar de "sin límite" a un número es reducir.
            if ($despues !== null && ($antes === null || $despues < $antes)) {
                $reduce[] = $clave;
            }
        }

        return [
            'plan_actual' => $s->plan,
            'plan_actual_nombre' => $actual?->nombre,
            'plan_nuevo' => $nuevo->codigo,
            'plan_nuevo_nombre' => $nuevo->nombre,
            'direccion' => ($pierde || $reduce) ? SolicitudPlan::BAJADA : SolicitudPlan::SUBIDA,
            'modulos_gana' => array_values(array_diff($modulosDespues, $modulosAntes)),
            'modulos_pierde' => $pierde,
            'limites_antes' => $limitesAntes,
            'limites_despues' => $limitesDespues,
            'limites_reducidos' => $reduce,
            'fecha_proximo_pago' => $s->fecha_proximo_pago?->toDateString(),
            // 09-oct-2026: la app entrega su lista de empresas (v2.2): el asesor elige cuáles conserva.
            'usa_v22' => (bool) $producto->empresas_v22,
        ];
    }

    /**
     * @param list<int>|null $empresasConservar 09-oct-2026: en una baja con una app v2.2, las empresas
     *                                          que conserva el cliente; null = aún no decide.
     */
    public function solicitar(Suscripcion $s, string $codigo, string $aplicacion, string $motivo, LandlordAdmin $solicitante, ?string $motivoSalida = null, ?array $empresasConservar = null): SolicitudPlan
    {
        if ($s->estatus !== Suscripcion::ESTATUS_ACTIVO) {
            throw new RuntimeException('Solo se cambia el plan de una suscripción activa.');
        }
        if (! trim($motivo)) {
            throw new RuntimeException('Indica el motivo del cambio de plan.');
        }
        if (SolicitudPlan::where('suscripcion_id', $s->id)->whereIn('estado', SolicitudPlan::ABIERTAS)->exists()) {
            throw new RuntimeException('Ya hay un cambio de plan pendiente o programado para esta suscripción; resuélvelo o cancélalo primero.');
        }

        $vista = $this->previsualizar($s, $codigo);
        $direccion = $vista['direccion'];

        // Subir de plan no le quita nada al cliente: siempre es inmediato.
        if ($direccion === SolicitudPlan::SUBIDA) {
            $aplicacion = SolicitudPlan::INMEDIATA;
        } elseif (! in_array($aplicacion, [SolicitudPlan::INMEDIATA, SolicitudPlan::RENOVACION], true)) {
            throw new RuntimeException('Indica si la baja se aplica en la renovación o de inmediato.');
        } elseif ($aplicacion === SolicitudPlan::RENOVACION && ! $s->fecha_proximo_pago) {
            throw new RuntimeException('Esta suscripción no tiene fecha de próximo pago: la baja solo puede aplicarse en el siguiente corte de las 00:00.');
        }

        // 08-oct-2026: una baja de plan también registra el motivo de salida (formulario del asesor).
        $formularios = app(FormularioSalidaService::class);
        if ($direccion === SolicitudPlan::BAJADA) {
            $formularios->validarMotivoAsesor($motivoSalida, $motivo);
        }
        $bajas = app(BajaPlanService::class);
        $empresasConservar = $direccion === SolicitudPlan::BAJADA && $bajas->usaV22($s)
            ? $bajas->validarLista($s, $vista['plan_nuevo'], $empresasConservar)
            : null;

        return DB::transaction(function () use ($s, $vista, $direccion, $aplicacion, $motivo, $solicitante, $motivoSalida, $formularios, $empresasConservar) {
            $sol = SolicitudPlan::create([
                'suscripcion_id' => $s->id,
                'plan_actual' => $s->plan,
                'plan_nuevo' => $vista['plan_nuevo'],
                'direccion' => $direccion,
                'aplicacion' => $aplicacion,
                'motivo' => trim($motivo),
                'empresas_conservar' => $empresasConservar,
                'estado' => SolicitudPlan::SOLICITADA,
                'solicitada_por' => $solicitante->id,
            ]);
            if ($direccion === SolicitudPlan::BAJADA) {
                $formularios->registrarAsesor($s, FormularioSalida::EVENTO_BAJA_PLAN, $motivoSalida, $motivo, $solicitante, ['solicitud_plan_id' => $sol->id]);
            }

            return $sol;
        });
    }

    /**
     * @param 'autorizar'|'rechazar' $accion
     * @return array{solicitud: SolicitudPlan, aplicada: bool}
     */
    public function resolver(SolicitudPlan $sol, string $accion, string $email, string $password, ?string $comentario, ?LandlordAdmin $sesion, ?string $ip): array
    {
        $intento = fn (string $resultado) => SolicitudPlanIntento::create([
            'solicitud_plan_id' => $sol->id, 'sesion_usuario_id' => $sesion?->id, 'email_tecleado' => mb_substr($email, 0, 150),
            'accion' => $accion, 'resultado' => $resultado, 'ip' => $ip,
        ]);

        if ($sol->estado !== SolicitudPlan::SOLICITADA) {
            throw new RuntimeException('Esta solicitud ya fue resuelta.');
        }

        $autorizador = LandlordAdmin::where('email', strtolower(trim($email)))->where('activo', true)->first();
        if (! $autorizador || ! Hash::check($password, $autorizador->password)) {
            $intento('credencial_invalida');
            throw new RuntimeException('Usuario o contraseña incorrectos.');
        }

        if ($autorizador->id === $sol->solicitada_por && ! $autorizador->esSuperadmin()) {
            $intento('autoriza_propia');
            throw new RuntimeException('No puedes resolver tu propia solicitud; debe hacerlo otra persona del escalafón.');
        }

        $nivel = $this->nivelDe($autorizador);
        if ($nivel === null) {
            $intento('sin_nivel');
            throw new RuntimeException(NivelAutorizacion::where('activo', true)->exists()
                ? 'Esa persona no tiene un nivel en el escalafón de autorización.'
                : 'Aún no hay escalafón capturado: solo el superadministrador puede resolver.');
        }

        if ($accion === 'rechazar') {
            if (! trim((string) $comentario)) {
                throw new RuntimeException('Indica el motivo del rechazo.');
            }
            $sol->update(['estado' => SolicitudPlan::RECHAZADA, 'resuelta_por' => $autorizador->id, 'nivel_autorizacion' => $nivel,
                'comentario_resolucion' => trim($comentario), 'resuelta_en' => now()]);
            $intento('rechazada');

            return ['solicitud' => $sol->fresh(), 'aplicada' => false];
        }

        $s = $sol->suscripcion;
        if ($s->estatus !== Suscripcion::ESTATUS_ACTIVO) {
            throw new RuntimeException('La suscripción ya no está activa; cancela la solicitud.');
        }
        if ($s->plan !== $sol->plan_actual) {
            throw new RuntimeException('El plan del cliente cambió desde que se hizo la solicitud; cancélala y solicita de nuevo.');
        }

        $datos = ['resuelta_por' => $autorizador->id, 'nivel_autorizacion' => $nivel,
            'comentario_resolucion' => trim((string) $comentario) ?: null, 'resuelta_en' => now()];
        $intento('autorizada');

        if ($sol->direccion === SolicitudPlan::SUBIDA) {
            $sol->update([...$datos, 'estado' => SolicitudPlan::PROGRAMADA, 'fecha_efectiva' => VigenciaService::hoy()->toDateString()]);

            return ['solicitud' => $this->aplicar($sol->fresh()), 'aplicada' => true];
        }

        // Bajada: siempre en un corte de las 00:00, nunca a media jornada. En
        // la renovación si la fecha aún no llega; si no, en el siguiente corte.
        $manana = VigenciaService::hoy()->addDay()->toDateString();
        $renovacion = $s->fecha_proximo_pago?->toDateString();
        $fecha = $sol->aplicacion === SolicitudPlan::RENOVACION && $renovacion && $renovacion > $manana ? $renovacion : $manana;
        $sol->update([...$datos, 'estado' => SolicitudPlan::PROGRAMADA, 'fecha_efectiva' => $fecha]);

        return ['solicitud' => $sol->fresh(), 'aplicada' => false];
    }

    public function cancelar(SolicitudPlan $sol, string $motivo): SolicitudPlan
    {
        // Una baja en ejecución (aviso, mantenimiento, ajuste) ya no se cancela: termina o se revierte sola.
        if (! in_array($sol->estado, [SolicitudPlan::SOLICITADA, SolicitudPlan::PROGRAMADA], true)) {
            throw new RuntimeException('Solo se cancela una solicitud pendiente o programada.');
        }
        if (! trim($motivo)) {
            throw new RuntimeException('Indica el motivo de la cancelación.');
        }

        $sol->update(['estado' => SolicitudPlan::CANCELADA, 'comentario_resolucion' => trim($motivo), 'resuelta_en' => now()]);

        return $sol->fresh();
    }

    /**
     * Aplica los cambios programados cuya fecha ya llegó. Lo llama el corte de
     * vencimientos (00:00 de México y cada hora); idempotente.
     *
     * @return list<SolicitudPlan>
     */
    public function aplicarProgramadas(): array
    {
        return SolicitudPlan::with('suscripcion.producto')
            ->where('estado', SolicitudPlan::PROGRAMADA)
            ->whereDate('fecha_efectiva', '<=', VigenciaService::hoy()->toDateString())
            ->orderBy('id')->get()
            ->map(fn (SolicitudPlan $sol) => $this->aplicarOIniciar($sol))
            ->all();
    }

    /**
     * 09-oct-2026: una baja con una app v2.2 que deja al cliente con más
     * empresas de las permitidas no se aplica de golpe: arranca la
     * orquestación (aviso → mantenimiento → ajuste-plan). Si la app no
     * responde para saberlo, la solicitud sigue programada y se reintenta en
     * el siguiente corte (cada hora).
     */
    private function aplicarOIniciar(SolicitudPlan $sol): SolicitudPlan
    {
        $bajas = app(BajaPlanService::class);
        if ($sol->direccion !== SolicitudPlan::BAJADA || ! $bajas->usaV22($sol->suscripcion)) {
            return $this->aplicar($sol);
        }

        try {
            $requiere = $bajas->requiereAjuste($sol);
        } catch (\Throwable $e) {
            $sol->update(['error' => mb_substr('No se pudo consultar las empresas en la app; se reintenta: '.$e->getMessage(), 0, 300)]);

            return $sol->fresh();
        }

        return $requiere ? $bajas->iniciar($sol) : $this->aplicar($sol);
    }

    /**
     * Banner para la app del cliente (`aviso` del resolve) mientras haya una
     * baja programada: el cliente sabe qué pasará y cuándo (05-oct-2026).
     */
    public function avisoProgramado(Suscripcion $s): ?array
    {
        $sol = SolicitudPlan::where('suscripcion_id', $s->id)->where('estado', SolicitudPlan::PROGRAMADA)
            ->where('direccion', SolicitudPlan::BAJADA)->orderBy('fecha_efectiva')->first();
        if (! $sol) {
            return null;
        }

        $plan = $s->producto->planVigente($sol->plan_nuevo)?->nombre ?? $sol->plan_nuevo;

        return [
            'nivel' => 'info',
            'tipo' => 'cambio_plan',
            'fecha_efectiva' => $sol->fecha_efectiva->toDateString(),
            'mensaje' => "Tu plan cambiará a {$plan} a partir del {$sol->fecha_efectiva->format('d/m/Y')}. Si tienes dudas, contacta a tu asesor.",
        ];
    }

    /**
     * Resumen de pagos para quien resuelve (decisión del dueño: si la baja no
     * se aplica, el escalafón ve lo pagado y lo pendiente para buscar opciones).
     */
    public function resumenPagos(Suscripcion $s): array
    {
        $ultimo = Pago::where('suscripcion_id', $s->id)->orderByDesc('fecha_pago')->orderByDesc('id')->first();

        return [
            'modalidad_pago' => $s->modalidad_pago,
            'fecha_proximo_pago' => $s->fecha_proximo_pago?->toDateString(),
            'dias_restantes' => app(VigenciaService::class)->diasRestantes($s),
            'pagos_registrados' => Pago::where('suscripcion_id', $s->id)->count(),
            'ultimo_pago' => $ultimo ? [
                'fecha' => $ultimo->fecha_pago?->toDateString(),
                'periodo_hasta' => $ultimo->periodo_hasta?->toDateString(),
                'referencia' => $ultimo->referencia,
            ] : null,
        ];
    }

    private function aplicar(SolicitudPlan $sol): SolicitudPlan
    {
        $s = $sol->suscripcion;
        $fallar = function (string $error) use ($sol, $s) {
            $sol->update(['estado' => SolicitudPlan::FALLIDA, 'error' => mb_substr($error, 0, 300)]);
            $this->registrar('suscripcion.cambio_plan_fallido', $sol, $s, ['error' => $sol->error]);

            return $sol->fresh();
        };

        if ($s->estatus === Suscripcion::ESTATUS_CANCELADO || in_array($s->estatus, Suscripcion::ESTATUS_SALIDA, true)) {
            $sol->update(['estado' => SolicitudPlan::CANCELADA, 'comentario_resolucion' => 'La suscripción fue cancelada o dada de baja antes de aplicar el cambio.']);

            return $sol->fresh();
        }
        if ($s->plan !== $sol->plan_actual) {
            return $fallar('El plan del cliente cambió desde que se autorizó la solicitud.');
        }

        $antes = ['plan' => $s->plan, 'limites' => $this->planes->limitesEfectivos($s)];
        try {
            $this->planes->aplicarPlan($s, $sol->plan_nuevo);
        } catch (RuntimeException $e) {
            return $fallar($e->getMessage());
        }

        $sol->update(['estado' => SolicitudPlan::APLICADA, 'aplicada_en' => now()]);
        $s = $s->fresh();
        $this->registrar('suscripcion.plan_cambiado', $sol, $s, ['plan' => $s->plan, 'limites' => $this->planes->limitesEfectivos($s)], $antes);

        return $sol->fresh();
    }

    /** Bitácora; desde el corte no hay operador en sesión (usuario_id queda vacío). */
    private function registrar(string $accion, SolicitudPlan $sol, Suscripcion $s, array $despues, ?array $antes = null): void
    {
        Auditoria::registrar($accion, null, $s, $antes, [
            'solicitud_plan_id' => $sol->id, 'direccion' => $sol->direccion, 'aplicacion' => $sol->aplicacion,
            'autorizada_por' => $sol->resuelta_por, ...$despues,
        ]);
    }

    /** Nivel en el escalafón; el superadmin siempre puede (nivel 99). */
    private function nivelDe(LandlordAdmin $a): ?int
    {
        if ($a->esSuperadmin()) {
            return 99;
        }

        return NivelAutorizacion::where('usuario_id', $a->id)->where('activo', true)->value('nivel');
    }
}
