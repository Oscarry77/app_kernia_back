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
    public const EN_EJECUCION = 'en_ejecucion'; // 09-oct-2026: baja v2.2 en curso (aviso, mantenimiento, ajuste)
    public const APLICADA = 'aplicada';
    public const RECHAZADA = 'rechazada';
    public const CANCELADA = 'cancelada';
    public const FALLIDA = 'fallida';

    /** Estados que impiden abrir otra solicitud para la misma suscripción. */
    public const ABIERTAS = [self::SOLICITADA, self::PROGRAMADA, self::EN_EJECUCION];

    // Fases de una baja v2.2 en ejecución (09-oct-2026).
    public const FASE_AVISO = 'aviso';
    public const FASE_MANTENIMIENTO = 'mantenimiento';
    public const FASE_AJUSTANDO = 'ajustando';

    public const SUBIDA = 'subida';
    public const BAJADA = 'bajada';

    public const INMEDIATA = 'inmediata';
    public const RENOVACION = 'renovacion';

    protected $table = 'solicitudes_plan';

    protected $fillable = [
        'suscripcion_id', 'plan_actual', 'plan_nuevo', 'direccion', 'aplicacion', 'fecha_efectiva', 'motivo', 'estado',
        'solicitada_por', 'resuelta_por', 'nivel_autorizacion', 'comentario_resolucion', 'resuelta_en', 'aplicada_en', 'error',
        'empresas_conservar', 'fase', 'fase_desde', 'alerta_demora', 'respaldo_id', 'empresas_bloqueadas',
    ];

    protected $casts = [
        'fecha_efectiva' => 'date',
        'resuelta_en' => 'datetime',
        'aplicada_en' => 'datetime',
        'empresas_conservar' => 'array',
        'fase_desde' => 'datetime',
        'alerta_demora' => 'boolean',
        'empresas_bloqueadas' => 'array',
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
