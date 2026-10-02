<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Autorización provisional de acceso de hasta 6 días (fase 3). */
class Prorroga extends Model
{
    public const SOLICITADA = 'solicitada';
    public const AUTORIZADA = 'autorizada';
    public const RECHAZADA = 'rechazada';
    public const VENCIDA = 'vencida';
    public const CERRADA_POR_PAGO = 'cerrada_por_pago';

    protected $table = 'prorrogas';

    protected $fillable = [
        'suscripcion_id', 'fecha_vencimiento', 'dias', 'motivo', 'detalle', 'estado', 'desde', 'hasta',
        'solicitada_por', 'resuelta_por', 'nivel_autorizacion', 'comentario_resolucion', 'resuelta_en',
    ];

    protected $casts = [
        'fecha_vencimiento' => 'date',
        'desde' => 'date',
        'hasta' => 'date',
        'resuelta_en' => 'datetime',
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
        return $this->hasMany(ProrrogaIntento::class);
    }
}
