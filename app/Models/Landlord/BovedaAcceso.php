<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cada operación sobre un secreto de la bóveda (07-oct-2026). Nunca guarda el valor. */
class BovedaAcceso extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'boveda_accesos';

    protected $fillable = ['secreto_id', 'clave', 'accion', 'usuario_id', 'autorizado_por', 'motivo', 'ip'];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(LandlordAdmin::class, 'usuario_id');
    }
}
