<?php
namespace App\Mail;

/**
 * Contraseña del respaldo (07-oct-2026; estándar v2.3 §3.2). Va SOLA, en un
 * correo distinto de la carta. Lleva un secreto: el registro nunca la guarda y
 * no se reenvía desde el panel (el reenvío sale de la bóveda, con escalafón).
 */
class ClaveRespaldoMail extends MensajeKernia
{
    public function __construct(
        public readonly string $cliente,
        public readonly string $app,
        public readonly string $clave,
        public readonly string $fechaLimite,
    ) {
    }

    public static function plantilla(): string
    {
        return 'clave_respaldo';
    }

    public static function nombre(): string
    {
        return 'Contraseña del respaldo';
    }

    public static function contieneSecreto(): bool
    {
        return true;
    }

    public static function ejemplo(): static
    {
        return new static('Empresa Ejemplo, SA de CV', 'Comercializa', '••••••••••••••••••••••••', '22/10/2026');
    }

    protected function asunto(): string
    {
        return "Contraseña de tu respaldo de {$this->app}";
    }

    protected function datos(): array
    {
        return get_object_vars($this);
    }
}
