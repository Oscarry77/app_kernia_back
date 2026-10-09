<?php
namespace App\Services\Landlord;

use App\Models\Landlord\Auditoria;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\NivelAutorizacion;
use App\Models\Landlord\SolicitudPlan;
use App\Models\Landlord\SolicitudSalida;
use App\Models\Landlord\SolicitudSalidaIntento;
use App\Models\Landlord\Suscripcion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Salida de una suscripción (08-oct-2026). Reglas en REGISTRO_DECISIONES_KERNIA.md
 * §3 y §6 y en el estándar v2.3 §4.1 y §7:
 *  - "Retirar app" → `retirado`: baja lógica, base intacta. "Reactivar" la
 *    regresa a `activo`. "Finiquitar" → `en_finiquito`: salida definitiva; el
 *    administrador del cliente solo entra a Descargas durante el plazo.
 *  - Todo se SOLICITA y lo AUTORIZA una persona del escalafón tecleando su
 *    correo y contraseña (como los cambios de plan). Nadie autoriza su propia
 *    solicitud (salvo superadmin); sin escalafón capturado, solo el superadmin.
 *  - El retiro y el finiquito se aplican en el corte de las 00:00 siguiente a
 *    la autorización, nunca a media jornada. La reactivación, al autorizarse.
 *  - El finiquito exige la conformidad del cliente (referencia del correo o
 *    documento firmado) y que el solicitante escriba el slug del cliente.
 *  - Kernia no manda estos estatus a una app que aún no confirma reconocerlos
 *    (`productos.estatus_salida`). El finiquito exige además que la app ya
 *    exporte y tenga Descargas (`productos.exportacion_v23`, 09-oct-2026).
 *  - Una sola solicitud de salida abierta por suscripción.
 *
 * Fuera de esta pieza (pendiente #4 del handoff del 07-oct): la exportación
 * del finiquito, sus correos y el paso a `finiquitado` y `eliminado`.
 */
class SalidaService
{
    /** Desde qué estatus se puede pedir cada tipo de salida. */
    private const DESDE = [
        SolicitudSalida::RETIRO => [Suscripcion::ESTATUS_ACTIVO, Suscripcion::ESTATUS_SUSPENDIDO],
        SolicitudSalida::REACTIVACION => [Suscripcion::ESTATUS_RETIRADO],
        SolicitudSalida::FINIQUITO => [Suscripcion::ESTATUS_ACTIVO, Suscripcion::ESTATUS_SUSPENDIDO, Suscripcion::ESTATUS_RETIRADO],
    ];

    public function __construct(private readonly SuscripcionEstatusService $estatus)
    {
    }

    /**
     * Tipos de salida que hoy se pueden pedir para la suscripción, con la
     * razón cuando no (para que el panel explique el botón deshabilitado).
     *
     * @return array<string, array{permitido: bool, razon: ?string}>
     */
    public function opciones(Suscripcion $s): array
    {
        $abierta = SolicitudSalida::where('suscripcion_id', $s->id)->whereIn('estado', SolicitudSalida::ABIERTAS)->exists();

        $opciones = [];
        foreach (SolicitudSalida::TIPOS as $tipo) {
            $razon = match (true) {
                ! in_array($s->estatus, self::DESDE[$tipo], true) => null,
                $abierta => 'Ya hay una solicitud de salida abierta para esta app.',
                $tipo !== SolicitudSalida::REACTIVACION && ! $s->producto->estatus_salida
                    => "{$s->producto->nombre} todavía no reconoce los estados de salida (estándar v2.3 §4.1).",
                $tipo === SolicitudSalida::FINIQUITO && ! $s->producto->exportacion_v23
                    => "{$s->producto->nombre} todavía no exporta ni tiene la sección Descargas (estándar v2.3).",
                default => '',
            };
            if ($razon !== null) {
                $opciones[$tipo] = ['permitido' => $razon === '', 'razon' => $razon ?: null];
            }
        }

        return $opciones;
    }

    public function solicitar(Suscripcion $s, string $tipo, array $datos, LandlordAdmin $solicitante): SolicitudSalida
    {
        if (! in_array($tipo, SolicitudSalida::TIPOS, true)) {
            throw new RuntimeException('Tipo de salida desconocido.');
        }
        if (! in_array($s->estatus, self::DESDE[$tipo], true)) {
            throw new RuntimeException(match ($tipo) {
                SolicitudSalida::REACTIVACION => 'Solo se reactiva una app retirada.',
                default => "No se puede ".($tipo === SolicitudSalida::RETIRO ? 'retirar' : 'finiquitar')." una suscripción '{$s->estatus}'.",
            });
        }
        if ($tipo !== SolicitudSalida::REACTIVACION && ! $s->producto->estatus_salida) {
            throw new RuntimeException("{$s->producto->nombre} todavía no reconoce los estados de salida (estándar v2.3 §4.1); Kernia no puede enviárselos.");
        }
        if ($tipo === SolicitudSalida::FINIQUITO && ! $s->producto->exportacion_v23) {
            throw new RuntimeException("{$s->producto->nombre} todavía no exporta ni tiene la sección Descargas (estándar v2.3); el cliente no podría descargar su respaldo.");
        }
        $motivo = trim((string) ($datos['motivo'] ?? ''));
        if ($motivo === '') {
            throw new RuntimeException('Indica el motivo.');
        }
        if (SolicitudSalida::where('suscripcion_id', $s->id)->whereIn('estado', SolicitudSalida::ABIERTAS)->exists()) {
            throw new RuntimeException('Ya hay una solicitud de salida pendiente o programada para esta app; resuélvela o cancélala primero.');
        }

        $conformidad = ['conformidad_tipo' => null, 'conformidad_referencia' => null];
        if ($tipo === SolicitudSalida::FINIQUITO) {
            $tipoConformidad = $datos['conformidad_tipo'] ?? null;
            $referencia = trim((string) ($datos['conformidad_referencia'] ?? ''));
            if (! in_array($tipoConformidad, [SolicitudSalida::CONFORMIDAD_CORREO, SolicitudSalida::CONFORMIDAD_DOCUMENTO], true) || $referencia === '') {
                throw new RuntimeException('El finiquito exige la conformidad del cliente: indica si es un correo o un documento firmado y su referencia.');
            }
            if (strtolower(trim((string) ($datos['confirmacion_slug'] ?? ''))) !== $s->cliente->slug) {
                throw new RuntimeException("Para confirmar el finiquito escribe exactamente el identificador del cliente ({$s->cliente->slug}).");
            }
            $conformidad = ['conformidad_tipo' => $tipoConformidad, 'conformidad_referencia' => mb_substr($referencia, 0, 500)];
        }

        // 08-oct-2026: formulario de salida obligatorio para el asesor (retiro y finiquito).
        $formularios = app(FormularioSalidaService::class);
        if ($tipo !== SolicitudSalida::REACTIVACION) {
            $formularios->validarMotivoAsesor($datos['motivo_salida'] ?? null, $motivo);
        }

        return DB::transaction(function () use ($s, $tipo, $motivo, $conformidad, $solicitante, $datos, $formularios) {
            $sol = SolicitudSalida::create([
                'suscripcion_id' => $s->id,
                'tipo' => $tipo,
                'estatus_anterior' => $s->estatus,
                'motivo' => $motivo,
                ...$conformidad,
                'estado' => SolicitudSalida::SOLICITADA,
                'solicitada_por' => $solicitante->id,
            ]);
            if ($tipo !== SolicitudSalida::REACTIVACION) {
                $formularios->registrarAsesor($s, $tipo, $datos['motivo_salida'], $motivo, $solicitante, ['solicitud_salida_id' => $sol->id]);
            }

            return $sol;
        });
    }

    /**
     * @param 'autorizar'|'rechazar' $accion
     * @return array{solicitud: SolicitudSalida, aplicada: bool}
     */
    public function resolver(SolicitudSalida $sol, string $accion, string $email, string $password, ?string $comentario, ?LandlordAdmin $sesion, ?string $ip): array
    {
        $intento = fn (string $resultado) => SolicitudSalidaIntento::create([
            'solicitud_salida_id' => $sol->id, 'sesion_usuario_id' => $sesion?->id, 'email_tecleado' => mb_substr($email, 0, 150),
            'accion' => $accion, 'resultado' => $resultado, 'ip' => $ip,
        ]);

        if ($sol->estado !== SolicitudSalida::SOLICITADA) {
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
            $sol->update(['estado' => SolicitudSalida::RECHAZADA, 'resuelta_por' => $autorizador->id, 'nivel_autorizacion' => $nivel,
                'comentario_resolucion' => trim($comentario), 'resuelta_en' => now()]);
            $intento('rechazada');

            return ['solicitud' => $sol->fresh(), 'aplicada' => false];
        }

        $s = $sol->suscripcion;
        if (! in_array($s->estatus, self::DESDE[$sol->tipo], true)) {
            throw new RuntimeException("La suscripción cambió a '{$s->estatus}' desde que se hizo la solicitud; cancélala.");
        }

        $datos = ['resuelta_por' => $autorizador->id, 'nivel_autorizacion' => $nivel,
            'comentario_resolucion' => trim((string) $comentario) ?: null, 'resuelta_en' => now()];
        $intento('autorizada');

        if ($sol->tipo === SolicitudSalida::REACTIVACION) {
            $sol->update([...$datos, 'estado' => SolicitudSalida::PROGRAMADA, 'fecha_efectiva' => VigenciaService::hoy()->toDateString()]);

            return ['solicitud' => $this->aplicar($sol->fresh()), 'aplicada' => true];
        }

        // Retiro y finiquito: en el corte de las 00:00 de mañana.
        $sol->update([...$datos, 'estado' => SolicitudSalida::PROGRAMADA, 'fecha_efectiva' => VigenciaService::hoy()->addDay()->toDateString()]);

        return ['solicitud' => $sol->fresh(), 'aplicada' => false];
    }

    public function cancelar(SolicitudSalida $sol, string $motivo): SolicitudSalida
    {
        if (! in_array($sol->estado, SolicitudSalida::ABIERTAS, true)) {
            throw new RuntimeException('Solo se cancela una solicitud pendiente o programada.');
        }
        if (! trim($motivo)) {
            throw new RuntimeException('Indica el motivo de la cancelación.');
        }

        $sol->update(['estado' => SolicitudSalida::CANCELADA, 'comentario_resolucion' => trim($motivo), 'resuelta_en' => now()]);

        return $sol->fresh();
    }

    /**
     * Aplica las salidas programadas cuya fecha ya llegó. Lo llama el corte de
     * las 00:00 (y cada hora); idempotente.
     *
     * @return list<SolicitudSalida>
     */
    public function aplicarProgramadas(): array
    {
        return SolicitudSalida::with('suscripcion.producto', 'suscripcion.cliente')
            ->where('estado', SolicitudSalida::PROGRAMADA)
            ->whereDate('fecha_efectiva', '<=', VigenciaService::hoy()->toDateString())
            ->orderBy('id')->get()
            ->map(fn (SolicitudSalida $sol) => $this->aplicar($sol))
            ->all();
    }

    /**
     * Banner para la app del cliente (`aviso` del resolve): una salida
     * programada mientras la suscripción está activa, o el plazo de descarga
     * en `en_finiquito`.
     */
    public function aviso(Suscripcion $s): ?array
    {
        if ($s->estatus === Suscripcion::ESTATUS_EN_FINIQUITO && $s->descarga_hasta) {
            return [
                'nivel' => 'critico',
                'tipo' => 'finiquito',
                'descarga_hasta' => $s->descarga_hasta->toDateString(),
                'mensaje' => "Tu servicio concluyó. Descarga el respaldo de tu información en «Descargas» a más tardar el {$s->descarga_hasta->format('d/m/Y')}.",
            ];
        }
        if ($s->estatus !== Suscripcion::ESTATUS_ACTIVO) {
            return null;
        }

        $sol = SolicitudSalida::where('suscripcion_id', $s->id)->where('estado', SolicitudSalida::PROGRAMADA)
            ->whereIn('tipo', [SolicitudSalida::RETIRO, SolicitudSalida::FINIQUITO])->orderBy('fecha_efectiva')->first();
        if (! $sol) {
            return null;
        }

        return [
            'nivel' => 'advertencia',
            'tipo' => $sol->tipo,
            'fecha_efectiva' => $sol->fecha_efectiva->toDateString(),
            'mensaje' => $sol->tipo === SolicitudSalida::FINIQUITO
                ? "Tu servicio concluye el {$sol->fecha_efectiva->format('d/m/Y')}. Después podrás descargar el respaldo de tu información en «Descargas»."
                : "Este servicio se dará de baja el {$sol->fecha_efectiva->format('d/m/Y')}. Si tienes dudas, contacta a tu asesor.",
        ];
    }

    private function aplicar(SolicitudSalida $sol): SolicitudSalida
    {
        $s = $sol->suscripcion;
        $fallar = function (string $error) use ($sol, $s) {
            $sol->update(['estado' => SolicitudSalida::FALLIDA, 'error' => mb_substr($error, 0, 300)]);
            Auditoria::registrar('suscripcion.salida_fallida', null, $s, null, ['solicitud_salida_id' => $sol->id, 'tipo' => $sol->tipo, 'error' => $sol->error]);

            return $sol->fresh();
        };

        if (! in_array($s->estatus, self::DESDE[$sol->tipo], true)) {
            return $fallar("La suscripción está '{$s->estatus}'; ya no admite esta salida.");
        }
        if ($sol->tipo !== SolicitudSalida::REACTIVACION && ! $s->producto->estatus_salida) {
            return $fallar("{$s->producto->nombre} todavía no reconoce los estados de salida.");
        }
        if ($sol->tipo === SolicitudSalida::FINIQUITO && ! $s->producto->exportacion_v23) {
            return $fallar("{$s->producto->nombre} todavía no exporta ni tiene la sección Descargas.");
        }

        $antes = ['estatus' => $s->estatus];
        [$estatus, $motivoApp] = match ($sol->tipo) {
            SolicitudSalida::RETIRO => [Suscripcion::ESTATUS_RETIRADO, 'Servicio dado de baja'],
            SolicitudSalida::REACTIVACION => [Suscripcion::ESTATUS_ACTIVO, 'Servicio reactivado'],
            SolicitudSalida::FINIQUITO => [Suscripcion::ESTATUS_EN_FINIQUITO, 'Finiquito del servicio'],
        };

        $s->update(['descarga_hasta' => $sol->tipo === SolicitudSalida::FINIQUITO
            ? VigenciaService::hoy()->addDays((int) config('kernia.finiquito_descarga_dias'))->toDateString()
            : null]);
        $confirmo = $this->estatus->cambiarEstatus($s, $estatus, $motivoApp);

        // Lo que quedaba abierto deja de tener sentido al salir: cambios de plan y prórrogas.
        if ($sol->tipo !== SolicitudSalida::REACTIVACION) {
            $s->update(['activa_hasta' => null]);
            SolicitudPlan::where('suscripcion_id', $s->id)->whereIn('estado', [SolicitudPlan::SOLICITADA, SolicitudPlan::PROGRAMADA])->update([
                'estado' => SolicitudPlan::CANCELADA, 'resuelta_en' => now(),
                'comentario_resolucion' => $sol->tipo === SolicitudSalida::FINIQUITO ? 'Cancelada por el finiquito del servicio.' : 'Cancelada por el retiro de la app.',
            ]);
            app(ProrrogaService::class)->cerrarPorSalida($s);
        }

        $sol->update(['estado' => SolicitudSalida::APLICADA, 'aplicada_en' => now()]);
        $s = $s->fresh();
        Auditoria::registrar("suscripcion.{$sol->tipo}", null, $s, $antes, [
            'solicitud_salida_id' => $sol->id, 'estatus' => $s->estatus, 'autorizada_por' => $sol->resuelta_por,
            'descarga_hasta' => $s->descarga_hasta?->toDateString(), 'app_confirmo' => $confirmo,
        ]);

        return $sol->fresh();
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
