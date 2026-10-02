<?php
namespace App\Services\Landlord;

use App\Models\Landlord\Suscripcion;
use Throwable;

/**
 * Cambia el estatus local de una suscripción (fuente de verdad, siempre se
 * aplica) y empuja la notificación a la app (estándar v2 §5.2) para
 * revocación inmediata.
 *
 * 01-oct-2026: si el push falla, el aviso queda PENDIENTE y
 * `reintentarPendientes()` lo repite con espera creciente. Antes se perdía:
 * las apps que consultan `resolve` lo veían al expirar su caché de 60 s,
 * pero HRM no consulta `resolve` y nunca se enteraba.
 */
class SuscripcionEstatusService
{
    /** Espera máxima entre reintentos, en minutos (1, 2, 4… hasta este tope). */
    private const BACKOFF_MAX_MIN = 30;

    public function __construct(private readonly ProductoAppClient $appClient)
    {
    }

    public const CAUSA_VENCIMIENTO = 'vencimiento';
    public const CAUSA_MANUAL = 'manual';

    /**
     * @param string|null $causa al suspender: 'vencimiento' o 'manual' (default).
     *                           Un pago solo levanta una suspensión por vencimiento (fase 2).
     * @return bool true si la app confirmó en este intento (el cambio local siempre se aplica)
     */
    public function cambiarEstatus(Suscripcion $suscripcion, string $estatus, ?string $motivo = null, ?string $causa = null): bool
    {
        $suscripcion->update([
            'estatus' => $estatus,
            'suspension_motivo' => $estatus === Suscripcion::ESTATUS_SUSPENDIDO ? ($causa ?? self::CAUSA_MANUAL) : null,
            'estatus_por_notificar' => $estatus,
            'estatus_notificacion_motivo' => $motivo,
            'estatus_notificacion_intentos' => 0,
            'estatus_notificacion_error' => null,
            'estatus_notificacion_ultimo_intento' => null,
        ]);

        return $this->notificar($suscripcion);
    }

    /**
     * Reintenta los avisos pendientes cuyo tiempo de espera ya venció.
     * Siempre manda el ÚLTIMO estatus pedido: si hubo suspensión y luego
     * reactivación sin confirmar, solo viaja la reactivación.
     *
     * @return array{confirmadas:int, fallidas:int}
     */
    public function reintentarPendientes(): array
    {
        $resultado = ['confirmadas' => 0, 'fallidas' => 0];

        $pendientes = Suscripcion::with(['cliente', 'producto'])->whereNotNull('estatus_por_notificar')->get();

        foreach ($pendientes as $suscripcion) {
            if (! $this->tocaReintentar($suscripcion)) {
                continue;
            }

            $this->notificar($suscripcion) ? $resultado['confirmadas']++ : $resultado['fallidas']++;
        }

        return $resultado;
    }

    private function notificar(Suscripcion $suscripcion): bool
    {
        try {
            $this->appClient->notificarEstatus(
                $suscripcion,
                $suscripcion->estatus_por_notificar,
                $suscripcion->estatus_notificacion_motivo,
            );
        } catch (Throwable $e) {
            $suscripcion->update([
                'estatus_notificacion_intentos' => $suscripcion->estatus_notificacion_intentos + 1,
                'estatus_notificacion_error' => mb_substr($e->getMessage(), 0, 500),
                'estatus_notificacion_ultimo_intento' => now(),
            ]);

            return false;
        }

        $suscripcion->update([
            'estatus_por_notificar' => null,
            'estatus_notificacion_motivo' => null,
            'estatus_notificacion_intentos' => 0,
            'estatus_notificacion_error' => null,
            'estatus_notificacion_ultimo_intento' => now(),
        ]);

        return true;
    }

    private function tocaReintentar(Suscripcion $suscripcion): bool
    {
        $ultimo = $suscripcion->estatus_notificacion_ultimo_intento;
        if (! $ultimo) {
            return true;
        }

        $esperaMin = min(2 ** max($suscripcion->estatus_notificacion_intentos - 1, 0), self::BACKOFF_MAX_MIN);

        return $ultimo->copy()->addMinutes($esperaMin)->lte(now());
    }
}
