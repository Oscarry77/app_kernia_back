<?php
namespace App\Services\Correo;

use App\Mail\AvisoVencimientoMail;
use App\Models\Landlord\AvisoVencimiento;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\CorreoEnviado;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\SuscripcionEstatusService;
use App\Services\Landlord\VigenciaService;

/**
 * Avisos de vencimiento por correo (09-oct-2026, fase 4). Decisiones del dueño
 * (REGISTRO_DECISIONES_KERNIA.md §4): desde 30 días antes, por correo, al
 * cliente, a su asesor y a Dirección; WhatsApp diferido.
 *
 * Criterio del orquestador (corregible):
 *  - Hitos: 30, 15, 7, 3 y 1 días antes, el día de vencimiento (0) y la
 *    suspensión. Al entrar a cada tramo se manda una vez; si el servidor
 *    estuvo abajo, se manda solo el más urgente pendiente, nunca una ráfaga.
 *  - Cliente: el administrador de la app y el correo fiscal del cliente.
 *    Asesor: siempre en copia. Dirección: solo desde 3 días antes y la suspensión.
 *  - Un hito cuenta como entregado cuando el correo del cliente salió; si el
 *    correo de Kernia no está disponible, se reintenta en la siguiente corrida.
 *  - Solo clientes comerciales; nada durante una prórroga (ya tiene su aviso en la app).
 */
class AvisosVencimientoService
{
    public function __construct(
        private readonly CentroCorreo $correo,
        private readonly AvisosCliente $avisos,
        private readonly VigenciaService $vigencias,
    ) {
    }

    /** @return list<array{suscripcion: int, hito: string, entregado: bool}> */
    public function enviarPendientes(): array
    {
        $resultado = [];
        $comerciales = fn ($q) => $q->where('tipo', Cliente::TIPO_COMERCIAL);

        $activas = Suscripcion::with(['cliente.operadores', 'producto'])
            ->where('estatus', Suscripcion::ESTATUS_ACTIVO)->whereNotNull('fecha_proximo_pago')
            ->whereHas('cliente', $comerciales)->get();
        foreach ($activas as $s) {
            $dias = $this->vigencias->diasRestantes($s);
            if ($dias === null || $dias < 0 || $this->vigencias->enProrroga($s)) {
                continue;
            }
            $hito = $this->hitoPara($dias);
            if ($hito !== null && ! $this->enviado($s, (string) $hito) && ! $this->hayMasUrgente($s, $hito)) {
                $resultado[] = $this->enviar($s, (string) $hito, $dias);
            }
        }

        $suspendidas = Suscripcion::with(['cliente.operadores', 'producto'])
            ->where('estatus', Suscripcion::ESTATUS_SUSPENDIDO)
            ->where('suspension_motivo', SuscripcionEstatusService::CAUSA_VENCIMIENTO)
            ->whereNotNull('fecha_proximo_pago')->whereHas('cliente', $comerciales)->get();
        foreach ($suspendidas as $s) {
            if (! $this->enviado($s, AvisoVencimiento::HITO_SUSPENDIDA)) {
                $resultado[] = $this->enviar($s, AvisoVencimiento::HITO_SUSPENDIDA, (int) $this->vigencias->diasRestantes($s));
            }
        }

        return $resultado;
    }

    /** Último aviso entregado del ciclo actual (para el panel). */
    public function ultimo(Suscripcion $s): ?array
    {
        if (! $s->fecha_proximo_pago) {
            return null;
        }
        $a = AvisoVencimiento::where('suscripcion_id', $s->id)->whereDate('fecha_proximo_pago', $s->fecha_proximo_pago)
            ->latest('enviado_en')->latest('id')->first();

        return $a ? ['hito' => $a->hito, 'enviado_en' => $a->enviado_en->toIso8601String()] : null;
    }

    /** El tramo en el que está hoy: el hito más cercano que aún no pasa (30 ≥ … ≥ 0). */
    private function hitoPara(int $dias): ?int
    {
        $tramos = array_filter(config('kernia.avisos_vencimiento_dias'), fn ($h) => $h >= $dias);

        return $tramos ? min($tramos) : null;
    }

    private function enviado(Suscripcion $s, string $hito): bool
    {
        return AvisoVencimiento::where('suscripcion_id', $s->id)->whereDate('fecha_proximo_pago', $s->fecha_proximo_pago)
            ->where('hito', $hito)->exists();
    }

    /** Si ya salió uno más urgente en este ciclo (p. ej. se corrigió la fecha), no se manda uno anterior. */
    private function hayMasUrgente(Suscripcion $s, int $hito): bool
    {
        return AvisoVencimiento::where('suscripcion_id', $s->id)->whereDate('fecha_proximo_pago', $s->fecha_proximo_pago)
            ->where('hito', '!=', AvisoVencimiento::HITO_SUSPENDIDA)->get()
            ->contains(fn ($a) => (int) $a->hito < $hito);
    }

    /** @return array{suscripcion: int, hito: string, entregado: bool} */
    private function enviar(Suscripcion $s, string $hito, int $dias): array
    {
        $suspendida = $hito === AvisoVencimiento::HITO_SUSPENDIDA;
        $mensaje = new AvisoVencimientoMail($s->cliente->nombre, $s->producto->nombre, $s->producto->planVigente($s->plan)?->nombre,
            $s->fecha_proximo_pago->format('d/m/Y'), max($dias, 0), $suspendida, $this->avisos->asesor($s));
        $contexto = ['cliente_id' => $s->cliente_id, 'suscripcion_id' => $s->id, 'referencia' => "vencimiento:{$s->fecha_proximo_pago->toDateString()}:{$hito}", 'operador_id' => null];

        $cliente = array_values(array_unique(array_filter([$s->admin_email, $s->cliente->correo])));
        $registros = $this->correo->enviarA($mensaje, $cliente, $contexto);
        $entregado = collect($registros)->contains(fn (CorreoEnviado $c) => $c->estado === CorreoEnviado::ENVIADO);

        // Copias solo si el aviso al cliente salió (si no, se reintenta mañana y no se duplican).
        if ($entregado) {
            $copias = $s->cliente->operadores()->where('activo', true)->pluck('email')->all();
            if ($suspendida || $dias <= (int) config('kernia.avisos_vencimiento_direccion_desde')) {
                $copias = [...$copias, ...LandlordAdmin::where('rol', 'direccion')->where('activo', true)->pluck('email')->all()];
            }
            $this->correo->enviarA($mensaje, array_diff($copias, $cliente), $contexto);
            AvisoVencimiento::create(['suscripcion_id' => $s->id, 'fecha_proximo_pago' => $s->fecha_proximo_pago, 'hito' => $hito, 'enviado_en' => now()]);
        }

        return ['suscripcion' => $s->id, 'hito' => $hito, 'entregado' => $entregado];
    }
}
