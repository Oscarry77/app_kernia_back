<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ingreso registrado por un operador y el periodo que cubre (fase 2). */
class Pago extends Model
{
    protected $table = 'pagos';

    protected $fillable = [
        'suscripcion_id', 'fecha_pago', 'periodo_desde', 'periodo_hasta', 'modalidad',
        'monto', 'moneda', 'referencia', 'notas', 'registrado_por',
    ];

    protected $casts = [
        'fecha_pago' => 'date',
        'periodo_desde' => 'date',
        'periodo_hasta' => 'date',
        'monto' => 'decimal:2',
    ];

    public function suscripcion(): BelongsTo
    {
        return $this->belongsTo(Suscripcion::class);
    }

    public function operador(): BelongsTo
    {
        return $this->belongsTo(LandlordAdmin::class, 'registrado_por');
    }
}
