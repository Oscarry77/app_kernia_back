<?php
namespace App\Mail;

/** Correo de prueba del buzón de Kernia, enviado desde la bóveda (07-oct-2026). */
class PruebaCorreoKerniaMail extends MensajeKernia
{
    public function __construct(public readonly string $operador)
    {
    }

    public static function plantilla(): string
    {
        return 'prueba_buzon';
    }

    public static function nombre(): string
    {
        return 'Prueba del buzón';
    }

    public static function ejemplo(): static
    {
        return new static('Ana López');
    }

    protected function asunto(): string
    {
        return 'prueba del buzón';
    }

    protected function datos(): array
    {
        return get_object_vars($this);
    }
}
