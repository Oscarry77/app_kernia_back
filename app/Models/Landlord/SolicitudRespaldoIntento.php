<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;

/** Intento de autorizar o rechazar una solicitud sobre un respaldo (también los fallidos). */
class SolicitudRespaldoIntento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'solicitud_respaldo_intentos';

    protected $fillable = ['solicitud_respaldo_id', 'sesion_usuario_id', 'email_tecleado', 'accion', 'resultado', 'ip'];
}
