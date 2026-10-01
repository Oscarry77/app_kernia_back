<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Movimiento (+n / -n) de un extra contratado. El total es la suma. */
class SuscripcionExtra extends Model
{
    protected $table = 'suscripcion_extras';

    protected $fillable = ['suscripcion_id', 'producto_extra_id', 'cantidad', 'motivo', 'registrado_por'];

    protected $casts = ['cantidad' => 'integer'];

    public function suscripcion(): BelongsTo
    {
        return $this->belongsTo(Suscripcion::class);
    }

    public function extra(): BelongsTo
    {
        return $this->belongsTo(ProductoExtra::class, 'producto_extra_id');
    }
}
