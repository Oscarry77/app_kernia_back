<?php

namespace Tests\Feature\Panel;

use App\Mail\NuevaPasswordOperadorMail;
use App\Mail\PruebaCorreoKerniaMail;
use App\Models\Landlord\Auditoria;
use App\Models\Landlord\BovedaAcceso;
use App\Models\Landlord\BovedaSecreto;
use App\Models\Landlord\LandlordAdmin;
use App\Services\Boveda\BovedaService;
use App\Services\Boveda\CorreoKernia;
use App\Services\Landlord\VigenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

/** Bóveda de Kernia y buzón operado desde ella (07-oct-2026). */
class BovedaTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Clave-de-prueba-123!';
    private const SECRETO_BUZON = 'contrasena-de-aplicacion-xyz';

    private LandlordAdmin $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = $this->operador('super', 'superadmin');
    }

    private function operador(string $nombre, string $rol): LandlordAdmin
    {
        return LandlordAdmin::create(['nombre' => ucfirst($nombre), 'email' => "{$nombre}@kernia.test", 'rol' => $rol, 'password' => self::PW, 'activo' => true]);
    }

    private function como(LandlordAdmin $a): array
    {
        $this->app['auth']->forgetGuards();
        JWTAuth::unsetToken();
        $this->app['tymon.jwt']->unsetToken();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($a)];
    }

    private function datosCorreo(array $cambios = []): array
    {
        return [
            'host' => 'smtp.gmail.com', 'port' => 587, 'cifrado' => 'tls', 'usuario' => 'kernia@gmail.com',
            'password' => self::SECRETO_BUZON, 'remitente' => 'kernia@gmail.com', 'nombre_remitente' => 'Kernia',
            'password_operador' => self::PW, ...$cambios,
        ];
    }

    public function test_solo_el_superadmin_y_con_su_contrasena(): void
    {
        $direccion = $this->operador('dir', 'direccion');
        $this->withHeaders($this->como($direccion))->getJson('/api/boveda')->assertForbidden();
        $this->withHeaders($this->como($direccion))->putJson('/api/boveda/correo', $this->datosCorreo())->assertForbidden();

        $this->withHeaders($this->como($this->super))->putJson('/api/boveda/correo', $this->datosCorreo(['password_operador' => 'mala']))
            ->assertStatus(422)->assertJsonValidationErrors('password_operador');
        $this->assertFalse(app(CorreoKernia::class)->configurado());
        $this->assertTrue(Auditoria::where('accion', 'boveda.confirmacion_fallida')->exists());

        // La primera vez, la contraseña del buzón es obligatoria
        $this->withHeaders($this->como($this->super))->putJson('/api/boveda/correo', $this->datosCorreo(['password' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_el_secreto_se_guarda_cifrado_y_nunca_se_devuelve(): void
    {
        $r = $this->withHeaders($this->como($this->super))->putJson('/api/boveda/correo', $this->datosCorreo())
            ->assertOk()->assertJsonPath('correo.configurado', true)->assertJsonPath('correo.resumen.host', 'smtp.gmail.com');
        $this->assertStringNotContainsString(self::SECRETO_BUZON, $r->getContent());

        // En la base solo hay texto cifrado
        $crudo = DB::table('boveda_secretos')->where('clave', CorreoKernia::CLAVE)->value('valor');
        $this->assertStringNotContainsString(self::SECRETO_BUZON, $crudo);

        // Ni el listado, ni la bitácora de Kernia, ni la de la bóveda lo contienen
        $lista = $this->withHeaders($this->como($this->super))->getJson('/api/boveda')->assertOk();
        $this->assertStringNotContainsString(self::SECRETO_BUZON, $lista->getContent());
        $this->assertStringNotContainsString(self::SECRETO_BUZON, json_encode(Auditoria::all()->toArray()));
        $this->assertStringNotContainsString(self::SECRETO_BUZON, json_encode(BovedaAcceso::all()->toArray()));
        $this->assertStringNotContainsString(self::SECRETO_BUZON, json_encode(BovedaSecreto::all()->toArray()));

        // Reemplazar sin escribir la contraseña del buzón conserva la guardada
        $this->withHeaders($this->como($this->super))->putJson('/api/boveda/correo', $this->datosCorreo(['password' => '', 'nombre_remitente' => 'Kernia SaaS']))
            ->assertOk()->assertJsonPath('correo.resumen.nombre_remitente', 'Kernia SaaS');
        $this->assertSame(self::SECRETO_BUZON, app(CorreoKernia::class)->credenciales()['password']);
        $this->assertSame(['guardado', 'reemplazado'], BovedaAcceso::orderBy('id')->pluck('accion')->all());
    }

    public function test_el_mailer_kernia_usa_la_boveda(): void
    {
        config(['mail.default' => 'kernia']);
        $correo = app(CorreoKernia::class);
        $this->assertFalse($correo->disponible()); // sin credenciales no hay correo

        $this->withHeaders($this->como($this->super))->putJson('/api/boveda/correo', $this->datosCorreo())->assertOk();
        $this->assertTrue($correo->disponible());

        $transporte = Mail::mailer('kernia')->getSymfonyTransport();
        $this->assertInstanceOf(EsmtpTransport::class, $transporte);
        $this->assertSame('kernia@gmail.com', $transporte->getUsername());
        $this->assertSame('kernia@gmail.com', config('mail.from.address'));

        // "Olvidé mi contraseña" ya considera disponible el correo de la bóveda
        Mail::fake();
        $this->postJson('/api/auth/password/solicitar', ['email' => 'super@kernia.test'])->assertStatus(202);
        Mail::assertSent(NuevaPasswordOperadorMail::class);
    }

    public function test_correo_de_prueba(): void
    {
        $this->withHeaders($this->como($this->super))->postJson('/api/boveda/correo/probar', ['destinatario' => 'yo@kernia.test'])->assertStatus(422);

        $this->withHeaders($this->como($this->super))->putJson('/api/boveda/correo', $this->datosCorreo())->assertOk();
        Mail::fake();
        $this->withHeaders($this->como($this->super))->postJson('/api/boveda/correo/probar', ['destinatario' => 'yo@kernia.test'])->assertOk();
        Mail::assertSent(PruebaCorreoKerniaMail::class, fn ($m) => $m->hasTo('yo@kernia.test'));
        $this->assertSame('prueba', BovedaAcceso::latest('id')->value('accion'));
    }

    public function test_secretos_de_cliente_registran_cada_uso_y_se_purgan(): void
    {
        $boveda = app(BovedaService::class);
        $boveda->guardar('respaldo.prueba-1', 'clave-del-respaldo', [
            'tipo' => BovedaSecreto::TIPO_RESPALDO, 'expira_en' => VigenciaService::hoy()->addDays(90)->toDateString(),
        ]);

        $this->assertSame('clave-del-respaldo', $boveda->leer('respaldo.prueba-1', 'Reenvío autorizado', $this->super->id));
        $this->assertSame('usado', BovedaAcceso::latest('id')->value('accion'));

        // No ha vencido: no se purga
        $this->assertSame(0, $boveda->purgarVencidos());

        // Venció: se borra el valor y se conserva el registro
        BovedaSecreto::where('clave', 'respaldo.prueba-1')->update(['expira_en' => VigenciaService::hoy()->subDay()->toDateString()]);
        $this->artisan('landlord:boveda-purgar')->assertSuccessful();
        $s = BovedaSecreto::where('clave', 'respaldo.prueba-1')->first();
        $this->assertNull($s->getRawOriginal('valor'));
        $this->assertNotNull($s->purgado_en);
        $this->assertNull($boveda->leer('respaldo.prueba-1'));
        $this->assertSame('purgado', BovedaAcceso::latest('id')->value('accion'));
    }

    public function test_importar_del_env_sin_mostrar_la_contrasena(): void
    {
        config([
            'mail.mailers.smtp' => ['host' => 'smtp.gmail.com', 'port' => 587, 'username' => 'kernia@gmail.com', 'password' => self::SECRETO_BUZON, 'scheme' => null],
            'mail.from' => ['address' => 'kernia@gmail.com', 'name' => 'Kernia'],
        ]);

        $this->artisan('landlord:boveda-importar-correo')
            ->doesntExpectOutputToContain(self::SECRETO_BUZON)
            ->assertSuccessful();
        $this->assertSame(self::SECRETO_BUZON, app(CorreoKernia::class)->credenciales()['password']);

        // Una segunda vez no reemplaza sin --forzar
        $this->artisan('landlord:boveda-importar-correo')->assertFailed();
    }
}
