<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Módulo contratable declarado por un producto (p. ej. Comercializa: ventas,
 * compras, inventarios, tesoreria, viaticos). Ver guía §3.1.
 */
class ProductoModulo extends Model
{
    protected $fillable = [
        'producto_id',
        'clave',
        'nombre',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
