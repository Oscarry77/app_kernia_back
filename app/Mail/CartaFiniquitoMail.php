<?php
namespace App\Mail;

/**
 * Carta de agradecimiento y entrega del respaldo (07-oct-2026; estándar v2.3
 * §7, decisiones del 05-oct). NO lleva la contraseña: esa va en un correo
 * aparte (ClaveRespaldoMail). La copia al asesor y a Dirección es esta misma.
 */
class CartaFiniquitoMail extends MensajeKernia
{
    /**
     * @param 'cliente'|'empresa' $alcance
     * @param list<array{rfc: string, nombre: string}> $empresas
     * @param list<array{archivo: string, registros: int}> $contenido
     * @param array{nombre: string, email: ?string}|null $asesor
     */
    public function __construct(
        public readonly string $cliente,
        public readonly string $app,
        public readonly string $alcance,
        public readonly array $empresas,
        public readonly array $contenido,
        public readonly string $fechaLimite,
        public readonly string $huella,
        public readonly string $fechaEliminacion,
        public readonly ?array $asesor,
        public readonly ?string $enlaceFormulario = null,
    ) {
    }

    public static function plantilla(): string
    {
        return 'carta_finiquito';
    }

    public static function nombre(): string
    {
        return 'Carta de agradecimiento y entrega del respaldo';
    }

    public static function ejemplo(): static
    {
        return new static('Empresa Ejemplo, SA de CV', 'Comercializa', 'cliente',
            [['rfc' => 'EEJ010101AB1', 'nombre' => 'Empresa Ejemplo'], ['rfc' => 'EEJ020202CD2', 'nombre' => 'Ejemplo Servicios']],
            [['archivo' => 'Facturas', 'registros' => 1520], ['archivo' => 'Clientes', 'registros' => 340], ['archivo' => 'Productos y servicios', 'registros' => 812]],
            '22/10/2026', '3f5a9c0e7b1d4a2f8e6c5b9a0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e', '04/01/2027',
            ['nombre' => 'Ana López', 'email' => 'ana.lopez@kernia.com'], 'https://kernia.com/salida/ejemplo');
    }

    protected function asunto(): string
    {
        return "Gracias por usar {$this->app}: tu respaldo está listo";
    }

    protected function datos(): array
    {
        return get_object_vars($this);
    }
}
