<?php
namespace App\Mail;

/**
 * Aviso de vencimiento al cliente (09-oct-2026, fase 4; decisiones del 21-sep
 * y 02-oct): recordatorios desde 30 días antes de la fecha de próximo pago y
 * aviso de suspensión. Copia al asesor; a Dirección, solo los urgentes.
 */
class AvisoVencimientoMail extends MensajeKernia
{
    /** @param array{nombre: string, email: ?string}|null $asesor */
    public function __construct(
        public readonly string $cliente,
        public readonly string $app,
        public readonly ?string $plan,
        public readonly string $fechaPago,
        public readonly int $diasRestantes,
        public readonly bool $suspendida,
        public readonly ?array $asesor,
    ) {
    }

    public static function plantilla(): string
    {
        return 'aviso_vencimiento';
    }

    public static function nombre(): string
    {
        return 'Aviso de vencimiento';
    }

    public static function ejemplo(): static
    {
        return new static('Empresa Ejemplo, SA de CV', 'Comercializa', 'Backoffice Profesional', '01/11/2026', 7, false,
            ['nombre' => 'Ana López', 'email' => 'ana.lopez@kernia.com']);
    }

    protected function asunto(): string
    {
        return match (true) {
            $this->suspendida => "Tu servicio de {$this->app} está suspendido",
            $this->diasRestantes <= 0 => "Tu suscripción de {$this->app} vence hoy",
            $this->diasRestantes === 1 => "Tu suscripción de {$this->app} vence mañana",
            default => "Tu suscripción de {$this->app} vence en {$this->diasRestantes} días",
        };
    }

    protected function datos(): array
    {
        return get_object_vars($this);
    }
}
