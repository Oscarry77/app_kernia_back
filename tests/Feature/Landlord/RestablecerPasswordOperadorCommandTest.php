<?php

namespace Tests\Feature\Landlord;

use App\Models\Landlord\LandlordAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RestablecerPasswordOperadorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_genera_una_password_nueva_que_sirve_y_la_anterior_no(): void
    {
        $admin = LandlordAdmin::create(['nombre' => 'Op', 'email' => 'op@kernia.test', 'password' => 'Vieja-123!abc', 'activo' => true]);

        $this->assertSame(0, Artisan::call('landlord:restablecer-password-operador', ['email' => 'OP@kernia.test']));
        preg_match('/^\s{2}(\S+)\s*$/m', Artisan::output(), $m);

        $hash = $admin->fresh()->password;
        $this->assertTrue(Hash::check($m[1], $hash), 'La contraseña mostrada debe ser la que quedó guardada.');
        $this->assertFalse(Hash::check('Vieja-123!abc', $hash));
    }

    public function test_correo_inexistente_falla_sin_cambios(): void
    {
        $this->assertSame(1, Artisan::call('landlord:restablecer-password-operador', ['email' => 'nadie@kernia.test']));
    }
}
