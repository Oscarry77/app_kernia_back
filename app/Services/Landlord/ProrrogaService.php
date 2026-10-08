<?php
namespace App\Services\Landlord;

use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\NivelAutorizacion;
use App\Models\Landlord\Prorroga;
use App\Models\Landlord\ProrrogaIntento;
use App\Models\Landlord\Suscripcion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Prórrogas (autorización provisional de acceso), fase 3, 02-oct-2026.
 * Reglas en REGISTRO_DECISIONES_KERNIA.md §4 y §6:
 *  - Solo para suscripciones suspendidas por vencimiento; 1 a 6 días desde
 *    el día en que se autoriza; motivo de lista fija.
 *  - Quien autoriza teclea SU correo y contraseña (aunque haya sesión) y debe
 *    tener nivel en el escalafón con dias_max ≥ días. Sin escalafón
 *    capturado, solo el superadmin autoriza. El superadmin siempre puede.
 *  - No se autoriza la propia solicitud (salvo superadmin).
 *  - Una prórroga por ciclo de vencimiento; la segunda exige un nivel mayor.
 *  - Todo intento queda en prorroga_intentos, incluidos los fallidos.
 */
class ProrrogaService
{
    public function __construct(private readonly SuscripcionEstatusService $estatus)
    {
    }

    public function solicitar(Suscripcion $s, int $dias, string $motivo, ?string $detalle, LandlordAdmin $solicitante): Prorroga
    {
        if ($s->estatus !== Suscripcion::ESTATUS_SUSPENDIDO || $s->suspension_motivo !== SuscripcionEstatusService::CAUSA_VENCIMIENTO) {
            throw new RuntimeException('Solo se solicita prórroga para una suscripción suspendida por vencimiento.');
        }
        if ($dias < 1 || $dias > config('kernia_acl.prorroga_max_dias')) {
            throw new RuntimeException('La prórroga es de 1 a '.config('kernia_acl.prorroga_max_dias').' días.');
        }
        if (! array_key_exists($motivo, config('kernia_acl.motivos_prorroga'))) {
            throw new RuntimeException('Motivo no válido.');
        }
        if ($motivo === 'otro' && ! trim((string) $detalle)) {
            throw new RuntimeException('Describe el motivo en el detalle.');
        }
        if (Prorroga::where('suscripcion_id', $s->id)->where('estado', Prorroga::SOLICITADA)->exists()) {
            throw new RuntimeException('Ya hay una solicitud de prórroga pendiente para esta suscripción.');
        }

        return Prorroga::create([
            'suscripcion_id' => $s->id,
            'fecha_vencimiento' => $s->fecha_proximo_pago,
            'dias' => $dias,
            'motivo' => $motivo,
            'detalle' => $detalle,
            'estado' => Prorroga::SOLICITADA,
            'solicitada_por' => $solicitante->id,
        ]);
    }

