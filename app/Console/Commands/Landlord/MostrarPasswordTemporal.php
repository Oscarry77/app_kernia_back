<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\Suscripcion;
use Illuminate\Console\Command;

/**
 * Muestra UNA sola vez la contraseña temporal del administrador inicial
 * (30-sep-2026, opción A del estándar v2): Kernia la genera y la manda a la
 * app, pero nunca la guarda -- si no se muestra aquí, nadie puede hacer el
 * primer ingreso. La app obliga a cambiarla en ese primer ingreso.
 */
final class MostrarPasswordTemporal
{
    public static function mostrar(Command $comando, ?string $email, string $password, string $estatus, bool $esReintento = false): void
    {
        $comando->newLine();
        $comando->warn('=== Contraseña temporal del administrador inicial (se muestra UNA sola vez) ===');
        $comando->line("  Usuario:    {$email}");
        $comando->line("  Contraseña: {$password}");
        $comando->warn('  Entrégala al cliente por un canal privado. NO la guardes en MDs, chats ni tickets.');
        $comando->warn('  La app obliga a cambiarla en el primer ingreso.');

        if ($estatus === Suscripcion::ESTATUS_EN_APROVISIONAMIENTO) {
            $comando->warn('  Será válida cuando la suscripción pase a "activo" (landlord:sincronizar-aprovisionamientos).');
        }

        if ($esReintento) {
            $comando->warn('  Reintento: si el intento anterior alcanzó a crear el tenant en la app, esta contraseña NO aplica y');
            $comando->warn('  sigue vigente la original. En ese caso usa el restablecimiento de administrador (estándar v2).');
        }

        $comando->newLine();
    }
}
