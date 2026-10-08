<?php

namespace Tests\Feature\Panel;

use App\Mail\AvisoCambioPlanMail;
use App\Mail\CartaFiniquitoMail;
use App\Mail\ClaveRespaldoMail;
use App\Mail\NuevaPasswordOperadorMail;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\CorreoEnviado;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\NivelAutorizacion;
use App\Models\Landlord\Producto;
use App\Models\Landlord\ProductoModulo;
use App\Models\Landlord\ProductoPlan;
use App\Models\Landlord\Suscripcion;
use App\Services\Correo\CentroCorreo;
use App\Services\Landlord\SuscripcionPlanService;
use App\Services\Landlord\VigenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/** Centro de correo de Kernia (07-oct-2026). */
class CentroCorreoTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Clave-de-prueba-123!';
    private const SECRETO = 'Contrasena-Del-Respaldo-123';

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

    public function test_sin_correo_configurado_se_registra_omitido(): void
    {
        config(['mail.default' => 'log']);
        $r = app(CentroCorreo::class)->enviar(ClaveRespaldoMail::ejemplo(), 'cliente@empresa.mx');

        $this->assertSame(CorreoEnviado::OMITIDO, $r->estado);
        $this->assertTrue($r->contiene_secreto);
    }

    public function test_enviado_y_el_registro_nunca_guarda_el_cuerpo_ni_el_secreto(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $r = app(CentroCorreo::class)->enviar(new ClaveRespaldoMail('ACME', 'Comercializa', self::SECRETO, '22/10/2026'), 'Cliente@Empresa.mx',
            ['referencia' => 'exportacion:1']);

        $this->assertSame(CorreoEnviado::ENVIADO, $r->estado);
        $this->assertSame('cliente@empresa.mx', $r->destinatario);
        Mail::assertSent(ClaveRespaldoMail::class, fn ($m) => $m->hasTo('cliente@empresa.mx'));
        $this->assertStringNotContainsString(self::SECRETO, json_encode(DB::table('correos_enviados')->get()));
    }

    public function test_un_fallo_del_servidor_se_registra_sin_romper(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);

        $r = app(CentroCorreo::class)->enviar(CartaFiniquitoMail::ejemplo(), 'cliente@empresa.mx');

        $this->assertSame(CorreoEnviado::FALLIDO, $r->estado);
        $this->assertNotEmpty($r->error);
    }

    public function test_varios_destinatarios_sin_repetir(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $r = app(CentroCorreo::class)->enviarA(AvisoCambioPlanMail::ejemplo(), ['a@x.mx', 'A@x.mx ', 'b@x.mx', null, '']);

        $this->assertCount(2, $r);
        Mail::assertSent(AvisoCambioPlanMail::class, 2);
    }

    public function test_panel_registro_y_vista_previa_de_todas_las_plantillas(): void
    {
        $direccion = $this->operador('dir', 'direccion');
        $this->withHeaders($this->como($this->operador('vend', 'vendedor')))->getJson('/api/correos')->assertForbidden();

        config(['mail.default' => 'log']);
        app(CentroCorreo::class)->enviar(NuevaPasswordOperadorMail::ejemplo(), 'op@kernia.test');
        $this->withHeaders($this->como($direccion))->getJson('/api/correos')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.estado', 'omitido')
            ->assertJsonPath('data.0.plantilla_nombre', 'Contraseña de acceso de un operador');

        $plantillas = $this->withHeaders($this->como($direccion))->getJson('/api/correos/plantillas')->assertOk()->json('data');
        $this->assertCount(6, $plantillas);
        foreach ($plantillas as $p) {
            $html = $this->withHeaders($this->como($direccion))->get("/api/correos/plantillas/{$p['clave']}/vista-previa")->assertOk()->getContent();
            $this->assertStringContainsString('Kernia', $html, $p['clave']);
        }
        $this->withHeaders($this->como($direccion))->get('/api/correos/plantillas/inexistente/vista-previa')->assertNotFound();
    }

    public function test_autorizar_una_baja_avisa_al_cliente_al_asesor_y_a_direccion(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $vendedor = $this->operador('vend', 'vendedor');
        $this->operador('dir', 'direccion');
        $gerente = $this->operador('ger', 'gerente');
        NivelAutorizacion::create(['nivel' => 1, 'puesto' => 'Gerencia', 'usuario_id' => $gerente->id, 'dias_max' => 3]);

        $p = Producto::create(['slug' => 'comercializa', 'nombre' => 'Comercializa', 'base_url_interna' => 'http://x',
            'modo_datos' => Producto::MODO_COMPARTIDA, 'token_interno' => 't']);
        foreach (['ventas', 'tesoreria'] as $m) {
            ProductoModulo::create(['producto_id' => $p->id, 'clave' => $m, 'nombre' => ucfirst($m)]);
        }
        ProductoPlan::create(['producto_id' => $p->id, 'codigo' => 'basico', 'nombre' => 'Básico', 'orden' => 1, 'modulos' => ['ventas'], 'limites' => ['max_empresas' => 4]]);
        ProductoPlan::create(['producto_id' => $p->id, 'codigo' => 'profesional', 'nombre' => 'Profesional', 'orden' => 2, 'modulos' => ['ventas', 'tesoreria'], 'limites' => ['max_empresas' => 8]]);

        $suscribir = function (string $slug, string $tipo) use ($p, $vendedor) {
            $c = Cliente::create(['slug' => $slug, 'nombre' => strtoupper($slug), 'estatus' => 'activo', 'tipo' => $tipo]);
            $c->operadores()->attach($vendedor->id);
            $s = Suscripcion::create(['cliente_id' => $c->id, 'producto_id' => $p->id, 'estatus' => 'activo', 'admin_email' => "admin@{$slug}.mx",
                'modalidad_pago' => 'anual', 'fecha_proximo_pago' => VigenciaService::hoy()->addDays(20)->toDateString()]);
            app(SuscripcionPlanService::class)->aplicarPlan($s, 'profesional');

            return $s;
        };
        $bajar = function (Suscripcion $s) use ($vendedor, $gerente) {
            $id = $this->withHeaders($this->como($vendedor))->postJson("/api/suscripciones/{$s->id}/cambios-plan",
                ['plan' => 'basico', 'aplicacion' => 'renovacion', 'motivo_salida' => 'precio', 'motivo' => 'Reduce operación'])->json('data.id');

            return $this->withHeaders($this->como($vendedor))->postJson("/api/cambios-plan/{$id}/resolver",
                ['accion' => 'autorizar', 'email' => $gerente->email, 'password' => self::PW]);
        };

        $bajar($suscribir('acme', 'comercial'))->assertOk()->assertJsonPath('correos.enviado', 3);
        foreach (['admin@acme.mx', 'vend@kernia.test', 'dir@kernia.test'] as $destino) {
            Mail::assertSent(AvisoCambioPlanMail::class, fn ($m) => $m->hasTo($destino) && $m->planNuevo === 'Básico' && $m->pierde === ['Tesoreria']);
        }
        $this->assertSame(3, CorreoEnviado::where('referencia', 'like', 'solicitud_plan:%')->count());

        // Un cliente de demo no recibe avisos
        $bajar($suscribir('demo-x', 'demo'))->assertOk();
        $this->assertSame(3, CorreoEnviado::count());
    }
}
