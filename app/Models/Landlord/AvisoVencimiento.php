<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;

/** Aviso de vencimiento entregado por correo: uno por suscripción, ciclo e hito (09-oct-2026). */
class AvisoVencimiento extends Model
{
    public const HITO_SUSPENDIDA = 'suspendida';

    protected $table = 'avisos_vencimiento';

    protected $fillable = ['suscripcion_id', 'fecha_proximo_pago', 'hito', 'enviado_en'];

    protected $casts = [
        'fecha_proximo_pago' => 'date',
        'enviado_en' => 'datetime',
    ];
}
