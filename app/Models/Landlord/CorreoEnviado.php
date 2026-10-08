<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro de un correo de Kernia (07-oct-2026). Solo metadatos; nunca el cuerpo. */
class CorreoEnviado extends Model
{
    public const UPDATED_AT = null;

    public const ENVIADO = 'enviado';
    public const FALLIDO = 'fallido';
    public const OMITIDO = 'omitido';   // el correo de Kernia no está disponible

    protected $table = 'correos_enviados';

    protected $fillable = [
        'plantilla', 'destinatario', 'asunto', 'estado', 'error', 'contiene_secreto',
        'cliente_id', 'suscripcion_id', 'operador_id', 'referencia',
    ];

    protected $casts = ['contiene_secreto' => 'boolean'];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function operador(): BelongsTo
    {
        return $this->belongsTo(LandlordAdmin::class, 'operador_id');
    }
}
