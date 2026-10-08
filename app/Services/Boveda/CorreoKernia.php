<?php
namespace App\Services\Boveda;

use App\Models\Landlord\BovedaSecreto;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Buzón de Kernia operado SOLO desde Kernia (07-oct-2026; decisión del 05-oct):
 * sus credenciales viven en la bóveda, no en el .env. Con MAIL_MAILER=kernia,
 * el transporte SMTP se arma al momento de enviar con lo que hay en la bóveda.
 * Al llegar el dominio propio, solo se reemplazan aquí; no se toca código.
 */
class CorreoKernia
{
    public const CLAVE = 'correo.smtp';
    public const MAILER = 'kernia';

    public function __construct(private readonly BovedaService $boveda)
    {
    }

    /** Hay credenciales del buzón en la bóveda. */
    public function configurado(): bool
    {
        return $this->boveda->existe(self::CLAVE);
    }

    /**
     * Kernia puede enviar correos de verdad: el mailer no es de pruebas
     * (log/array) y, si es el de la bóveda, la bóveda tiene credenciales.
     */
    public function disponible(): bool
    {
        $mailer = config('mail.default');

        return match ($mailer) {
            'log', 'array' => false,
            self::MAILER => $this->configurado(),
            default => true,
        };
    }

    /** Datos que se pueden mostrar (nunca la contraseña). */
    public function resumen(): ?array
    {
        $secreto = BovedaSecreto::with('actualizador:id,nombre')->where('clave', self::CLAVE)->first();
        if (! $secreto || ! $secreto->vigente()) {
            return null;
        }

        return [
            ...($secreto->resumen ?? []),
            'actualizado_en' => $secreto->updated_at?->toIso8601String(),
            'actualizado_por' => $secreto->actualizador?->nombre,
            'mailer_activo' => config('mail.default') === self::MAILER,
        ];
    }

    /**
     * @param array{host: string, port: int, cifrado: string, usuario: string, password: string, remitente: string, nombre_remitente: string} $datos
     */
    public function guardar(array $datos, ?int $operadorId, string $motivo = 'Credenciales del buzón capturadas desde el panel'): void
    {
        $this->boveda->guardar(self::CLAVE, $datos, [
            'tipo' => BovedaSecreto::TIPO_CORREO,
            'descripcion' => 'Buzón de Kernia (SMTP)',
            'resumen' => [
                'host' => $datos['host'], 'port' => (int) $datos['port'], 'cifrado' => $datos['cifrado'],
                'usuario' => $datos['usuario'], 'remitente' => $datos['remitente'], 'nombre_remitente' => $datos['nombre_remitente'],
            ],
        ], $operadorId, $motivo);

        // Un mailer ya creado conserva su transporte: se descarta para que el
        // siguiente envío use las credenciales nuevas.
        Mail::purge(self::MAILER);
    }

    /** Credenciales completas, solo para armar el transporte. */
    public function credenciales(): array
    {
        $datos = $this->boveda->leerArreglo(self::CLAVE);
        if (! $datos) {
            throw new RuntimeException('El buzón de Kernia no está configurado en la bóveda.');
        }

        return $datos;
    }

    /**
     * Transporte SMTP con las credenciales de la bóveda. `ssl` = SMTPS (465);
     * `tls` = STARTTLS (587), obligatorio.
     */
    public function transporte(): EsmtpTransport
    {
        $c = $this->credenciales();

        $transporte = new EsmtpTransport($c['host'], (int) $c['port'], $c['cifrado'] === 'ssl');
        if ($c['cifrado'] === 'tls') {
            $transporte->setRequireTls(true);
        }
        $transporte->setUsername($c['usuario']);
        $transporte->setPassword($c['password']);

        // El remitente sale de la bóveda; MailManager lo aplica al crear el mailer.
        config(['mail.from' => ['address' => $c['remitente'], 'name' => $c['nombre_remitente']]]);

        return $transporte;
    }
}
