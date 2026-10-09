<?php

namespace Tests\Feature\Panel;

use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\CorreoEnviado;
use App\Models\Landlord\Exportacion;
use App\Models\Landlord\FormularioSalida;
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

/** Copia a petición y archivo de una empresa (v2.3 casos C y A; 09-oct-2026). */
class CopiaYArchivoEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Clave-de-prueba-123!';

    private LandlordAdmin $vendedor;
    private Suscripcion $s;
    private ?string $descargada = null;
    private string $archivar = 'processing';
    private int $archivarStatus = 202;
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp']);
        Mail::fake();
        Carbon::setTestNow('2026-10-09 10:00:00');

        $this->vendedor = LandlordAdmin::create(['nombre' => 'Vend', 'email' => 'vend@kernia.test', 'rol' => 'vendedor', 'password' => self::PW, 'activo' => true]);
        $ger = LandlordAdmin::create(['nombre' => 'Ger', 'email' => 'ger@kernia.test', 'rol' => 'gerente', 'password' => self::PW, 'activo' => true]);
        NivelAutorizacion::create(['nivel' => 1, 'puesto' => 'Gerencia', 'usuario_id' => $ger->id, 'dias_max' => 3]);

        $p = Producto::create(['slug' => 'hrm', 'nombre' => 'Bridge HRM', 'base_url_interna' => 'http://hrm.test', 'modo_datos' => Producto::MODO_COMPARTIDA,
            'token_interno' => 't', 'estatus_salida' => true, 'exportacion_v23' => true, 'empresas_v22' => true]);
        $c = Cliente::create(['slug' => 'acme', 'nombre' => 'ACME', 'estatus' => 'activo']);
        $c->operadores()->attach($this->vendedor->id);
        $this->s = Suscripcion::create(['cliente_id' => $c->id, 'producto_id' => $p->id, 'estatus' => 'activo', 'admin_email' => 'admin@acme.test',
            'modalidad_pago' => 'anual', 'fecha_proximo_pago' => VigenciaService::hoy()->addDays(300)->toDateString()]);

        Http::fake(function (Request $r) {
            $url = $r->url();

            return match (true) {
                str_ends_with($url, '/clientes/acme/empresas') => Http::response(['data' => [
                    ['id' => 11, 'rfc' => 'AAA010101AA1', 'nombre' => 'AAA', 'estado' => 'activa', 'creada_en' => null],
                    ['id' => 12, 'rfc' => 'BBB010101AA1', 'nombre' => 'BBB', 'estado' => 'inactiva', 'creada_en' => null],
                ], 'cuentan_para_limite' => 2, 'max_empresas' => 3]),
                str_ends_with($url, '/clientes/acme/exportaciones') => (function () use ($r) {
                    $this->posts[] = $r->data();

                    return Http::response(['exportacion_id' => 'exp-'.count($this->posts), 'status' => 'processing'], 202);
                })(),
                (bool) preg_match('#/exportaciones/exp-\d+$#', $url) && $r->method() === 'GET' => Http::response(['status' => 'ready', 'tamano_bytes' => 10,
                    'sha256_7z' => str_repeat('c', 64), 'conteos' => ['datos/empleados.csv' => 5], 'descargada_en' => $this->descargada]),
                str_ends_with($url, '/empresas/12/archivar') && $r->method() === 'POST' => Http::response(['status' => 'processing'], $this->archivarStatus),
                str_ends_with($url, '/empresas/12/archivar') => Http::response(['status' => $this->archivar]),
                default => Http::response('', 204),
            };
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

    private function dia(int $n): void
    {
        Carbon::setTestNow(now()->addDays($n));
        Artisan::call('landlord:procesar-vencimientos');
        Artisan::call('landlord:orquestar-exportaciones');
        Artisan::call('landlord:orquestar-exportaciones', ['--diarias' => true]);
    }

    public function test_copia_una_por_trimestre_con_aviso_y_clave_y_se_borra_al_vencer(): void
    {
        $h = $this->withHeaders($this->como($this->vendedor));
        $h->postJson("/api/suscripciones/{$this->s->id}/copias")->assertCreated();
        $this->assertSame(['cliente', 'copia'], [$this->posts[0]['alcance'], $this->posts[0]['motivo']]);

        // Una en curso / ya usada en el trimestre
        $h->postJson("/api/suscripciones/{$this->s->id}/copias")->assertStatus(422);

        Artisan::call('landlord:orquestar-exportaciones'); // ready → aviso y clave
        $exp = Exportacion::sole();
        $this->assertSame(['admin@acme.test', 'vend@kernia.test'], CorreoEnviado::where('plantilla', 'respaldo_listo')->orderBy('id')->pluck('destinatario')->all());
        $this->assertSame('admin@acme.test', CorreoEnviado::where('plantilla', 'clave_respaldo')->sole()->destinatario);
        $this->assertFalse(CorreoEnviado::where('plantilla', 'carta_finiquito')->exists());
        $this->assertSame('activo', $this->s->fresh()->estatus);

        $this->withHeaders($this->como($this->vendedor))->postJson("/api/suscripciones/{$this->s->id}/copias")
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Ya se usó la copia incluida de este trimestre'));

        // Vence a los 15 días: se borra y se purga la contraseña
        $this->dia(16);
        $exp->refresh();
        $this->assertSame(Exportacion::ELIM_COMPLETA, $exp->eliminacion);
        $this->assertNull(app(BovedaService::class)->leer($exp->claveBoveda()));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/exportaciones/exp-1'));

        // Trimestre nuevo (enero): otra copia incluida
        Carbon::setTestNow('2027-01-05 10:00:00');
        $this->withHeaders($this->como($this->vendedor))->postJson("/api/suscripciones/{$this->s->id}/copias")->assertCreated();
    }

    public function test_archivo_de_empresa_exporta_espera_la_descarga_archiva_y_retiene(): void
    {
        $h = $this->withHeaders($this->como($this->vendedor));
        $base = ['tipo' => 'archivo', 'empresa_id' => 12, 'motivo_salida' => 'ya_no_necesita', 'motivo' => 'La empresa BBB dejó de operar'];
        $h->postJson("/api/suscripciones/{$this->s->id}/salidas", [...$base, 'confirmacion_slug' => 'BBB010101AA1'])->assertStatus(422); // sin conformidad
        $h->postJson("/api/suscripciones/{$this->s->id}/salidas", [...$base, 'conformidad_tipo' => 'correo', 'conformidad_referencia' => 'Correo 08/10',
            'confirmacion_slug' => 'OTRO'])->assertStatus(422); // RFC mal escrito
        $id = $h->postJson("/api/suscripciones/{$this->s->id}/salidas", [...$base, 'conformidad_tipo' => 'correo', 'conformidad_referencia' => 'Correo 08/10',
            'confirmacion_slug' => 'bbb010101aa1'])->assertCreated()->assertJsonPath('data.empresa_nombre', 'BBB')->json('data.id');
        $this->assertSame('archivo', FormularioSalida::sole()->evento);

        $this->withHeaders($this->como($this->vendedor))->postJson("/api/salidas/{$id}/resolver",
            ['accion' => 'autorizar', 'email' => 'ger@kernia.test', 'password' => self::PW])->assertOk();

        $this->dia(1); // 00:00: exporta solo esa empresa; listo → aviso y clave
        $this->assertSame(['empresa', [12], 'archivo'], [$this->posts[0]['alcance'], $this->posts[0]['empresas'], $this->posts[0]['motivo']]);
        $this->assertSame('activo', $this->s->fresh()->estatus);
        $this->assertTrue(CorreoEnviado::where('plantilla', 'respaldo_listo')->where('asunto', 'like', '%BBB%')->exists());
        $exp = Exportacion::sole();
        $this->assertNull($exp->archivo); // no descargó y no venció: aún no se archiva

        // Descarga → se pide archivar → la app lo hace
        $this->descargada = now()->toIso8601String();
        $this->dia(2);
        $this->assertSame(Exportacion::ARCH_ARCHIVANDO, $exp->fresh()->archivo);
        $this->archivar = 'ready';
        Artisan::call('landlord:orquestar-exportaciones');
        $exp->refresh();
        $this->assertSame(Exportacion::ARCH_ARCHIVADA, $exp->archivo);
        $this->assertTrue(Auditoria::where('accion', 'empresa.archivada')->exists());
        $this->assertSame($exp->disponible_hasta->copy()->addDays(90)->toDateString(), $exp->retencion_hasta->toDateString());

        // Al terminar la retención se borra la exportación
        $this->dia(110);
        $this->assertSame(Exportacion::ELIM_COMPLETA, $exp->fresh()->eliminacion);
        $this->assertSame('activo', $this->s->fresh()->estatus);
    }

    public function test_sin_descarga_se_archiva_al_vencer_el_plazo(): void
    {
        $exp = Exportacion::create(['suscripcion_id' => $this->s->id, 'motivo' => 'archivo', 'alcance' => 'empresa', 'empresas' => [12], 'intento' => 1,
            'exportacion_app' => 'exp-1', 'estado' => 'ready', 'sha256' => str_repeat('c', 64), 'clave_enviada_en' => now(), 'carta_enviada_en' => now(),
            'disponible_hasta' => VigenciaService::hoy()->addDays(3)->toDateString()]);

        $this->dia(2);
        $this->assertNull($exp->fresh()->archivo);
        $this->dia(2); // venció
        $this->assertSame(Exportacion::ARCH_ARCHIVANDO, $exp->fresh()->archivo);
    }
}
