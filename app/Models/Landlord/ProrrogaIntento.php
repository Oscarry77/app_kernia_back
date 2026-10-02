<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cada intento de autorizar o rechazar una prórroga, incluidos los fallidos (fase 3). */
class ProrrogaIntento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'prorroga_intentos';

    protected $fillable = ['prorroga_id', 'sesion_usuario_id', 'email_tecleado', 'accion', 'resultado', 'ip'];

    public function prorroga(): BelongsTo
    {
        return $this->belongsTo(Prorroga::class);
    }

    public function operador(): BelongsTo
    {
        return $this->belongsTo(LandlordAdmin::class, 'sesion_usuario_id');
    }
}
