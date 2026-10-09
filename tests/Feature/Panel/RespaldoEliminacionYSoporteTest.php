<?php

namespace Tests\Feature\Panel;

use App\Models\Landlord\Auditoria;
use App\Models\Landlord\BovedaSecreto;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\CorreoEnviado;
use App\Models\Landlord\Exportacion;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\NivelAutorizacion;
use App\Models\Landlord\Producto;
use App\Models\Landlord\SolicitudRespaldo;
use App\Models\Landlord\Suscripcion;
use App\Services\Boveda\BovedaService;
use App\Services\Landlord\EliminadorBases;
use App\Services\Landlord\VigenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/** Finiquito, 2.ª parte: eliminación al terminar la retención y soporte sobre el respaldo (09-oct-2026). */
class RespaldoEliminacionYSoporteTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Clave-de-prueba-123!';

    private LandlordAdmin $soporte;
    private LandlordAdmin $gerente;
    private Producto $producto;
    private Suscripcion $s;
    private Exportacion $exp;
    private string $eliminar = 'ready';
    /** @var list<string> */
    private array $llamadas = [];
    private int $basesBorradas = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $this->soporte = LandlordAdmin::create(['nombre' => 'Sop', 'email' => 'sop@kernia.test', 'rol' => 'soporte', 'password' => self::PW, 'activo' => true]);
        $this->gerente = LandlordAdmin::create(['nombre' => 'Ger', 'email' => 'ger@kernia.test', 'rol' => 'gerente', 'password' => self::PW, 'activo' => true]);
        NivelAutorizacion::create(['nivel' => 1, 'puesto' => 'Gerencia', 'usuario_id' => $this->gerente->id, 'dias_max' => 3]);

        $this->producto = Producto::create(['slug' => 'hrm', 'nombre' => 'Bridge HRM', 'base_url_interna' => 'http://hrm.test',
            'modo_datos' => Producto::MODO_COMPARTIDA, 'token_interno' => 't', 'estatus_salida' => true, 'exportacion_v23' => true]);
        $c = Cliente::create(['slug' => 'acme', 'nombre' => 'ACME', 'estatus' => 'activo']);
        $this->s = Suscripcion::create(['cliente_id' => $c->id, 'producto_id' => $this->producto->id, 'estatus' => 'finiquitado',
            'admin_email' => 'admin@acme.test', 'descarga_hasta' => VigenciaService::hoy()->subDays(1)->toDateString()]);

        // Un finiquito ya en retención: respaldo listo y su contraseña en la bóveda
        $this->exp = Exportacion::create(['suscripcion_id' => $this->s->id, 'motivo' => 'finiquito', 'alcance' => 'cliente', 'intento' => 1,
            'exportacion_app' => 'exp-9', 'estado' => 'ready', 'sha256' => str_repeat('b', 64), 'tamano_bytes' => 10,
            'disponible_hasta' => VigenciaService::hoy()->subDays(1)->toDateString(),
            'retencion_hasta' => VigenciaService::hoy()->addDays(89)->toDateString()]);
        app(BovedaService::class)->guardar($this->exp->claveBoveda(), 'Clave-Respaldo-De-Prueba-24', ['tipo' => BovedaSecreto::TIPO_RESPALDO,
            'cliente_id' => $c->id, 'suscripcion_id' => $this->s->id]);

        Http::fake(function (Request $r) {
            $this->llamadas[] = $r->method().' '.parse_url($r->url(), PHP_URL_PATH);
            $url = $r->url();

            return match (true) {
                str_ends_with($url, '/exportaciones/exp-9/archivo') => Http::response('7z-cifrado-binario', 200, ['Content-Type' => 'application/x-7z-compressed']),
                $r->method() === 'DELETE' => Http::response('', 204),
                str_ends_with($url, '/finiquito/eliminar') && $r->method() === 'POST' => Http::response(['status' => 'processing'], 202),
                str_ends_with($url, '/finiquito/eliminar') => Http::response(['status' => $this->eliminar]),
                default => Http::response('', 204),
            };
        });

        $this->app->instance(EliminadorBases::class, new class($this) extends EliminadorBases {
            public function __construct(private $prueba) {}

            public function eliminar(Suscripcion $s): array
            {
                $this->prueba->contarBaseBorrada();

                return ['base' => 'svi_acme', 'usuarios' => ['tn_sviacme_app']];
            }
        });
    }

    public function contarBaseBorrada(): void
    {
        $this->basesBorradas++;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function como(LandlordAdmin $a): array
    {
        $this->app['auth']->forgetGuards();
        JWTAuth::unsetToken();
        $this->app['tymon.jwt']->unsetToken();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($a)];
    }

    private function vueltas(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            Artisan::call('landlord:orquestar-exportaciones');
        }
    }

    public function test_antes_de_terminar_la_retencion_no_se_borra_nada(): void
    {
        $this->vueltas(2);
        $this->assertSame([], $this->llamadas);
        $this->assertNull($this->exp->fresh()->eliminacion);
    }

    public function test_al_terminar_la_retencion_se_elimina_en_orden_con_constancia(): void
    {
        Carbon::setTestNow(now()->addDays(90));
        $this->eliminar = 'processing';
        $this->vueltas(3); // borra exportación → pide eliminar → sigue procesando
        $this->assertSame(Exportacion::ELIM_APP_ELIMINANDO, $this->exp->fresh()->eliminacion);
        $this->assertSame('finiquitado', $this->s->fresh()->estatus);

        $this->eliminar = 'ready';
        $this->vueltas(2);
        $exp = $this->exp->fresh();
        $this->assertSame([Exportacion::ELIM_COMPLETA, 'eliminado'], [$exp->eliminacion, $this->s->fresh()->estatus]);
        $this->assertSame(['DELETE /api/internal/v1/clientes/acme/exportaciones/exp-9', 'POST /api/internal/v1/clientes/acme/finiquito/eliminar'],
            array_values(array_unique(array_filter($this->llamadas, fn ($l) => ! str_starts_with($l, 'GET')))));
        $this->assertSame(0, $this->basesBorradas); // patrón B: la base la borra la app
        $this->assertNull(app(BovedaService::class)->leer($exp->claveBoveda()));
        $constancia = Auditoria::where('accion', 'suscripcion.eliminada')->sole();
        $this->assertSame(str_repeat('b', 64), $constancia->despues['sha256']);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/estatus')); // `eliminado` no se envía a la app
    }

    public function test_en_el_patron_a_kernia_borra_la_base_despues_de_la_app(): void
    {
        $this->producto->update(['modo_datos' => Producto::MODO_DEDICADA]);
        Carbon::setTestNow(now()->addDays(90));
        $this->vueltas(4);
        $this->assertSame(1, $this->basesBorradas);
        $this->assertTrue(Auditoria::where('accion', 'suscripcion.base_eliminada')->exists());
        $this->assertSame('eliminado', $this->s->fresh()->estatus);
    }

    public function test_si_la_app_falla_al_eliminar_se_reintenta_sin_avanzar(): void
    {
        Carbon::setTestNow(now()->addDays(90));
        $this->eliminar = 'failed';
        $this->vueltas(4);
        $this->assertSame(Exportacion::ELIM_APP_ELIMINANDO, $this->exp->fresh()->eliminacion);
        $this->assertStringContainsString('no pudo eliminar', $this->exp->fresh()->error);
        $this->assertSame('finiquitado', $this->s->fresh()->estatus);
    }

    public function test_reenvio_de_la_contrasena_con_escalafon(): void
    {
        $id = $this->withHeaders($this->como($this->soporte))->postJson("/api/exportaciones/{$this->exp->id}/solicitudes",
            ['tipo' => 'reenvio_clave', 'motivo' => 'El cliente borró el correo'])->assertCreated()->json('data.id');

        // Soporte no tiene nivel en el escalafón; nadie autoriza lo suyo
        $this->withHeaders($this->como($this->soporte))->postJson("/api/respaldos/{$id}/resolver",
            ['accion' => 'autorizar', 'email' => 'sop@kernia.test', 'password' => self::PW])->assertStatus(422);
        $this->assertNull(CorreoEnviado::where('plantilla', 'clave_respaldo')->first());

        $this->withHeaders($this->como($this->soporte))->postJson("/api/respaldos/{$id}/resolver",
            ['accion' => 'autorizar', 'email' => 'ger@kernia.test', 'password' => self::PW])->assertOk()->assertJsonPath('data.estado', 'aplicada');
        $this->assertSame('admin@acme.test', CorreoEnviado::where('plantilla', 'clave_respaldo')->sole()->destinatario);
        $this->assertTrue(Auditoria::where('accion', 'respaldo.clave_reenviada')->exists());
    }

    public function test_entrega_a_soporte_solo_a_quien_la_pidio_una_vez_y_en_24_horas(): void
    {
        $id = $this->withHeaders($this->como($this->soporte))->postJson("/api/exportaciones/{$this->exp->id}/solicitudes",
            ['tipo' => 'entrega_soporte', 'motivo' => 'El cliente perdió su archivo'])->assertCreated()->json('data.id');

        // Antes de autorizar no se descarga
        $this->withHeaders($this->como($this->soporte))->get("/api/respaldos/{$id}/archivo")->assertStatus(422);

        $this->withHeaders($this->como($this->soporte))->postJson("/api/respaldos/{$id}/resolver",
            ['accion' => 'autorizar', 'email' => 'ger@kernia.test', 'password' => self::PW])->assertOk()->assertJsonPath('data.estado', 'autorizada');

        // Otro operador (aunque autorizó) no lo descarga
        $this->withHeaders($this->como($this->gerente))->get("/api/respaldos/{$id}/archivo")->assertStatus(422);

        $r = $this->withHeaders($this->como($this->soporte))->get("/api/respaldos/{$id}/archivo")->assertOk();
        $this->assertSame('7z-cifrado-binario', $r->streamedContent());
        $this->assertSame(str_repeat('b', 64), $r->headers->get('X-Huella-SHA256'));
        $this->assertSame('aplicada', SolicitudRespaldo::find($id)->estado);
        $this->assertTrue(Auditoria::where('accion', 'respaldo.entregado_a_soporte')->exists());

        // Una sola vez
        $this->withHeaders($this->como($this->soporte))->get("/api/respaldos/{$id}/archivo")->assertStatus(422);

        // Una entrega nueva que no se descarga en 24 h vence
        $id2 = $this->withHeaders($this->como($this->soporte))->postJson("/api/exportaciones/{$this->exp->id}/solicitudes",
            ['tipo' => 'entrega_soporte', 'motivo' => 'Otra vez'])->json('data.id');
        $this->withHeaders($this->como($this->soporte))->postJson("/api/respaldos/{$id2}/resolver",
            ['accion' => 'autorizar', 'email' => 'ger@kernia.test', 'password' => self::PW]);
        Carbon::setTestNow(now()->addHours(25));
        $this->withHeaders($this->como($this->soporte))->get("/api/respaldos/{$id2}/archivo")->assertStatus(422)
            ->assertJsonPath('message', 'Venció la ventana de 24 horas; solicita de nuevo.');
    }

    public function test_tras_la_eliminacion_no_hay_soporte_posible(): void
    {
        $this->exp->update(['eliminacion' => Exportacion::ELIM_COMPLETA]);
        $this->withHeaders($this->como($this->soporte))->postJson("/api/exportaciones/{$this->exp->id}/solicitudes",
            ['tipo' => 'reenvio_clave', 'motivo' => 'x'])->assertStatus(422);
    }
}
