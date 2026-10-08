<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Secreto de la bóveda (07-oct-2026). `valor` va cifrado con APP_KEY y está
 * oculto en toda serialización: solo BovedaService lo lee, para un proceso
 * del sistema, y deja constancia en boveda_accesos.
 */
class BovedaSecreto extends Model
{
    public const TIPO_CORREO = 'correo_smtp';
    public const TIPO_RESPALDO = 'clave_respaldo';

    protected $table = 'boveda_secretos';

    protected $fillable = [
        'clave', 'tipo', 'descripcion', 'resumen', 'valor', 'cliente_id', 'suscripcion_id',
        'expira_en', 'purgado_en', 'actualizado_por',
    ];

    protected $hidden = ['valor'];

    protected $casts = [
        'valor' => 'encrypted',
        'resumen' => 'array',
        'expira_en' => 'date',
        'purgado_en' => 'datetime',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function actualizador(): BelongsTo
    {
        return $this->belongsTo(LandlordAdmin::class, 'actualizado_por');
    }

    public function accesos(): HasMany
    {
        return $this->hasMany(BovedaAcceso::class, 'secreto_id');
    }

    public function vigente(): bool
    {
        return $this->purgado_en === null && $this->getRawOriginal('valor') !== null;
    }
}
