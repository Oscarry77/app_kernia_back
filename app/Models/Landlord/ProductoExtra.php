<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Capacidad que se vende aparte y suma a un límite (estándar v2.1). */
class ProductoExtra extends Model
{
    protected $table = 'producto_extras';

    protected $fillable = ['producto_id', 'codigo', 'nombre', 'limite', 'incremento', 'activo'];

    protected $casts = [
        'incremento' => 'integer',
        'activo' => 'boolean',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
