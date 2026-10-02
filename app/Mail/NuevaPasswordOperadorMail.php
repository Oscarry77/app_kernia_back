<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Nueva contraseña de un operador de Kernia, solicitada desde el login
 * (02-oct-2026). Se envía de forma síncrona: nunca se encola, para que la
 * contraseña no quede guardada en la tabla de trabajos.
 */
class NuevaPasswordOperadorMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nombre,
        public readonly string $password,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Kernia — tu nueva contraseña de acceso');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.nueva-password-operador');
    }
}
