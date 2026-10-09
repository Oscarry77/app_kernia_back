<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exportación v2.3 pedida por Kernia a una app (09-oct-2026). Solo metadatos:
 * el 7z vive en la app y su contraseña en la bóveda (`respaldo.{id}`).
 */
class Exportacion extends Model
{
    public const PENDIENTE = 'pendiente';   // aún no la acepta la app (se reintenta)
    public const PROCESSING = 'processing';
    public const READY = 'ready';
    public const FAILED = 'failed';         // agotó los intentos

    public const MOTIVO_FINIQUITO = 'finiquito';
    public const MOTIVO_COPIA = 'copia';
    public const MOTIVO_ARCHIVO = 'archivo';

    protected $table = 'exportaciones';

    protected $fillable = [
        'suscripcion_id', 'solicitud_salida_id', 'motivo', 'alcance', 'empresas', 'intento', 'exportacion_app', 'estado',
        'tamano_bytes', 'sha256', 'conteos', 'disponible_hasta', 'descargada_en', 'retencion_hasta',
        'carta_enviada_en', 'clave_enviada_en', 'recordatorios', 'error', 'revisada_en', 'eliminacion', 'eliminada_en', 'archivo', 'archivada_en',
    ];

    // Archivo de empresa (v2.3 §4.5).
    public const ARCH_ARCHIVANDO = 'archivando';
    public const ARCH_ARCHIVADA = 'archivada';

    // Pasos de la eliminación al terminar la retención (09-oct-2026, v2.3 §4.4 y §4.6).
    public const ELIM_EXPORTACION_BORRADA = 'exportacion_borrada';
    public const ELIM_APP_ELIMINANDO = 'app_eliminando';
    public const ELIM_BASE_BORRADA = 'base_borrada';
    public const ELIM_COMPLETA = 'completa';

    protected $casts = [
        'empresas' => 'array',
        'conteos' => 'array',
        'recordatorios' => 'array',
        'disponible_hasta' => 'date',
        'retencion_hasta' => 'date',
        'descargada_en' => 'datetime',
        'carta_enviada_en' => 'datetime',
        'clave_enviada_en' => 'datetime',
        'revisada_en' => 'datetime',
        'eliminada_en' => 'datetime',
        'archivada_en' => 'datetime',
    ];

    public function suscripcion(): BelongsTo
    {
        return $this->belongsTo(Suscripcion::class);
    }

    /** Clave de la bóveda donde vive la contraseña del 7z. */
    public function claveBoveda(): string
    {
        return "respaldo.{$this->id}";
    }

    /** Idempotency-Key del intento vigente (v2.3 §4.2). */
    public function idempotencyKey(): string
    {
        return "exportacion-{$this->id}-{$this->intento}";
    }
}
