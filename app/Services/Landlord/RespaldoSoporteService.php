<?php
namespace App\Services\Landlord;

use App\Mail\ClaveRespaldoMail;
use App\Models\Landlord\Auditoria;
use App\Models\Landlord\CorreoEnviado;
use App\Models\Landlord\Exportacion;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\NivelAutorizacion;
use App\Models\Landlord\SolicitudRespaldo;
use App\Models\Landlord\SolicitudRespaldoIntento;
use App\Services\Boveda\BovedaService;
use App\Services\Correo\CentroCorreo;
use Illuminate\Support\Facades\Hash;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Soporte sobre un respaldo (09-oct-2026; REGISTRO_DECISIONES_KERNIA.md §3 del
 * 05-oct y estándar v2.3 §4.3 y §7). Todo lo autoriza el escalafón con correo
 * y contraseña (como los cambios de plan y las salidas):
 *
 *  - **Reenviar la contraseña**: si el cliente la pierde, sale de la bóveda al
 *    correo del administrador del cliente. Nadie la ve en pantalla.
 *  - **Entregar el 7z a soporte**: tras autorizarse, SOLO quien lo pidió puede
 *    descargarlo, UNA vez y dentro de 24 h. Kernia lo pasa desde la app sin
 *    guardarlo; soporte no puede abrirlo (no tiene la contraseña).
 *
 * Solo mientras el respaldo exista (antes de terminar la retención).
 */
class RespaldoSoporteService
{
    public const HORAS_DESCARGA = 24;

    public function __construct(
        private readonly BovedaService $boveda,
        private readonly CentroCorreo $correo,
        private readonly ProductoAppClient $app,
    ) {
    }

    public function solicitar(Exportacion $exp, string $tipo, string $motivo, LandlordAdmin $solicitante): SolicitudRespaldo
    {
        if (! in_array($tipo, SolicitudRespaldo::TIPOS, true)) {
            throw new RuntimeException('Tipo de solicitud desconocido.');
        }
        if (! trim($motivo)) {
            throw new RuntimeException('Indica el motivo.');
        }
        $this->validarVigente($exp, $tipo);
        if (SolicitudRespaldo::where('exportacion_id', $exp->id)->where('tipo', $tipo)
            ->whereIn('estado', [SolicitudRespaldo::SOLICITADA, SolicitudRespaldo::AUTORIZADA])->exists()) {
            throw new RuntimeException('Ya hay una solicitud igual abierta para este respaldo.');
        }

        return SolicitudRespaldo::create([
            'exportacion_id' => $exp->id, 'suscripcion_id' => $exp->suscripcion_id, 'tipo' => $tipo,
            'motivo' => trim($motivo), 'estado' => SolicitudRespaldo::SOLICITADA, 'solicitada_por' => $solicitante->id,
        ]);
    }

