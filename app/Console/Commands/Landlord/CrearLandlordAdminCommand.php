<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\LandlordAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CrearLandlordAdminCommand extends Command
{
    protected $signature = 'landlord:crear-admin
        {nombre : Nombre del administrador}
        {email : Correo con el que hará login en Kernia}
        {--password= : Password a asignar -- si se omite, se genera uno aleatorio}';

    protected $description = 'Da de alta un administrador de Kernia. No hay registro público, este comando es la única forma de crear el primero.';

    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));

        if (LandlordAdmin::where('email', $email)->exists()) {
            $this->error("Ya existe un admin con el email '{$email}'.");

            return self::FAILURE;
        }

        $password = $this->option('password') ?: Str::random(16);

        $admin = LandlordAdmin::create([
            'nombre'   => $this->argument('nombre'),
            'email'    => $email,
            'password' => Hash::make($password),
            'activo'   => true,
        ]);

        $this->info("Admin '{$admin->email}' creado (id={$admin->id}).");

        if (! $this->option('password')) {
            $this->warn('Password generado -- esta es la ÚNICA vez que se muestra en claro, guárdalo ahora:');
            $this->line("  {$password}");
        }

        return self::SUCCESS;
    }
}