    /**
     * @param 'autorizar'|'rechazar' $accion
     * @return array{prorroga: Prorroga, app_confirmo: bool|null}
     */
    public function resolver(Prorroga $p, string $accion, string $email, string $password, ?string $comentario, ?LandlordAdmin $sesion, ?string $ip): array
    {
        $intento = fn (string $resultado) => ProrrogaIntento::create([
            'prorroga_id' => $p->id, 'sesion_usuario_id' => $sesion?->id, 'email_tecleado' => mb_substr($email, 0, 150),
            'accion' => $accion, 'resultado' => $resultado, 'ip' => $ip,
        ]);

        if ($p->estado !== Prorroga::SOLICITADA) {
            throw new RuntimeException('Esta solicitud ya fue resuelta.');
        }

        $autorizador = LandlordAdmin::where('email', strtolower(trim($email)))->where('activo', true)->first();
        if (! $autorizador || ! Hash::check($password, $autorizador->password)) {
            $intento('credencial_invalida');
            throw new RuntimeException('Usuario o contraseña incorrectos.');
        }

        if ($autorizador->id === $p->solicitada_por && ! $autorizador->esSuperadmin()) {
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
            $p->update(['estado' => Prorroga::RECHAZADA, 'resuelta_por' => $autorizador->id, 'nivel_autorizacion' => $nivel['nivel'],
                'comentario_resolucion' => $comentario, 'resuelta_en' => now()]);
            $intento('rechazada');

            return ['prorroga' => $p->fresh(), 'app_confirmo' => null];
        }

        if ($nivel['dias_max'] < $p->dias) {
            $intento('nivel_insuficiente');
            throw new RuntimeException("Tu nivel autoriza hasta {$nivel['dias_max']} día(s); se solicitaron {$p->dias}.");
        }

        $anterior = Prorroga::where('suscripcion_id', $p->suscripcion_id)
            ->where('fecha_vencimiento', $p->fecha_vencimiento)
            ->whereIn('estado', [Prorroga::AUTORIZADA, Prorroga::VENCIDA])
            ->orderByDesc('nivel_autorizacion')->first();
        if ($anterior && $nivel['nivel'] <= (int) $anterior->nivel_autorizacion && ! $autorizador->esSuperadmin()) {
            $intento('nivel_insuficiente');
            throw new RuntimeException('Ya hubo una prórroga en este vencimiento: una segunda la autoriza un nivel mayor.');
        }

        $s = $p->suscripcion;
        if ($s->estatus !== Suscripcion::ESTATUS_SUSPENDIDO) {
            throw new RuntimeException('La suscripción ya no está suspendida; no hace falta prórroga.');
        }

        $desde = VigenciaService::hoy();
        $hasta = $desde->addDays($p->dias - 1);

        DB::transaction(function () use ($p, $s, $autorizador, $nivel, $comentario, $desde, $hasta) {
            $p->update(['estado' => Prorroga::AUTORIZADA, 'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(),
                'resuelta_por' => $autorizador->id, 'nivel_autorizacion' => $nivel['nivel'], 'comentario_resolucion' => $comentario,
                'resuelta_en' => now()]);
            $s->update(['activa_hasta' => $hasta->toDateString()]);
        });
        $intento('autorizada');

        $confirmo = $this->estatus->cambiarEstatus($s->fresh(), Suscripcion::ESTATUS_ACTIVO,
            "Prórroga autorizada hasta el {$hasta->format('d/m/Y')}");

        return ['prorroga' => $p->fresh(), 'app_confirmo' => $confirmo];
    }

    /** Un pago cierra la prórroga vigente y cancela solicitudes pendientes. */
    public function cerrarPorPago(Suscripcion $s): void
    {
        Prorroga::where('suscripcion_id', $s->id)->where('estado', Prorroga::AUTORIZADA)->update(['estado' => Prorroga::CERRADA_POR_PAGO]);
        Prorroga::where('suscripcion_id', $s->id)->where('estado', Prorroga::SOLICITADA)->update(['estado' => 'cancelada']);
    }

    /** 08-oct-2026: retirar o finiquitar la app cierra la prórroga vigente y cancela las pendientes. */
    public function cerrarPorSalida(Suscripcion $s): void
    {
        Prorroga::where('suscripcion_id', $s->id)->where('estado', Prorroga::AUTORIZADA)->update(['estado' => Prorroga::CERRADA_POR_SALIDA]);
        Prorroga::where('suscripcion_id', $s->id)->where('estado', Prorroga::SOLICITADA)->update(['estado' => 'cancelada']);
    }

    /** Marca como vencidas las prórrogas cuyo último día ya pasó. */
    public function marcarVencidas(): int
    {
        return Prorroga::where('estado', Prorroga::AUTORIZADA)
            ->where('hasta', '<', VigenciaService::hoy()->toDateString())
            ->update(['estado' => Prorroga::VENCIDA]);
    }

    /** @return array{nivel:int, dias_max:int}|null el superadmin siempre puede (nivel 99, días máximos). */
    private function nivelDe(LandlordAdmin $a): ?array
    {
        if ($a->esSuperadmin()) {
            return ['nivel' => 99, 'dias_max' => (int) config('kernia_acl.prorroga_max_dias')];
        }

        $n = NivelAutorizacion::where('usuario_id', $a->id)->where('activo', true)->first();

        return $n ? ['nivel' => $n->nivel, 'dias_max' => $n->dias_max] : null;
    }
}
