<?php
namespace App\Services\Correo;

use App\Mail\AvisoCambioPlanMail;
use App\Models\Landlord\CorreoEnviado;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\SolicitudPlan;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\CambioPlanService;

/**
 * Avisos por correo a los clientes (07-oct-2026). Decisión del dueño
 * (21-sep y 05-oct): van al cliente, a su asesor y a Dirección.
 *
 *  - Cliente: el administrador del workspace en esa app (`admin_email`).
 *  - Asesor: los operadores que tienen al cliente en su cartera.
 *  - Dirección: los operadores activos con rol `direccion`.
 *
 * Los clientes de demo, capacitación y prueba no reciben avisos.
 */
class AvisosCliente
{
    public function __construct(
        private readonly CentroCorreo $correo,
        private readonly CambioPlanService $cambios,
    ) {
    }

    /** @return list<CorreoEnviado> */
    public function cambioPlanProgramado(SolicitudPlan $sol): array
    {
        $s = $sol->suscripcion->loadMissing(['cliente.operadores', 'producto']);
        if (! $s->cliente->esComercial()) {
            return [];
        }

        $vista = $this->cambios->previsualizar($s, $sol->plan_nuevo);
        $nombreModulo = fn (string $clave) => $s->producto->modulos()->where('clave', $clave)->value('nombre') ?? $clave;
        $etiquetas = ['max_empresas' => 'Empresas', 'max_empleados' => 'Empleados', 'max_usuarios' => 'Usuarios'];
        $limites = [];
        foreach ($vista['limites_despues'] as $clave => $despues) {
            $antes = $vista['limites_antes'][$clave] ?? null;
            if ($antes !== $despues) {
                $limites[] = ['nombre' => $etiquetas[$clave] ?? $clave, 'antes' => $antes ?? 'sin límite', 'despues' => $despues ?? 'sin límite'];
            }
        }

        $mensaje = new AvisoCambioPlanMail(
            $s->cliente->nombre, $s->producto->nombre,
            $vista['plan_actual_nombre'] ?? 'sin plan', $vista['plan_nuevo_nombre'],
            $sol->fecha_efectiva->format('d/m/Y'),
            array_map($nombreModulo, $vista['modulos_pierde']), $limites,
            $this->asesor($s),
        );
        $contexto = ['cliente_id' => $s->cliente_id, 'suscripcion_id' => $s->id, 'referencia' => "solicitud_plan:{$sol->id}"];

        return $this->correo->enviarA($mensaje, [$s->admin_email, ...$this->copias($s)], $contexto);
    }

    /** Asesor que firma el aviso: el primero de la cartera del cliente. */
    public function asesor(Suscripcion $s): ?array
    {
        $o = $s->cliente->operadores()->where('activo', true)->orderBy('cliente_usuario.id')->first();

        return $o ? ['nombre' => $o->nombre, 'email' => $o->email] : null;
    }

    /** @return list<string> asesores de la cartera y Dirección */
    private function copias(Suscripcion $s): array
    {
        return [
            ...$s->cliente->operadores()->where('activo', true)->pluck('email')->all(),
            ...LandlordAdmin::where('rol', 'direccion')->where('activo', true)->pluck('email')->all(),
        ];
    }
}
