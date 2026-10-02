<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\LandlordAdmin;
use App\Services\Seguridad\GeneradorPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Restablece la contraseña de un operador de Kernia (kernia-admin). Kernia
 * solo guarda el hash: si el operador la olvida, esta es la vía de
 * recuperación (02-oct-2026). La nueva se muestra UNA vez y nunca se guarda
 * en claro. Ejecútalo en una terminal propia, no dentro de un chat.
 */
class RestablecerPasswordOperadorCommand extends Command
{
    protected $signature = 'landlord:restablecer-password-operador {email : Correo del operador de Kernia}';

    protected $description = 'Genera una contraseña nueva para un operador de Kernia (kernia-admin) y la muestra una sola vez.';

    public function handle(): int
    {
        $admin = LandlordAdmin::where('email', strtolower((string) $this->argument('email')))->first();
        if (! $admin) {
            $this->error('No existe un operador con ese correo.');

            return self::FAILURE;
        }

        $password = GeneradorPassword::generar(18);
        $admin->forceFill(['password' => Hash::make($password)])->save();

        Log::info('landlord.restablecer_password_operador', ['operador_id' => $admin->id]);

        $this->info("Contraseña restablecida para {$admin->email}.");
        $this->warn('Se muestra UNA sola vez. Guárdala en tu gestor de contraseñas; no la pegues en chats ni MDs:');
        $this->line("  {$password}");

        return self::SUCCESS;
    }
}
