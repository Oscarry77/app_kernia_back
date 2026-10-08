<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Correo de prueba del buzón de Kernia, enviado desde la bóveda (07-oct-2026). */
class PruebaCorreoKerniaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $operador)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Kernia — prueba del buzón');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.prueba-correo-kernia');
    }
}
