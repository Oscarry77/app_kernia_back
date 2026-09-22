<?php
namespace App\Models\Landlord;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class LandlordAdmin extends Authenticatable implements JWTSubject
{
    use Notifiable;

    protected $table = 'landlord_admins';

    protected $fillable = [
        'nombre',
        'email',
        'password',
        'activo',
        'ultimo_acceso',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password'      => 'hashed',
        'activo'        => 'boolean',
        'ultimo_acceso' => 'datetime',
    ];

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'nombre' => $this->nombre,
        ];
    }
}
