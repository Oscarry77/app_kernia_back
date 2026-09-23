<?php
namespace App\Services\Landlord;

use App\Models\Landlord\Suscripcion;
use Throwable;

/**
 * Cambia el estatus local de una suscripción (fuente de verdad, siempre se
 * aplica) y empuja la notificación a la app (guía §4.2/§5) para revocación
 * inmediata. Si el push falla, el estatus local YA quedó cambiado -- la app
 * lo va a ver de todas formas en su siguiente `resolve` (caché de 60 s), el
 * push solo evita esperar esa ventana.
 */
class SuscripcionEstatusService
{
    public function __construct(private readonly ProductoAppClient $appClient)
    {
    }

    /** @return bool true si el push a la app tuvo éxito (el cambio local siempre se aplica) */
    public function cambiarEstatus(Suscripcion $suscripcion, string $estatus, ?string $motivo = null): bool
    {
        $suscripcion->update(['estatus' => $estatus]);

        try {
            $this->appClient->notificarEstatus($suscripcion, $estatus, $motivo);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
