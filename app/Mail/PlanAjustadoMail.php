<?php
namespace App\Mail;

/**
 * Aviso al administrador del cliente de que la baja de plan YA se aplicó
 * (09-oct-2026; decisión del dueño del 05-oct: "Kernia regresa a activo y se
 * notifica al administrador del cliente"). Dice qué empresas siguen
 * disponibles y cuáles quedaron bloqueadas por el plan, con sus datos intactos.
 *
 * @phpstan-type Empresa array{rfc: ?string, nombre: string}
 */
class PlanAjustadoMail extends MensajeKernia
{
    /**
     * @param list<Empresa> $disponibles
     * @param list<Empresa> $bloqueadas
     * @param array{nombre: string, email: ?string}|null $asesor
     */
    public function __construct(
        public readonly string $cliente,
        public readonly string $app,
        public readonly string $planNuevo,
        public readonly array $disponibles,
        public readonly array $bloqueadas,
        public readonly bool $sinSeleccion,
        public readonly ?array $asesor,
    ) {
    }

    public static function plantilla(): string
    {
        return 'plan_ajustado';
    }

    public static function nombre(): string
    {
        return 'Plan ajustado (baja aplicada)';
    }

    public static function ejemplo(): static
    {
        return new static('Empresa Ejemplo, SA de CV', 'Comercializa', 'Backoffice Básico',
            [['rfc' => 'EEJ010101AB1', 'nombre' => 'Empresa Ejemplo'], ['rfc' => 'EEJ020202CD2', 'nombre' => 'Ejemplo Servicios']],
            [['rfc' => 'EEJ030303EF3', 'nombre' => 'Ejemplo Logística']], false,
            ['nombre' => 'Ana López', 'email' => 'ana.lopez@kernia.com']);
    }

    protected function asunto(): string
    {
        return "Tu plan de {$this->app} ya cambió a {$this->planNuevo}";
    }

    protected function datos(): array
    {
        return get_object_vars($this);
    }
}
