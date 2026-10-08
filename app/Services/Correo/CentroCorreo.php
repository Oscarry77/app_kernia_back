<?php
namespace App\Services\Correo;

use App\Mail\MensajeKernia;
use App\Models\Landlord\CorreoEnviado;
use App\Services\Boveda\CorreoKernia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Centro de correo de Kernia (07-oct-2026; decisión del 05-oct): todo correo
 * que Kernia envía pasa por aquí y queda en `correos_enviados` (solo
 * metadatos, nunca el cuerpo ni una contraseña).
 *
 *  - Si el correo de Kernia no está disponible (bóveda sin credenciales o
 *    mailer de pruebas), el envío se registra como `omitido`, sin error.
 *  - Un fallo del servidor de correo se registra como `fallido` con un
 *    motivo saneado y no interrumpe la operación que lo pidió.
 *  - Un destinatario por envío: así cada correo tiene su propio registro.
 */
class CentroCorreo
{
    public function __construct(private readonly CorreoKernia $correo)
    {
    }

    /**
     * @param array{cliente_id?: ?int, suscripcion_id?: ?int, referencia?: ?string, operador_id?: ?int} $contexto
     * @param ?string $mailer forzar un mailer (el correo de prueba usa siempre el de la bóveda)
     */
    public function enviar(MensajeKernia $mensaje, string $destinatario, array $contexto = [], ?string $mailer = null): CorreoEnviado
    {
        $registro = [
            'plantilla' => $mensaje::plantilla(),
            'destinatario' => strtolower(trim($destinatario)),
            'asunto' => mb_substr($mensaje->asuntoCompleto(), 0, 255),
            'contiene_secreto' => $mensaje::contieneSecreto(),
            'cliente_id' => $contexto['cliente_id'] ?? null,
            'suscripcion_id' => $contexto['suscripcion_id'] ?? null,
            'referencia' => $contexto['referencia'] ?? null,
            'operador_id' => $contexto['operador_id'] ?? auth('api')->id(),
        ];

        // Con el mailer de la bóveda forzado (correo de prueba) basta con que haya credenciales.
        $disponible = $mailer === CorreoKernia::MAILER ? $this->correo->configurado() : $this->correo->disponible();
        if (! $disponible) {
            return CorreoEnviado::create([...$registro, 'estado' => CorreoEnviado::OMITIDO, 'error' => 'El correo de Kernia no está configurado.']);
        }

        try {
            Mail::mailer($mailer)->to($registro['destinatario'])->send($mensaje);
        } catch (Throwable $e) {
            Log::warning('correo.envio_fallido', ['plantilla' => $registro['plantilla'], 'error' => $e::class]);

            return CorreoEnviado::create([...$registro, 'estado' => CorreoEnviado::FALLIDO, 'error' => self::motivo($e)]);
        }

        return CorreoEnviado::create([...$registro, 'estado' => CorreoEnviado::ENVIADO]);
    }

    /**
     * El mismo mensaje a varios destinatarios (sin repetir), cada uno con su registro.
     *
     * @param list<string> $destinatarios
     * @return list<CorreoEnviado>
     */
    public function enviarA(MensajeKernia $mensaje, array $destinatarios, array $contexto = []): array
    {
        $unicos = array_values(array_unique(array_filter(array_map(fn ($d) => strtolower(trim((string) $d)), $destinatarios))));

        return array_map(fn (string $d) => $this->enviar(clone $mensaje, $d, $contexto), $unicos);
    }

    /** Motivo legible sin credenciales ni rutas internas. */
    public static function motivo(Throwable $e): string
    {
        $m = $e->getMessage();

        return match (true) {
            str_contains($m, '535') || str_contains($m, 'authenticate') => 'El servidor rechazó el usuario o la contraseña del buzón (en Gmail se usa una contraseña de aplicación).',
            str_contains($m, '550') || str_contains($m, '553') => 'El servidor rechazó el destinatario.',
            str_contains($m, 'TLS') || str_contains($m, 'SSL') => 'Falló la conexión segura; revisa el puerto y el cifrado (587 con TLS o 465 con SSL).',
            str_contains($m, 'Connection') || str_contains($m, 'connect') => 'No se pudo conectar con el servidor de correo; revisa el servidor, el puerto y el cifrado.',
            default => 'Error al enviar ('.class_basename($e).').',
        };
    }
}
