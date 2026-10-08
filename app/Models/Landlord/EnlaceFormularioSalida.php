<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Enlace de un solo uso para que el cliente conteste el formulario de salida (08-oct-2026). */
class EnlaceFormularioSalida extends Model
{
    protected $table = 'enlaces_formulario_salida';

    protected $fillable = ['cliente_id', 'suscripcion_id', 'evento', 'token_hash', 'expira_en', 'usado_en', 'formulario_salida_id', 'creado_por'];

    protected $casts = [
        'expira_en' => 'datetime',
        'usado_en' => 'datetime',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function suscripcion(): BelongsTo
    {
        return $this->belongsTo(Suscripcion::class);
    }

    public function vigente(): bool
    {
        return $this->usado_en === null && $this->expira_en->isFuture();
    }
}
