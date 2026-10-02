<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Escalafón de autorización de prórrogas (fase 3), replicando el de OC de Comercializa. */
class NivelAutorizacion extends Model
{
    protected $table = 'niveles_autorizacion';

    protected $fillable = ['nivel', 'puesto', 'usuario_id', 'dias_max', 'activo'];

    protected $casts = ['activo' => 'boolean', 'nivel' => 'integer', 'dias_max' => 'integer'];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(LandlordAdmin::class, 'usuario_id');
    }
}
