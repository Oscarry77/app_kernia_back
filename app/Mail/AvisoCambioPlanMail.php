<?php
namespace App\Mail;

/**
 * Aviso al cliente de una baja de plan autorizada (07-oct-2026): qué cambia y
 * desde cuándo. Se envía al autorizarse; la baja se aplica a las 00:00 de la
 * fecha indicada (REGISTRO_DECISIONES_KERNIA.md §3, 05-oct).
 *
 * @phpstan-type Limite array{nombre: string, antes: int|string, despues: int|string}
 */
class AvisoCambioPlanMail extends MensajeKernia
{
    /**
     * @param list<string> $pierde módulos que deja de tener
     * @param list<Limite> $limites solo los que cambian
     * @param array{nombre: string, email: ?string}|null $asesor
     */
    public function __construct(
        public readonly string $cliente,
        public readonly string $app,
        public readonly string $planActual,
        public readonly string $planNuevo,
        public readonly string $fechaEfectiva,
        public readonly array $pierde,
        public readonly array $limites,
        public readonly ?array $asesor,
    ) {
    }

    public static function plantilla(): string
    {
        return 'aviso_cambio_plan';
    }

    public static function nombre(): string
    {
        return 'Aviso de cambio de plan';
    }

    public static function ejemplo(): static
    {
        return new static('Empresa Ejemplo, SA de CV', 'Comercializa', 'Backoffice Corporativo', 'Backoffice Básico', '01/01/2027',
            ['Tesorería', 'Viáticos'], [['nombre' => 'Empresas', 'antes' => 10, 'despues' => 4]],
            ['nombre' => 'Ana López', 'email' => 'ana.lopez@kernia.com']);
    }

    protected function asunto(): string
    {
        return "Tu plan de {$this->app} cambiará el {$this->fechaEfectiva}";
    }

    protected function datos(): array
    {
        return get_object_vars($this);
    }
}
