<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solicitud de soporte sobre un respaldo, autorizada por el escalafón
 * (09-oct-2026; estándar v2.3 §4.3 y §7): reenviar la contraseña al
 * administrador del cliente o entregar el 7z cifrado a soporte.
 */
class SolicitudRespaldo extends Model
{
    public const REENVIO_CLAVE = 'reenvio_clave';
    public const ENTREGA_SOPORTE = 'entrega_soporte';
    public const TIPOS = [self::REENVIO_CLAVE, self::ENTREGA_SOPORTE];

    public const SOLICITADA = 'solicitada';
    public const AUTORIZADA = 'autorizada';  // entrega: lista para que quien la pidió descargue
    public const APLICADA = 'aplicada';
    public const RECHAZADA = 'rechazada';
    public const CANCELADA = 'cancelada';

    protected $table = 'solicitudes_respaldo';

    protected $fillable = [
        'exportacion_id', 'suscripcion_id', 'tipo', 'motivo', 'estado', 'solicitada_por', 'resuelta_por', 'nivel_autorizacion',
        'comentario_resolucion', 'resuelta_en', 'aplicada_en', 'vigente_hasta',
    ];

    protected $casts = [
        'resuelta_en' => 'datetime',
        'aplicada_en' => 'datetime',
        'vigente_hasta' => 'datetime',
    ];

    public function exportacion(): BelongsTo
    {
        return $this->belongsTo(Exportacion::class);
    }

    public function suscripcion(): BelongsTo
    {
        return $this->belongsTo(Suscripcion::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(LandlordAdmin::class, 'solicitada_por');
    }

    public function resolutor(): BelongsTo
    {
        return $this->belongsTo(LandlordAdmin::class, 'resuelta_por');
    }
}
