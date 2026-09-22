<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliente x Producto. Ver GUIA_INTEGRACION_APP_KERNIA_v1.md §2 y §5.
 */
class Suscripcion extends Model
{
    public const ESTATUS_EN_APROVISIONAMIENTO = 'en_aprovisionamiento';
    public const ESTATUS_ACTIVO = 'activo';
    public const ESTATUS_SUSPENDIDO = 'suspendido';
    public const ESTATUS_FALLIDO = 'fallido';
    public const ESTATUS_CANCELADO = 'cancelado';

    protected $fillable = [
        'cliente_id',
        'producto_id',
        'estatus',
        'plan',
        'ref_externa',
        'admin_email',
        'modalidad_pago',
        'fecha_contratacion',
        'fecha_proximo_pago',
        'dias_gracia',
        'suspension_automatica',
        'activa_hasta',
        'db_driver',
        'db_host',
        'db_port',
        'db_database',
        'db_username',
        'db_password',
        'db_bi_username',
        'db_bi_password',
        'provisionada_en',
    ];

    protected $hidden = [
        'db_password',
        'db_bi_password',
    ];

    protected $casts = [
        'fecha_contratacion' => 'date',
        'fecha_proximo_pago' => 'date',
        'activa_hasta' => 'date',
        'provisionada_en' => 'datetime',
        'suspension_automatica' => 'boolean',
        'db_password' => 'encrypted',
        'db_bi_password' => 'encrypted',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function modulos(): HasMany
    {
        return $this->hasMany(SuscripcionModulo::class);
    }

    /**
     * Estatus efectivo: suspendida si el cliente está suspendido, aunque la
     * suscripción esté `activo` (guía §2).
     */
    public function estaActivaEfectivo(): bool
    {
        return $this->estatus === self::ESTATUS_ACTIVO
            && $this->cliente?->estaActivo();
    }

    public function datosConexion(): ?array
    {
        if (! $this->db_database) {
            return null;
        }

        return [
            'db_driver'   => $this->db_driver,
            'db_host'     => $this->db_host,
            'db_port'     => $this->db_port,
            'db_database' => $this->db_database,
            'db_username' => $this->db_username,
            'db_password' => $this->db_password,
        ];
    }

    /**
     * Claves de módulo activas para el `resolve` (guía §3.1). Null si el
     * producto no declara módulos.
     */
    public function clavesModulosActivos(): ?array
    {
        if (! $this->producto?->usaModulos()) {
            return null;
        }

        return $this->modulos()->where('activo', true)->pluck('modulo_clave')->all();
    }
}
