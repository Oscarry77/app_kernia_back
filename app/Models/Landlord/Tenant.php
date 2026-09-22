<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro central de clientes SaaS. Movido de bridge-com-api a kernia-api el
 * 13-sep-2026 (extracción de Kernia como servicio landlord independiente).
 * Vive en la conexión 'mysql' (default de este proyecto, físicamente la
 * misma BD kernia_landlord que antes usaba bridge-com-api como 'landlord').
 */
class Tenant extends Model
{
    public const ESTATUS_ACTIVO = 'activo';
    public const ESTATUS_SUSPENDIDO = 'suspendido';
    public const ESTATUS_EN_APROVISIONAMIENTO = 'en_aprovisionamiento';

    protected $fillable = [
        'nombre_cliente',
        'slug',
        'db_host',
        'db_port',
        'db_driver',
        'db_database',
        'db_username',
        'db_password',
        'db_bi_username',
        'db_bi_password',
        'estatus',
        'plan',
        'notas',
    ];

    protected $hidden = [
        'db_password',
        'db_bi_password',
    ];

    protected $casts = [
        'db_password' => 'encrypted',
        'db_bi_password' => 'encrypted',
    ];

    public function tieneAccesoBiConfigurado(): bool
    {
        return filled($this->db_bi_username) && filled($this->db_bi_password);
    }

    public function estaActivo(): bool
    {
        return $this->estatus === self::ESTATUS_ACTIVO;
    }

    /**
     * Credenciales de conexión que espera el contrato HTTP interno de
     * bridge-com-api (TenantConnectionResolver::activarDesdeArray) en cada
     * llamada -- ese servicio no tiene acceso local a esta tabla.
     */
    public function datosConexion(): array
    {
        return [
            'db_driver'   => $this->db_driver,
            'db_host'     => $this->db_host,
            'db_port'     => $this->db_port,
            'db_database' => $this->db_database,
            'db_username' => $this->db_username,
            'db_password' => $this->db_password,
        ];
    }
}
