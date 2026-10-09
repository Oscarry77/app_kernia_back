<?php

namespace Tests\Feature\Panel;

use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\CorreoEnviado;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\NivelAutorizacion;
use App\Models\Landlord\Producto;
use App\Models\Landlord\ProductoPlan;
use App\Models\Landlord\SolicitudPlan;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\SuscripcionPlanService;
use App\Services\Landlord\VigenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/** Orquestación de la baja de plan con una app v2.2 (09-oct-2026). */
class BajaPlanOrquestacionTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Clave-de-prueba-123!';

    private LandlordAdmin $vendedor;
    private LandlordAdmin $gerente;
    private Producto $hrm;
    private Suscripcion $s;

    /** Empresas que "tiene" la app y lo que contesta el ajuste; los fakes las leen y modifican. */
    private array $empresas = [];
    private string $ajuste = 'ready';
    private int $ajusteStatus = 202;
    private array $metricas = ['ok' => false];

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $this->vendedor = $this->operador('vend', 'vendedor');
        $this->gerente = $this->operador('ger', 'gerente');
        NivelAutorizacion::create(['nivel' => 1, 'puesto' => 'Gerencia', 'usuario_id' => $this->gerente->id, 'dias_max' => 3]);

        $this->hrm = Producto::create(['slug' => 'hrm', 'nombre' => 'Bridge HRM', 'base_url_interna' => 'http://hrm.test',
            'modo_datos' => Producto::MODO_COMPARTIDA, 'token_interno' => 't', 'empresas_v22' => true]);
        foreach ([['basico', 1], ['estandar', 3], ['profesional', 5]] as $i => [$codigo, $max]) {
            ProductoPlan::create(['producto_id' => $this->hrm->id, 'codigo' => $codigo, 'nombre' => ucfirst($codigo), 'orden' => $i + 1,
                'modulos' => [], 'limites' => ['max_empresas' => $max]]);
        }

        $c = Cliente::create(['slug' => 'acme', 'nombre' => 'ACME', 'estatus' => 'activo']);
        $c->operadores()->attach($this->vendedor->id);
        $this->s = Suscripcion::create(['cliente_id' => $c->id, 'producto_id' => $this->hrm->id, 'estatus' => 'activo', 'admin_email' => 'admin@acme.test',
            'modalidad_pago' => 'anual', 'fecha_proximo_pago' => VigenciaService::hoy()->addDays(40)->toDateString()]);
        app(SuscripcionPlanService::class)->aplicarPlan($this->s, 'estandar');

        $this->empresas = [
            ['id' => 11, 'rfc' => 'AAA010101AA1', 'nombre' => 'AAA', 'estado' => 'activa', 'creada_en' => '2026-01-01'],
            ['id' => 12, 'rfc' => 'AAB010101AA1', 'nombre' => 'AAB', 'estado' => 'activa', 'creada_en' => '2026-01-01'],
            ['id' => 13, 'rfc' => 'AAC010101AA1', 'nombre' => 'AAC', 'estado' => 'inactiva', 'creada_en' => '2026-01-01'],
        ];
        $this->fakeApp();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeApp(): void
    {
        Http::fake(function (Request $r) {
            $url = $r->url();
            $cuentan = fn () => count(array_filter($this->empresas, fn ($e) => in_array($e['estado'], ['activa', 'inactiva'], true)));

            return match (true) {
                str_ends_with($url, '/clientes/acme/empresas') && $r->method() === 'GET'
                    => Http::response(['data' => $this->empresas, 'cuentan_para_limite' => $cuentan(), 'max_empresas' => 3]),
                str_ends_with($url, '/clientes/acme/estatus') => Http::response('', 204),
                str_ends_with($url, '/metricas/acme') => Http::response($this->metricas),
                str_ends_with($url, '/clientes/acme/ajuste-plan') => Http::response(['status' => 'processing'], $this->ajusteStatus),
                str_contains($url, '/clientes/acme/ajuste-plan/') => Http::response($this->estadoAjuste()),
                str_ends_with($url, '/empresas/desbloquear') => (function () use ($r) {
                    foreach ($this->empresas as &$e) {
                        if (in_array($e['id'], $r['empresas'], true)) {
                            $e['estado'] = 'activa';
                        }
                    }

                    return Http::response('', 204);
                })(),
                default => Http::response(['message' => 'No encontrado.'], 404),
            };
        });
    }

    /** Lo que contesta el polling: con 'ready', bloquea en la "app" todo lo que no se conservó. */
    private function estadoAjuste(): array
    {
        if ($this->ajuste !== 'ready') {
            return ['status' => $this->ajuste, 'respaldo_id' => null, 'empresas_bloqueadas' => [], 'error' => $this->ajuste === 'failed' ? 'respaldo_fallido' : null];
        }
        $conservar = SolicitudPlan::where('estado', SolicitudPlan::EN_EJECUCION)->first()?->empresas_conservar ?? [];
        $bloqueadas = [];
        foreach ($this->empresas as &$e) {
            if (! in_array($e['id'], $conservar, true) && $e['estado'] !== 'bloqueada_plan') {
                $e['estado'] = 'bloqueada_plan';
                $bloqueadas[] = $e['id'];
            }
        }

        return ['status' => 'ready', 'respaldo_id' => 'resp-1', 'empresas_bloqueadas' => $bloqueadas, 'error' => null];
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

    /** Solicita y autoriza una baja a $plan (inmediata: se aplica en el corte de mañana). */
    private function bajaAutorizada(string $plan, ?array $conservar): SolicitudPlan
    {
        $id = $this->withHeaders($this->como($this->vendedor))->postJson("/api/suscripciones/{$this->s->id}/cambios-plan", [
            'plan' => $plan, 'aplicacion' => 'inmediata', 'motivo_salida' => 'precio', 'motivo' => 'Reduce operación',
            'empresas_conservar' => $conservar,
        ])->assertCreated()->json('data.id');
        $this->withHeaders($this->como($this->vendedor))->postJson("/api/cambios-plan/{$id}/resolver",
            ['accion' => 'autorizar', 'email' => 'ger@kernia.test', 'password' => self::PW])->assertOk();

        return SolicitudPlan::find($id);
    }

    private function aMinuto(int $minutos): void
    {
        Carbon::setTestNow(now()->addMinutes($minutos));
        Artisan::call('landlord:orquestar-bajas');
    }

    public function test_el_panel_ve_las_empresas_y_valida_la_lista_al_solicitar(): void
    {
        $h = $this->withHeaders($this->como($this->vendedor));
        $h->getJson("/api/suscripciones/{$this->s->id}/empresas")->assertOk()
            ->assertJsonPath('cuentan_para_limite', 3)->assertJsonPath('max_empresas_kernia', 3)->assertJsonCount(3, 'data');

        $base = ['plan' => 'basico', 'aplicacion' => 'inmediata', 'motivo_salida' => 'precio', 'motivo' => 'x'];
        $h->postJson("/api/suscripciones/{$this->s->id}/cambios-plan", [...$base, 'empresas_conservar' => [99]])
            ->assertStatus(422)->assertJsonPath('message', 'La empresa 99 no pertenece al cliente.');
        $h->postJson("/api/suscripciones/{$this->s->id}/cambios-plan", [...$base, 'empresas_conservar' => [11, 12]])
            ->assertStatus(422)->assertJsonPath('message', 'Elegiste 2 empresas; el plan nuevo permite 1.');

        // Sin lista se puede solicitar (el cliente aún no decide) y capturarla después
        $id = $h->postJson("/api/suscripciones/{$this->s->id}/cambios-plan", [...$base, 'empresas_conservar' => null])
            ->assertCreated()->assertJsonPath('data.empresas_conservar', null)->json('data.id');
        $h->putJson("/api/cambios-plan/{$id}/empresas", ['empresas_conservar' => [13]])
            ->assertOk()->assertJsonPath('data.empresas_conservar', [13]);
    }

    public function test_baja_que_cabe_se_aplica_directo(): void
    {
        app(SuscripcionPlanService::class)->aplicarPlan($this->s, 'profesional');
        $sol = $this->bajaAutorizada('estandar', null); // 3 empresas caben en estándar (3)

        Carbon::setTestNow(now()->addDay());
        Artisan::call('landlord:procesar-vencimientos');

        $this->assertSame('aplicada', $sol->fresh()->estado);
        $this->assertSame('estandar', $this->s->fresh()->plan);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/estatus') || str_contains($r->url(), 'ajuste-plan'));
    }

    public function test_baja_con_seleccion_recorre_aviso_mantenimiento_ajuste_y_activo(): void
    {
        $sol = $this->bajaAutorizada('basico', [13]); // conserva la inactiva

        Carbon::setTestNow(now()->addDay());
        Artisan::call('landlord:procesar-vencimientos');
        $sol->refresh();
        $this->assertSame(['en_ejecucion', 'aviso'], [$sol->estado, $sol->fase]);
        $this->assertSame('mantenimiento', app(VigenciaService::class)->aviso($this->s->fresh())['tipo']);

        // Antes de 5 minutos no pasa nada; ni se cancela
        $this->aMinuto(2);
        $this->assertSame('aviso', $sol->fresh()->fase);
        $this->withHeaders($this->como($this->vendedor))->postJson("/api/cambios-plan/{$sol->id}/cancelar", ['motivo' => 'x'])->assertStatus(422);

        $this->aMinuto(4);
        $this->assertSame('mantenimiento', $sol->fresh()->fase);
        $this->assertSame('en_mantenimiento', $this->s->fresh()->estatus);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/estatus') && $r['estatus'] === 'en_mantenimiento');

        $this->aMinuto(1);
        $this->assertSame('ajustando', $sol->fresh()->fase);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/ajuste-plan')
            && $r->hasHeader('Idempotency-Key', "solicitud-plan-{$sol->id}")
            && $r['motivo'] === 'seleccion' && $r['empresas_conservar'] === [13] && $r['max_empresas'] === 1 && $r['solicitud_id'] === $sol->id);

        $this->aMinuto(1);
        $sol->refresh();
        $this->assertSame('aplicada', $sol->estado);
        $this->assertSame(['resp-1', [11, 12]], [$sol->respaldo_id, $sol->empresas_bloqueadas]);
        $s = $this->s->fresh();
        $this->assertSame(['activo', 'basico'], [$s->estatus, $s->plan]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/estatus') && $r['estatus'] === 'activo');
        $this->assertTrue(CorreoEnviado::where('plantilla', 'plan_ajustado')->where('destinatario', 'admin@acme.test')->exists());
        $this->assertTrue(Auditoria::where('accion', 'suscripcion.plan_cambiado')->exists());
    }

    public function test_sin_lista_se_bloquean_todas(): void
    {
        $sol = $this->bajaAutorizada('basico', null);
        Carbon::setTestNow(now()->addDay());
        Artisan::call('landlord:procesar-vencimientos');
        $this->aMinuto(5);
        $this->aMinuto(1);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/ajuste-plan') && $r['motivo'] === 'sin_seleccion' && $r['empresas_conservar'] === []);
        $this->aMinuto(1);
        $this->assertSame([11, 12, 13], $sol->fresh()->empresas_bloqueadas);
    }

    public function test_si_la_app_falla_regresa_a_activo_con_su_plan(): void
    {
        $this->ajuste = 'failed';
        $sol = $this->bajaAutorizada('basico', [11]);
        Carbon::setTestNow(now()->addDay());
        Artisan::call('landlord:procesar-vencimientos');
        $this->aMinuto(5);
        $this->aMinuto(1);
        $this->aMinuto(1);

        $sol->refresh();
        $this->assertSame('fallida', $sol->estado);
        $this->assertStringContainsString('respaldo_fallido', $sol->error);
        $this->assertSame(['activo', 'estandar'], [$this->s->fresh()->estatus, $this->s->fresh()->plan]);
        $this->assertTrue(Auditoria::where('accion', 'baja_plan.fallida')->exists());
    }

    public function test_desbloquear_pasa_por_la_auditoria_de_licencia(): void
    {
        $this->empresas[0]['estado'] = 'bloqueada_plan';
        $this->empresas[1]['estado'] = 'bloqueada_plan'; // cuentan: 1 (la inactiva) de 3
        $h = $this->withHeaders($this->como($this->vendedor));

        $h->postJson("/api/suscripciones/{$this->s->id}/empresas/desbloquear", ['empresas' => [13], 'motivo' => 'x'])
            ->assertStatus(422)->assertJsonPath('message', 'La empresa 13 no está bloqueada por el plan.');

        // Con plan básico (1 empresa), desbloquear una más rebasa: Kernia no llama a la app
        app(SuscripcionPlanService::class)->aplicarPlan($this->s, 'basico');
        $h->postJson("/api/suscripciones/{$this->s->id}/empresas/desbloquear", ['empresas' => [11], 'motivo' => 'Cliente lo pidió'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Rebasa la licencia: el cliente usa 1 de 1'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/desbloquear'));
        $this->assertTrue(Auditoria::where('accion', 'licencia.desbloqueo_rechazado')->exists());

        // Con estándar (3) sí cabe
        app(SuscripcionPlanService::class)->aplicarPlan($this->s, 'estandar');
        $h->postJson("/api/suscripciones/{$this->s->id}/empresas/desbloquear", ['empresas' => [11, 12], 'motivo' => 'Subió de plan'])
            ->assertOk()->assertJsonPath('cuentan_para_limite', 3);
        $this->assertTrue(Auditoria::where('accion', 'empresas.desbloqueadas')->exists());
    }

    public function test_conciliacion_registra_licencias_excedidas(): void
    {
        $this->metricas = ['ok' => true, 'empresas_activas' => 3, 'empresas_inactivas' => 1];
        Artisan::call('landlord:conciliar-licencias');
        $this->assertSame(4, Auditoria::where('accion', 'licencia.excedida')->sole()->despues['cuentan']);
    }

    public function test_una_app_sin_v22_aplica_la_baja_como_antes_y_no_entrega_empresas(): void
    {
        $this->hrm->update(['empresas_v22' => false]);
        $this->withHeaders($this->como($this->vendedor))->getJson("/api/suscripciones/{$this->s->id}/empresas")->assertStatus(422);

        $sol = $this->bajaAutorizada('basico', [11]); // la lista se ignora sin v2.2
        $this->assertNull($sol->empresas_conservar);
        Carbon::setTestNow(now()->addDay());
        Artisan::call('landlord:procesar-vencimientos');
        $this->assertSame('aplicada', $sol->fresh()->estado);
        Http::assertNothingSent();
    }

    /** (09-oct-2026) Patrón A: Kernia respalda la base ya en mantenimiento y manda `respaldo_id` en `ajuste-plan`. */
    public function test_patron_a_kernia_respalda_antes_del_ajuste_y_manda_el_respaldo(): void
    {
        $this->hrm->update(['modo_datos' => Producto::MODO_DEDICADA]);
        $this->s->update(['db_database' => 'hrm_acme']);
        $respaldos = new class extends \App\Services\Landlord\RespaldoBaseService {
            public int $llamadas = 0;

            public function __construct() {}

            public function crear(Suscripcion $s, string $motivo, ?int $solicitudPlanId = null): \App\Models\Landlord\RespaldoBase
            {
                $this->llamadas++;

                return \App\Models\Landlord\RespaldoBase::create(['suscripcion_id' => $s->id, 'solicitud_plan_id' => $solicitudPlanId, 'motivo' => $motivo,
                    'base' => $s->db_database, 'estado' => 'listo', 'expira_en' => now()->addDays(90)->toDateString()]);
            }
        };
        $this->app->instance(\App\Services\Landlord\RespaldoBaseService::class, $respaldos);

        $sol = $this->bajaAutorizada('basico', [11]);
        Carbon::setTestNow(now()->addDay());
        Artisan::call('landlord:procesar-vencimientos');
        $this->aMinuto(5); // mantenimiento
        $this->aMinuto(1); // respaldo + ajuste-plan
        $this->aMinuto(1); // ready

        $respaldo = \App\Models\Landlord\RespaldoBase::sole();
        $this->assertSame(1, $respaldos->llamadas);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/ajuste-plan') && $r['respaldo_id'] === $respaldo->id);
        $this->assertSame(['aplicada', $respaldo->id], [$sol->fresh()->estado, $sol->fresh()->respaldo_id]);
    }
}
