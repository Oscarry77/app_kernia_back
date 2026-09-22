<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Qué módulos de su producto tiene activos una suscripción. Ver guía §3.1.
 */
class SuscripcionModulo extends Model
{
    protected $fillable = [
        'suscripcion_id',
        'modulo_clave',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function suscripcion(): BelongsTo
    {
        return $this->belongsTo(Suscripcion::class);
    }
}
