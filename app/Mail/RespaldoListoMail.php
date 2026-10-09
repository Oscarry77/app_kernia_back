<?php
namespace App\Mail;

/**
 * (09-oct-2026) Respaldo listo para descargar en una COPIA a petición o en el
 * ARCHIVO de una empresa (estándar v2.3 casos C y A). El servicio sigue: no es
 * la carta de despedida del finiquito. La contraseña va en otro correo
 * (ClaveRespaldoMail).
 */
class RespaldoListoMail extends MensajeKernia
{
    /**
     * @param 'copia'|'archivo' $motivo
     * @param list<array{archivo: string, registros: int}> $contenido
     * @param array{nombre: string, email: ?string}|null $asesor
     */
    public function __construct(
        public readonly string $cliente,
        public readonly string $app,
        public readonly string $motivo,
        public readonly ?string $empresa,
        public readonly array $contenido,
        public readonly string $fechaLimite,
        public readonly string $huella,
        public readonly ?array $asesor,
    ) {
    }

    public static function plantilla(): string
    {
        return 'respaldo_listo';
    }

    public static function nombre(): string
    {
        return 'Respaldo listo (copia o archivo de empresa)';
    }

    public static function ejemplo(): static
    {
        return new static('Empresa Ejemplo, SA de CV', 'Comercializa', 'archivo', 'Ejemplo Logística (EEJ030303EF3)',
            [['archivo' => 'Facturas', 'registros' => 420], ['archivo' => 'Clientes', 'registros' => 85]],
            '24/10/2026', str_repeat('3f5a9c0e', 8), ['nombre' => 'Ana López', 'email' => 'ana.lopez@kernia.com']);
    }

    protected function asunto(): string
    {
        return $this->motivo === 'archivo'
            ? "El respaldo de {$this->empresa} en {$this->app} está listo"
            : "Tu copia de {$this->app} está lista";
    }

    protected function datos(): array
    {
        return get_object_vars($this);
    }
}
