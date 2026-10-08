<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;

/** Intento de autorizar o rechazar una solicitud de salida (también los fallidos). */
class SolicitudSalidaIntento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'solicitud_salida_intentos';

    protected $fillable = ['solicitud_salida_id', 'sesion_usuario_id', 'email_tecleado', 'accion', 'resultado', 'ip'];
}
