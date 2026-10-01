<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo de APPs que Kernia puede rentar (comercializa, hrm, svi,
 * tesoreria). Ver GUIA_INTEGRACION_APP_KERNIA_v1.md §2 y §3.1.
 */
class Producto extends Model
{
    public const MODO_DEDICADA = 'dedicada';
    public const MODO_COMPARTIDA = 'compartida';

    protected $fillable = [
        'slug',
        'nombre',
        'base_url_interna',
        'modo_datos',
        'token_interno',
        'prefijo_db',
    ];

    protected $hidden = [
        'token_interno',
    ];

    protected $casts = [
        'token_interno' => 'encrypted',
    ];

    public function modulos(): HasMany
    {
        return $this->hasMany(ProductoModulo::class);
    }

    public function suscripciones(): HasMany
    {
        return $this->hasMany(Suscripcion::class);
    }

    public function esDedicada(): bool
    {
        return $this->modo_datos === self::MODO_DEDICADA;
    }

    public function usaModulos(): bool
    {
        return $this->modulos()->exists();
    }

    public function planes(): HasMany
    {
        return $this->hasMany(ProductoPlan::class);
    }

    public function extras(): HasMany
    {
        return $this->hasMany(ProductoExtra::class);
    }

    /** Con catálogo de planes, el plan es obligatorio y dicta módulos y límites (estándar v2.1). */
    public function usaPlanes(): bool
    {
        return $this->planes()->exists();
    }

    public function plan(?string $codigo): ?ProductoPlan
    {
        return $codigo === null ? null : $this->planes()->where('codigo', $codigo)->where('activo', true)->first();
    }
}
