<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Edición de un producto: módulos incluidos + límites base (estándar v2.1). */
class ProductoPlan extends Model
{
    protected $table = 'producto_planes';

    protected $fillable = ['producto_id', 'codigo', 'nombre', 'descripcion', 'modulos', 'limites', 'orden', 'activo'];

    protected $casts = [
        'modulos' => 'array',
        'limites' => 'array',
        'activo' => 'boolean',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
