<?php
namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Auditoria extends Model
{
    protected $table = 'auditoria';

    public const UPDATED_AT = null;

    protected $fillable = ['usuario_id', 'accion', 'cliente_id', 'suscripcion_id', 'antes', 'despues', 'ip'];

    protected $casts = [
        'antes' => 'array',
        'despues' => 'array',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(LandlordAdmin::class, 'usuario_id');
    }

    /** Registra una acción del operador autenticado. Nunca pases contraseñas en antes/después. */
    public static function registrar(string $accion, ?Cliente $cliente = null, ?Suscripcion $suscripcion = null, ?array $antes = null, ?array $despues = null): self
    {
        return self::create([
            'usuario_id' => auth('api')->id(),
            'accion' => $accion,
            'cliente_id' => $cliente?->id ?? $suscripcion?->cliente_id,
            'suscripcion_id' => $suscripcion?->id,
            'antes' => $antes,
            'despues' => $despues,
            'ip' => request()?->ip(),
        ]);
    }
}
