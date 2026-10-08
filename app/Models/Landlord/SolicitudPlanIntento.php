<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;

/** Intento de autorizar o rechazar un cambio de plan (también los fallidos). */
class SolicitudPlanIntento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'solicitud_plan_intentos';

    protected $fillable = ['solicitud_plan_id', 'sesion_usuario_id', 'email_tecleado', 'accion', 'resultado', 'ip'];
}
