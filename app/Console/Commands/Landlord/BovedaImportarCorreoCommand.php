<?php
namespace App\Console\Commands\Landlord;

use App\Services\Boveda\CorreoKernia;
use Illuminate\Console\Command;

/**
 * Pasa las credenciales SMTP del .env (MAIL_*) a la bóveda, sin imprimirlas
 * (07-oct-2026). Después: MAIL_MAILER=kernia y borrar MAIL_USERNAME y
 * MAIL_PASSWORD del .env.
 */
class BovedaImportarCorreoCommand extends Command
{
    protected $signature = 'landlord:boveda-importar-correo {--forzar : Reemplaza lo que ya haya en la bóveda}';

    protected $description = 'Copia las credenciales SMTP del .env a la bóveda de Kernia sin mostrarlas.';

    public function handle(CorreoKernia $correo): int
    {
        $smtp = config('mail.mailers.smtp');
        $from = config('mail.from');

        $faltan = array_keys(array_filter([
            'MAIL_HOST' => empty($smtp['host']) || $smtp['host'] === '127.0.0.1',
            'MAIL_PORT' => empty($smtp['port']),
            'MAIL_USERNAME' => empty($smtp['username']),
            'MAIL_PASSWORD' => empty($smtp['password']),
            'MAIL_FROM_ADDRESS' => empty($from['address']) || $from['address'] === 'hello@example.com',
        ]));
        if ($faltan) {
            $this->error('Faltan en el .env: '.implode(', ', $faltan).'. Captura las credenciales desde el panel (Bóveda).');

            return self::FAILURE;
        }

        if ($correo->configurado() && ! $this->option('forzar')) {
            $this->warn('La bóveda ya tiene credenciales del buzón. Usa --forzar para reemplazarlas.');

            return self::FAILURE;
        }

        $port = (int) $smtp['port'];
        $correo->guardar([
            'host' => strtolower($smtp['host']),
            'port' => $port,
            'cifrado' => $port === 465 || ($smtp['scheme'] ?? null) === 'smtps' ? 'ssl' : 'tls',
            'usuario' => $smtp['username'],
            'password' => $smtp['password'],
            'remitente' => strtolower($from['address']),
            'nombre_remitente' => $from['name'] ?: 'Kernia',
        ], null, 'Credenciales importadas del .env por consola');

        $this->info('Credenciales del buzón guardadas en la bóveda (no se muestran).');
        $this->line('Siguiente: en el .env pon MAIL_MAILER=kernia, borra MAIL_USERNAME y MAIL_PASSWORD,');
        $this->line('corre "php artisan config:clear" y envía un correo de prueba desde el panel (Bóveda).');

        return self::SUCCESS;
    }
}
