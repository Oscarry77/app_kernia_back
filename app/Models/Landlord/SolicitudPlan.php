<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Solicitud de cambio de plan con autorización del escalafón (05-oct-2026). */
class SolicitudPlan extends Model
{
    public const SOLICITADA = 'solicitada';
    public const PROGRAMADA = 'programada';   // autorizada; se aplica en la fecha efectiva
    public const APLICADA = 'aplicada';
    public const RECHAZADA = 'rechazada';
    public const CANCELADA = 'cancelada';
    public const FALLIDA = 'fallida';

    /** Estados que impiden abrir otra solicitud para la misma suscripción. */
    public const ABIERTAS = [self::SOLICITADA, self::PROGRAMADA];

    public const SUBIDA = 'subida';
    public const BAJADA = 'bajada';

    public const INMEDIATA = 'inmediata';
    public const RENOVACION = 'renovacion';

    protected $table = 'solicitudes_plan';

    protected $fillable = [
        'suscripcion_id', 'plan_actual', 'plan_nuevo', 'direccion', 'aplicacion', 'fecha_efectiva', 'motivo', 'estado',
        'solicitada_por', 'resuelta_por', 'nivel_autorizacion', 'comentario_resolucion', 'resuelta_en', 'aplicada_en', 'error',
    ];

    protected $casts = [
        'fecha_efectiva' => 'date',
        'resuelta_en' => 'datetime',
        'aplicada_en' => 'datetime',
    ];

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

    public function intentos(): HasMany
    {
        return $this->hasMany(SolicitudPlanIntento::class);
    }
}
