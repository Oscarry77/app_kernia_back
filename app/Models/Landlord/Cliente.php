<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Workspace contratante (un cliente puede tener varias suscripciones, una
 * por producto/APP). Ver GUIA_INTEGRACION_APP_KERNIA_v1.md §1 y §2.
 *
 * 02-oct-2026: datos fiscales según la Constancia de Situación Fiscal, con
 * tipo de persona. `estatus` es el de Kernia (acceso); `estatus_padron` es el
 * del SAT, informativo. `nombre` es el nombre para mostrar y se arma con
 * `nombreParaMostrar()` al guardar.
 */
class Cliente extends Model
{
    public const ESTATUS_ACTIVO = 'activo';
    public const ESTATUS_SUSPENDIDO = 'suspendido';
    public const ESTATUS_BAJA = 'baja';

    public const PERSONA_MORAL = 'moral';
    public const PERSONA_FISICA = 'fisica';

    /** Campos de la Constancia de Situación Fiscal que captura el panel. */
    public const CAMPOS_FISCALES = [
        'tipo_persona', 'rfc', 'razon_social', 'regimen_capital', 'nombre_comercial',
        'curp', 'nombres', 'primer_apellido', 'segundo_apellido',
        'fecha_inicio_operaciones', 'estatus_padron', 'regimen_fiscal',
        'codigo_postal', 'tipo_vialidad', 'nombre_vialidad', 'numero_exterior', 'numero_interior',
        'colonia', 'localidad', 'municipio', 'entidad_federativa', 'entre_calle', 'y_calle',
        'correo', 'telefono_lada', 'telefono_numero',
    ];

    protected $fillable = [
        'slug',
        'nombre',
        'estatus',
        'notas',
        ...self::CAMPOS_FISCALES,
    ];

    protected $casts = [
        'fecha_inicio_operaciones' => 'date',
    ];

    public function suscripciones(): HasMany
    {
        return $this->hasMany(Suscripcion::class);
    }

    public function estaActivo(): bool
    {
        return $this->estatus === self::ESTATUS_ACTIVO;
    }

    /**
     * Moral: "Razón social, SA de CV" (CFDI 4.0 separa el régimen de capital).
     * Física: "Nombre(s) Primer apellido Segundo apellido".
     */
    public function nombreParaMostrar(): string
    {
        if ($this->tipo_persona === self::PERSONA_MORAL && $this->razon_social) {
            return $this->regimen_capital ? "{$this->razon_social}, {$this->regimen_capital}" : $this->razon_social;
        }

        if ($this->tipo_persona === self::PERSONA_FISICA && $this->nombres) {
            return trim("{$this->nombres} {$this->primer_apellido} {$this->segundo_apellido}");
        }

        return (string) $this->nombre;
    }

    /** Los datos mínimos de la Constancia están capturados. */
    public function datosFiscalesCompletos(): bool
    {
        $base = $this->tipo_persona && $this->rfc && $this->regimen_fiscal && $this->codigo_postal
            && $this->entidad_federativa && $this->correo;

        return $base && ($this->tipo_persona === self::PERSONA_MORAL
            ? (bool) $this->razon_social
            : $this->nombres && $this->primer_apellido && $this->curp);
    }
}
