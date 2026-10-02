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
        'rol',
        'puesto',
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

    /** (02-oct-2026) Fase 3: permisos del rol (config/kernia_acl.php); el superadmin tiene todos. */
    public function permisos(): array
    {
        return config("kernia_acl.roles.{$this->rol}.permisos", []);
    }

    public function puede(string $permiso): bool
    {
        $permisos = $this->permisos();

        return in_array('*', $permisos, true) || in_array($permiso, $permisos, true);
    }

    public function esSuperadmin(): bool
    {
        return $this->rol === 'superadmin';
    }

    /** Vendedor: solo ve su cartera; los demás roles ven a todos los clientes. */
    public function tieneCartera(): bool
    {
        return in_array($this->rol, config('kernia_acl.roles_con_cartera', []), true);
    }

    public function cartera(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Cliente::class, 'cliente_usuario', 'usuario_id', 'cliente_id')->withTimestamps();
    }

    public function nivelAutorizacion(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(NivelAutorizacion::class, 'usuario_id');
    }

    public function puedeVerCliente(Cliente $cliente): bool
    {
        return ! $this->tieneCartera() || $this->cartera()->whereKey($cliente->id)->exists();
    }

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
