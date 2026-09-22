<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Workspace contratante (un cliente puede tener varias suscripciones, una
 * por producto/APP). Ver GUIA_INTEGRACION_APP_KERNIA_v1.md §1 y §2.
 */
class Cliente extends Model
{
    public const ESTATUS_ACTIVO = 'activo';
    public const ESTATUS_SUSPENDIDO = 'suspendido';
    public const ESTATUS_BAJA = 'baja';

    protected $fillable = [
        'slug',
        'nombre',
        'rfc',
        'estatus',
        'notas',
    ];

    public function suscripciones(): HasMany
    {
        return $this->hasMany(Suscripcion::class);
    }

    public function estaActivo(): bool
    {
        return $this->estatus === self::ESTATUS_ACTIVO;
    }
}
