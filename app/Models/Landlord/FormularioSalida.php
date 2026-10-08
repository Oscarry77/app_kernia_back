<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Respuesta del formulario de salida (08-oct-2026): la registra el asesor al
 * solicitar una salida o una baja de plan, o la envía el cliente desde su enlace.
 */
class FormularioSalida extends Model
{
    public const ORIGEN_ASESOR = 'asesor';
    public const ORIGEN_CLIENTE = 'cliente';

    public const EVENTO_RETIRO = 'retiro';
    public const EVENTO_FINIQUITO = 'finiquito';
    public const EVENTO_BAJA_PLAN = 'baja_plan';
    public const EVENTO_ARCHIVO = 'archivo';   // archivar una empresa (v2.3, caso A); aún sin flujo en Kernia

    protected $table = 'formularios_salida';

    protected $fillable = [
        'cliente_id', 'suscripcion_id', 'origen', 'evento', 'solicitud_salida_id', 'solicitud_plan_id',
        'motivo', 'detalle', 'calificacion', 'mejora', 'recomendaria', 'registrado_por',
    ];

    protected $casts = [
        'calificacion' => 'integer',
        'recomendaria' => 'boolean',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function suscripcion(): BelongsTo
    {
        return $this->belongsTo(Suscripcion::class);
    }

    public function solicitudSalida(): BelongsTo
    {
        return $this->belongsTo(SolicitudSalida::class);
    }

    public function solicitudPlan(): BelongsTo
    {
        return $this->belongsTo(SolicitudPlan::class);
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(LandlordAdmin::class, 'registrado_por');
    }
}
