<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Respaldo cifrado de la base de un cliente del patrón A, hecho por Kernia (09-oct-2026). */
class RespaldoBase extends Model
{
    use HasUuids;

    public const LISTO = 'listo';
    public const FALLIDO = 'fallido';
    public const ELIMINADO = 'eliminado';

    protected $table = 'respaldos_base';

    protected $fillable = ['suscripcion_id', 'solicitud_plan_id', 'motivo', 'base', 'ruta', 'tamano_bytes', 'sha256', 'estado', 'error', 'expira_en'];

    protected $casts = ['expira_en' => 'date'];

    public function suscripcion(): BelongsTo
    {
        return $this->belongsTo(Suscripcion::class);
    }

    public function claveBoveda(): string
    {
        return "respaldo_base.{$this->id}";
    }
}
