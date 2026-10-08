<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Base de los correos de Kernia (07-oct-2026, centro de correo): plantilla
 * HTML con la imagen de Kernia (`emails.kernia.*`), nombre visible para el
 * panel y si el correo lleva un secreto (contraseña), en cuyo caso nunca se
 * reenvía desde el registro.
 *
 * Se envían de forma síncrona (CentroCorreo): un correo con contraseña nunca
 * se encola, para que no quede guardada en la tabla de trabajos.
 */
abstract class MensajeKernia extends Mailable
{
    use Queueable;

    /** Clave estable de la plantilla (registro y vista previa). */
    abstract public static function plantilla(): string;

    /** Nombre para el panel. */
    abstract public static function nombre(): string;

    /** Un ejemplo con datos ficticios, para la vista previa del panel. */
    abstract public static function ejemplo(): static;

    abstract protected function asunto(): string;

    /** @return array<string,mixed> variables de la vista */
    abstract protected function datos(): array;

    public static function contieneSecreto(): bool
    {
        return false;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Kernia — '.$this->asunto());
    }

    public function content(): Content
    {
        return new Content(view: 'emails.kernia.'.static::plantilla(), with: $this->datos());
    }

    public function asuntoCompleto(): string
    {
        return 'Kernia — '.$this->asunto();
    }
}