    /** @param 'autorizar'|'rechazar' $accion */
    public function resolver(SolicitudRespaldo $sol, string $accion, string $email, string $password, ?string $comentario, ?LandlordAdmin $sesion, ?string $ip): SolicitudRespaldo
    {
        $intento = fn (string $resultado) => SolicitudRespaldoIntento::create([
            'solicitud_respaldo_id' => $sol->id, 'sesion_usuario_id' => $sesion?->id, 'email_tecleado' => mb_substr($email, 0, 150),
            'accion' => $accion, 'resultado' => $resultado, 'ip' => $ip,
        ]);

        if ($sol->estado !== SolicitudRespaldo::SOLICITADA) {
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
        $nivel = $autorizador->esSuperadmin() ? 99 : NivelAutorizacion::where('usuario_id', $autorizador->id)->where('activo', true)->value('nivel');
        if ($nivel === null) {
            $intento('sin_nivel');
            throw new RuntimeException(NivelAutorizacion::where('activo', true)->exists()
                ? 'Esa persona no tiene un nivel en el escalafón de autorización.'
                : 'Aún no hay escalafón capturado: solo el superadministrador puede resolver.');
        }

        $datos = ['resuelta_por' => $autorizador->id, 'nivel_autorizacion' => $nivel, 'resuelta_en' => now()];
        if ($accion === 'rechazar') {
            if (! trim((string) $comentario)) {
                throw new RuntimeException('Indica el motivo del rechazo.');
            }
            $sol->update([...$datos, 'estado' => SolicitudRespaldo::RECHAZADA, 'comentario_resolucion' => trim($comentario)]);
            $intento('rechazada');

            return $sol->fresh();
        }

        $exp = $sol->exportacion;
        $this->validarVigente($exp, $sol->tipo);
        $intento('autorizada');
        $sol->update([...$datos, 'comentario_resolucion' => trim((string) $comentario) ?: null]);

        if ($sol->tipo === SolicitudRespaldo::REENVIO_CLAVE) {
            return $this->reenviarClave($sol->fresh(), $autorizador);
        }

        $sol->update(['estado' => SolicitudRespaldo::AUTORIZADA, 'vigente_hasta' => now()->addHours(self::HORAS_DESCARGA)]);

        return $sol->fresh();
    }

    public function cancelar(SolicitudRespaldo $sol, string $motivo): SolicitudRespaldo
    {
        if (! in_array($sol->estado, [SolicitudRespaldo::SOLICITADA, SolicitudRespaldo::AUTORIZADA], true) || ! trim($motivo)) {
            throw new RuntimeException('Solo se cancela una solicitud abierta, con su motivo.');
        }
        $sol->update(['estado' => SolicitudRespaldo::CANCELADA, 'comentario_resolucion' => trim($motivo)]);

        return $sol->fresh();
    }

    /**
     * Entrega del 7z: solo quien lo pidió, una vez y dentro de la ventana.
     * Devuelve el flujo de la app; Kernia no lo guarda.
     *
     * @return array{flujo: StreamInterface, nombre: string, sha256: ?string}
     */
    public function abrirEntrega(SolicitudRespaldo $sol, LandlordAdmin $operador): array
    {
        if ($sol->tipo !== SolicitudRespaldo::ENTREGA_SOPORTE || $sol->estado !== SolicitudRespaldo::AUTORIZADA) {
            throw new RuntimeException('Esta entrega no está autorizada o ya se usó.');
        }
        if ($sol->solicitada_por !== $operador->id) {
            throw new RuntimeException('Solo quien pidió la entrega puede descargar el archivo.');
        }
        if (! $sol->vigente_hasta || $sol->vigente_hasta->isPast()) {
            throw new RuntimeException('Venció la ventana de 24 horas; solicita de nuevo.');
        }
        $exp = $sol->exportacion;
        $this->validarVigente($exp, $sol->tipo);

        $flujo = $this->app->archivoExportacion($exp->suscripcion, $exp->exportacion_app, $sol->id);
        // Un solo uso: se marca antes de transmitir, para que un segundo clic no lo vuelva a bajar.
        $sol->update(['estado' => SolicitudRespaldo::APLICADA, 'aplicada_en' => now()]);
        Auditoria::registrar('respaldo.entregado_a_soporte', null, $exp->suscripcion, null, [
            'solicitud_respaldo_id' => $sol->id, 'exportacion_id' => $exp->id, 'sha256' => $exp->sha256,
            'operador' => $operador->email, 'autorizado_por' => $sol->resuelta_por,
        ]);

        $s = $exp->suscripcion;
        $nombre = "kernia-export-{$s->producto->slug}-{$s->cliente->slug}-{$exp->alcance}-".$exp->created_at->format('Ymd').'.7z';

        return ['flujo' => $flujo, 'nombre' => $nombre, 'sha256' => $exp->sha256];
    }

    private function reenviarClave(SolicitudRespaldo $sol, LandlordAdmin $autorizador): SolicitudRespaldo
    {
        $exp = $sol->exportacion;
        $s = $exp->suscripcion;
        $clave = $this->boveda->leer($exp->claveBoveda(), "Reenvío autorizado de la contraseña (solicitud {$sol->id})", $sol->solicitada_por, $autorizador->id);
        if ($clave === null) {
            throw new RuntimeException('La contraseña ya no está en la bóveda (terminó la retención).');
        }
        $r = $this->correo->enviar(new ClaveRespaldoMail($s->cliente->nombre, $s->producto->nombre, $clave, $exp->disponible_hasta->format('d/m/Y')),
            $s->admin_email, ['cliente_id' => $s->cliente_id, 'suscripcion_id' => $s->id, 'referencia' => "solicitud_respaldo:{$sol->id}"]);
        unset($clave);
        if ($r->estado !== CorreoEnviado::ENVIADO) {
            // Queda autorizada pero sin aplicar: el operador puede reintentarla cuando el correo esté disponible.
            $sol->update(['estado' => SolicitudRespaldo::AUTORIZADA]);
            throw new RuntimeException('El correo de Kernia no está disponible; la solicitud quedó autorizada y puede reintentarse.');
        }
        $sol->update(['estado' => SolicitudRespaldo::APLICADA, 'aplicada_en' => now()]);
        Auditoria::registrar('respaldo.clave_reenviada', null, $s, null, [
            'solicitud_respaldo_id' => $sol->id, 'exportacion_id' => $exp->id, 'destinatario' => $s->admin_email, 'autorizado_por' => $autorizador->id,
        ]);

        return $sol->fresh();
    }

    /** Un reenvío autorizado que no salió por el correo se reintenta sin volver a pedir autorización. */
    public function reintentarReenvio(SolicitudRespaldo $sol): SolicitudRespaldo
    {
        if ($sol->tipo !== SolicitudRespaldo::REENVIO_CLAVE || $sol->estado !== SolicitudRespaldo::AUTORIZADA) {
            throw new RuntimeException('Solo se reintenta un reenvío autorizado que no salió.');
        }

        return $this->reenviarClave($sol, LandlordAdmin::findOrFail($sol->resuelta_por));
    }

    private function validarVigente(Exportacion $exp, string $tipo): void
    {
        if ($exp->estado !== Exportacion::READY || $exp->eliminacion !== null) {
            throw new RuntimeException('El respaldo no está disponible (no está listo o ya se eliminó).');
        }
        if ($tipo === SolicitudRespaldo::REENVIO_CLAVE && ! $this->boveda->existe($exp->claveBoveda())) {
            throw new RuntimeException('La contraseña ya no está en la bóveda.');
        }
    }
}
