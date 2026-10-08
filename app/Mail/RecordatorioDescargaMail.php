<?php
namespace App\Mail;

/** Recordatorio de descarga del respaldo, 7 y 2 días antes del plazo (07-oct-2026; estándar v2.3 §7). */
class RecordatorioDescargaMail extends MensajeKernia
{
    /** @param array{nombre: string, email: ?string}|null $asesor */
    public function __construct(
        public readonly string $cliente,
        public readonly string $app,
        public readonly string $fechaLimite,
        public readonly int $diasRestantes,
        public readonly ?array $asesor,
    ) {
    }

    public static function plantilla(): string
    {
        return 'recordatorio_descarga';
    }

    public static function nombre(): string
    {
        return 'Recordatorio de descarga del respaldo';
    }

    public static function ejemplo(): static
    {
        return new static('Empresa Ejemplo, SA de CV', 'Comercializa', '22/10/2026', 2, ['nombre' => 'Ana López', 'email' => 'ana.lopez@kernia.com']);
    }

    protected function asunto(): string
    {
        return $this->diasRestantes === 1
            ? "Mañana vence la descarga de tu respaldo de {$this->app}"
            : "Quedan {$this->diasRestantes} días para descargar tu respaldo de {$this->app}";
    }

    protected function datos(): array
    {
        return get_object_vars($this);
    }
}
