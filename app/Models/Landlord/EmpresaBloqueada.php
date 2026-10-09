<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Empresa bloqueada por plan y su cuenta regresiva de 30 días para archivarse (09-oct-2026). */
class EmpresaBloqueada extends Model
{
    public const BLOQUEADA = 'bloqueada';
    public const DESBLOQUEADA = 'desbloqueada';
    public const ARCHIVANDO = 'archivando';
    public const ESCALADA = 'escalada';   // bloqueo total sin lista: no se archiva solo

    protected $table = 'empresas_bloqueadas';

    protected $fillable = [
        'suscripcion_id', 'solicitud_plan_id', 'empresa_id', 'nombre', 'rfc', 'sin_seleccion', 'bloqueada_en', 'archivar_el',
        'estado', 'aviso_enviado_en', 'solicitud_salida_id', 'nota',
    ];

    protected $casts = [
        'sin_seleccion' => 'boolean',
        'bloqueada_en' => 'date',
        'archivar_el' => 'date',
        'aviso_enviado_en' => 'datetime',
    ];

    public function suscripcion(): BelongsTo
    {
        return $this->belongsTo(Suscripcion::class);
    }
}
