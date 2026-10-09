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
use App\Models\Landlord\Suscripcion;
use App\Services\Boveda\BovedaService;
use App\Services\Landlord\VigenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/** Finiquito con exportación v2.3 orquestada por Kernia (09-oct-2026). */
class ExportacionFiniquitoTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Clave-de-prueba-123!';

    private Suscripcion $s;
    private LandlordAdmin $vendedor;

    /** Lo que contesta la "app" al polling, por intento: processing | ready | failed. */
    private array $respuestas = [];
    private ?string $descargada = null;
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $this->vendedor = LandlordAdmin::create(['nombre' => 'Vend', 'email' => 'vend@kernia.test', 'rol' => 'vendedor', 'password' => self::PW, 'activo' => true]);
        $ger = LandlordAdmin::create(['nombre' => 'Ger', 'email' => 'ger@kernia.test', 'rol' => 'gerente', 'password' => self::PW, 'activo' => true]);
        LandlordAdmin::create(['nombre' => 'Dir', 'email' => 'dir@kernia.test', 'rol' => 'direccion', 'password' => self::PW, 'activo' => true]);
        NivelAutorizacion::create(['nivel' => 1, 'puesto' => 'Gerencia', 'usuario_id' => $ger->id, 'dias_max' => 3]);

        $p = Producto::create(['slug' => 'svi', 'nombre' => 'Bridge SVI', 'base_url_interna' => 'http://svi.test', 'modo_datos' => Producto::MODO_DEDICADA,
            'token_interno' => 't', 'estatus_salida' => true, 'exportacion_v23' => true]);
        $c = Cliente::create(['slug' => 'acme', 'nombre' => 'ACME', 'estatus' => 'activo']);
        $c->operadores()->attach($this->vendedor->id);
        $this->s = Suscripcion::create(['cliente_id' => $c->id, 'producto_id' => $p->id, 'estatus' => 'activo', 'admin_email' => 'admin@acme.test',
            'modalidad_pago' => 'anual', 'fecha_proximo_pago' => VigenciaService::hoy()->addDays(200)->toDateString()]);

        Http::fake(function (Request $r) {
            $url = $r->url();
            if (str_ends_with($url, '/clientes/acme/exportaciones') && $r->method() === 'POST') {
                $this->posts[] = ['key' => $r->header('Idempotency-Key')[0] ?? null, 'body' => $r->data()];

                return Http::response(['exportacion_id' => 'exp-'.count($this->posts), 'status' => 'processing'], 202);
            }
            if (preg_match('#/exportaciones/(exp-\d+)$#', $url, $m)) {
                $estado = array_shift($this->respuestas) ?? 'processing';

                return Http::response(['status' => $estado, 'tamano_bytes' => 2048, 'sha256_7z' => str_repeat('a', 64),
                    'conteos' => ['datos/avisos.csv' => 12, 'datos/sujetos_obligados.csv' => 3], 'descargada_en' => $this->descargada,
                    'error' => $estado === 'failed' ? 'proceso_interrumpido' : null]);
            }

            return Http::response('', 204); // estatus
        });
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

    /** Solicita y autoriza el finiquito; el corte de mañana lo aplica. */
    private function finiquitar(): void
    {
        $id = $this->withHeaders($this->como($this->vendedor))->postJson("/api/suscripciones/{$this->s->id}/salidas", [
            'tipo' => 'finiquito', 'motivo_salida' => 'cierre_negocio', 'motivo' => 'Cierra operaciones', 'conformidad_tipo' => 'correo',
            'conformidad_referencia' => 'Correo del 08/10', 'confirmacion_slug' => 'acme',
        ])->assertCreated()->json('data.id');
        $this->withHeaders($this->como($this->vendedor))->postJson("/api/salidas/{$id}/resolver",
            ['accion' => 'autorizar', 'email' => 'ger@kernia.test', 'password' => self::PW])->assertOk();

        Carbon::setTestNow(now()->addDay());
        Artisan::call('landlord:procesar-vencimientos');
    }

    public function test_finiquito_exporta_manda_carta_y_clave_aparte_recuerda_y_finiquita(): void
    {
        $this->respuestas = ['processing', 'ready'];
        $this->finiquitar();

        // Al aplicarse: en_finiquito y la exportación pedida con la contraseña de la bóveda
        $exp = Exportacion::sole();
        $this->assertSame([Suscripcion::ESTATUS_EN_FINIQUITO, 'processing', 'exp-1'], [$this->s->fresh()->estatus, $exp->estado, $exp->exportacion_app]);
        $this->assertSame("exportacion-{$exp->id}-1", $this->posts[0]['key']);
        $clave = app(BovedaService::class)->leer($exp->claveBoveda());
        $this->assertSame(24, strlen($clave));
        $this->assertSame($clave, $this->posts[0]['body']['clave_respaldo']);
        $this->assertSame(['cliente', 'finiquito'], [$this->posts[0]['body']['alcance'], $this->posts[0]['body']['motivo']]);
        $this->assertSame(BovedaSecreto::TIPO_RESPALDO, BovedaSecreto::sole()->tipo);

        Artisan::call('landlord:orquestar-exportaciones'); // processing
        $this->assertNull(CorreoEnviado::where('plantilla', 'carta_finiquito')->first());
        Artisan::call('landlord:orquestar-exportaciones'); // ready → correos

        $exp->refresh();
        $this->assertSame(['ready', 2048, str_repeat('a', 64)], [$exp->estado, $exp->tamano_bytes, $exp->sha256]);
        $this->assertNotNull($exp->carta_enviada_en);
        $this->assertNotNull($exp->clave_enviada_en);
        // Carta al administrador, al asesor y a Dirección; la contraseña, solo al administrador y sin guardarla
        $this->assertEqualsCanonicalizing(['admin@acme.test', 'vend@kernia.test', 'dir@kernia.test'],
            CorreoEnviado::where('plantilla', 'carta_finiquito')->pluck('destinatario')->all());
        $clave = CorreoEnviado::where('plantilla', 'clave_respaldo')->sole();
        $this->assertSame(['admin@acme.test', true], [$clave->destinatario, (bool) $clave->contiene_secreto]);
        $this->assertTrue(Auditoria::where('accion', 'exportacion.lista')->exists());

        // Recordatorios a 7 y 2 días del plazo de 15
        Carbon::setTestNow(now()->addDays(8));  // faltan 7
        Artisan::call('landlord:orquestar-exportaciones', ['--diarias' => true]);
        Carbon::setTestNow(now()->addDays(1));  // faltan 6: mismo tramo, nada
        Artisan::call('landlord:orquestar-exportaciones', ['--diarias' => true]);
        $this->assertSame([7], $exp->fresh()->recordatorios);

        // Descargó: ya no hay recordatorio de 2 días
        $this->descargada = now()->toIso8601String();
        Carbon::setTestNow(now()->addDays(4));  // faltan 2
        Artisan::call('landlord:orquestar-exportaciones', ['--diarias' => true]);
        $this->assertSame([7], $exp->fresh()->recordatorios);
        $this->assertNotNull($exp->fresh()->descargada_en);

        // Vence el plazo: finiquitado, con retención de 90 días
        Carbon::setTestNow(now()->addDays(3));
        Artisan::call('landlord:orquestar-exportaciones', ['--diarias' => true]);
        $this->assertSame(Suscripcion::ESTATUS_FINIQUITADO, $this->s->fresh()->estatus);
        $this->assertSame($exp->disponible_hasta->copy()->addDays(90)->toDateString(), $exp->fresh()->retencion_hasta->toDateString());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/estatus') && $r['estatus'] === 'finiquitado');
    }

    public function test_si_la_app_falla_reintenta_con_clave_y_llave_nuevas_hasta_tres_veces(): void
    {
        $this->respuestas = ['failed', 'failed', 'failed'];
        $this->finiquitar();
        $exp = Exportacion::sole();
        $primera = app(BovedaService::class)->leer($exp->claveBoveda());

        Artisan::call('landlord:orquestar-exportaciones'); // falla 1 → intento 2
        $exp->refresh();
        $this->assertSame([2, 'processing'], [$exp->intento, $exp->estado]);
        $this->assertSame("exportacion-{$exp->id}-2", $this->posts[1]['key']);
        $this->assertNotSame($primera, $this->posts[1]['body']['clave_respaldo']);

        Artisan::call('landlord:orquestar-exportaciones'); // falla 2 → intento 3
        Artisan::call('landlord:orquestar-exportaciones'); // falla 3 → se rinde
        $exp->refresh();
        $this->assertSame(['failed', 3], [$exp->estado, $exp->intento]);
        $this->assertStringContainsString('proceso_interrumpido', $exp->error);
        $this->assertTrue(Auditoria::where('accion', 'exportacion.fallida')->exists());
        $this->assertNull(CorreoEnviado::where('plantilla', 'clave_respaldo')->first());

        // Sin exportación lista, el plazo no lo pasa a finiquitado
        Carbon::setTestNow(now()->addDays(20));
        Artisan::call('landlord:orquestar-exportaciones', ['--diarias' => true]);
        $this->assertSame(Suscripcion::ESTATUS_EN_FINIQUITO, $this->s->fresh()->estatus);
    }

    public function test_sin_correo_disponible_la_clave_no_sale_antes_que_la_carta(): void
    {
        $this->respuestas = ['ready'];
        $this->finiquitar();
        config(['mail.default' => 'kernia']); // bóveda sin buzón: correo no disponible
        Artisan::call('landlord:orquestar-exportaciones');

        $exp = Exportacion::sole();
        $this->assertNull($exp->carta_enviada_en);
        $this->assertNull($exp->clave_enviada_en);
        $this->assertNull(CorreoEnviado::where('plantilla', 'clave_respaldo')->first());

        config(['mail.default' => 'smtp']);
        Artisan::call('landlord:orquestar-exportaciones');
        $this->assertNotNull($exp->fresh()->clave_enviada_en);
    }
}
