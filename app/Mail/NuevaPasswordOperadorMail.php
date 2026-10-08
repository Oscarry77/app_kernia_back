<?php
namespace App\Mail;

/**
 * Nueva contraseña de un operador de Kernia (02-oct-2026): "olvidé mi
 * contraseña" y alta de operadores. Lleva un secreto. 07-oct-2026: plantilla
 * HTML del centro de correo.
 */
class NuevaPasswordOperadorMail extends MensajeKernia
{
    public function __construct(
        public readonly string $nombre,
        public readonly string $password,
    ) {
    }

    public static function plantilla(): string
    {
        return 'nueva_password_operador';
    }

    public static function nombre(): string
    {
        return 'Contraseña de acceso de un operador';
    }

    public static function contieneSecreto(): bool
    {
        return true;
    }

    public static function ejemplo(): static
    {
        return new static('Ana López', '••••••••••••••••••');
    }

    protected function asunto(): string
    {
        return 'tu nueva contraseña de acceso';
    }

    protected function datos(): array
    {
        return get_object_vars($this);
    }
}
