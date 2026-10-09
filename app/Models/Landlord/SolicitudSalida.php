<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Solicitud de salida de una suscripción con autorización del escalafón
 * (08-oct-2026): retirar la app, reactivarla o finiquitar. Reglas en SalidaService.
 */
class SolicitudSalida extends Model
{
    public const RETIRO = 'retiro';
    public const REACTIVACION = 'reactivacion';
    public const FINIQUITO = 'finiquito';

    public const TIPOS = [self::RETIRO, self::REACTIVACION, self::FINIQUITO];

    /** 09-oct-2026: archivar UNA empresa (v2.3 caso A). No cambia el estatus de la suscripción. */
    public const ARCHIVO = 'archivo';
    public const TIPOS_SOLICITABLES = [...self::TIPOS, self::ARCHIVO];

    public const SOLICITADA = 'solicitada';
    public const PROGRAMADA = 'programada';   // autorizada; se aplica en el corte de la fecha efectiva
    public const APLICADA = 'aplicada';
    public const RECHAZADA = 'rechazada';
    public const CANCELADA = 'cancelada';
    public const FALLIDA = 'fallida';

    /** Estados que impiden abrir otra solicitud de salida para la misma suscripción. */
    public const ABIERTAS = [self::SOLICITADA, self::PROGRAMADA];

    public const CONFORMIDAD_CORREO = 'correo';
    public const CONFORMIDAD_DOCUMENTO = 'documento';

    protected $table = 'solicitudes_salida';

    protected $fillable = [
        'suscripcion_id', 'tipo', 'empresa_id', 'empresa_nombre', 'empresa_rfc', 'estatus_anterior', 'motivo', 'conformidad_tipo', 'conformidad_referencia', 'estado', 'fecha_efectiva',
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
        return $this->hasMany(SolicitudSalidaIntento::class);
    }
}
