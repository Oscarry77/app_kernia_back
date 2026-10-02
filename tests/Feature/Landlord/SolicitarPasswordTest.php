<?php

namespace Tests\Feature\Landlord;

use App\Mail\NuevaPasswordOperadorMail;
use App\Models\Landlord\LandlordAdmin;
use App\Services\Seguridad\GeneradorPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** "Olvidé mi contraseña" del panel kernia-admin (02-oct-2026). */
class SolicitarPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/auth/password/solicitar';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp']); // un transporte real; Mail::fake intercepta el envío
        Mail::fake();
    }

    private function operador(bool $activo = true): LandlordAdmin
    {
        return LandlordAdmin::create(['nombre' => 'Oscar', 'email' => 'op@kernia.test', 'password' => 'Anterior-123!x', 'activo' => $activo]);
    }

    public function test_genera_18_caracteres_seguros_y_sin_simbolos_conflictivos(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $p = GeneradorPassword::generar(18);

            $this->assertSame(18, strlen($p));
            $this->assertMatchesRegularExpression('/[A-Z]/', $p);
            $this->assertMatchesRegularExpression('/[a-z]/', $p);
            $this->assertMatchesRegularExpression('/[0-9]/', $p);
            $this->assertMatchesRegularExpression('/[!*\-_=+.@]/', $p);
            $this->assertDoesNotMatchRegularExpression('/[\'"`\\\\$#%&;<>|^~()\[\]{}\/?:, IOlo01]/', $p);
        }
    }

    public function test_operador_activo_recibe_por_correo_la_password_que_quedo_guardada(): void
    {
        $admin = $this->operador();

        $this->postJson(self::URL, ['email' => 'OP@kernia.test'])->assertStatus(202);

        Mail::assertSent(NuevaPasswordOperadorMail::class, function (NuevaPasswordOperadorMail $mail) use ($admin) {
            return $mail->hasTo('op@kernia.test')
                && strlen($mail->password) === 18
                && Hash::check($mail->password, $admin->fresh()->password);
        });
        $this->assertFalse(Hash::check('Anterior-123!x', $admin->fresh()->password));
    }

    public function test_respuesta_uniforme_y_sin_cambios_si_no_hay_cuenta_activa(): void
    {
        $inactivo = $this->operador(activo: false);

        $existe = $this->postJson(self::URL, ['email' => 'op@kernia.test'])->assertStatus(202)->json('message');
        $noExiste = $this->postJson(self::URL, ['email' => 'nadie@kernia.test'])->assertStatus(202)->json('message');

        $this->assertSame($existe, $noExiste, 'No debe revelar qué correos existen.');
        Mail::assertNothingSent();
        $this->assertTrue(Hash::check('Anterior-123!x', $inactivo->fresh()->password));
    }

    public function test_si_el_envio_falla_la_password_anterior_sigue_sirviendo(): void
    {
        $admin = $this->operador();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caído'));

        $this->postJson(self::URL, ['email' => 'op@kernia.test'])->assertStatus(202);

        $this->assertTrue(Hash::check('Anterior-123!x', $admin->fresh()->password));
    }

    public function test_con_mailer_log_no_cambia_nada_para_no_dejar_la_password_en_el_log(): void
    {
        config(['mail.default' => 'log']);
        $admin = $this->operador();

        $this->postJson(self::URL, ['email' => 'op@kernia.test'])
            ->assertStatus(503)
            ->assertJsonPath('codigo', 'CORREO_NO_CONFIGURADO');

        Mail::assertNothingSent();
        $this->assertTrue(Hash::check('Anterior-123!x', $admin->fresh()->password));
    }

    public function test_limite_de_solicitudes_por_correo(): void
    {
        $this->operador();

        foreach (range(1, 3) as $_) {
            $this->postJson(self::URL, ['email' => 'op@kernia.test'])->assertStatus(202);
        }
        $this->postJson(self::URL, ['email' => 'op@kernia.test'])->assertStatus(429);
    }
}
