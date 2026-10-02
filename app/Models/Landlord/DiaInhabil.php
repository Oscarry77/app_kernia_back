<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;

/** Calendario de días inhábiles para contar días hábiles (fase 2). */
class DiaInhabil extends Model
{
    protected $table = 'dias_inhabiles';

    protected $fillable = ['fecha', 'descripcion'];

    protected $casts = ['fecha' => 'date'];
}
