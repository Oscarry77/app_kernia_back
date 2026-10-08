<?php

namespace Tests\Feature\Panel;

use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\NivelAutorizacion;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Prorroga;
use App\Models\Landlord\SolicitudPlan;
use App\Models\Landlord\SolicitudSalida;
use App\Models\Landlord\SolicitudSalidaIntento;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\VigenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/** Estados de salida: retirar, reactivar y finiquitar con el escalafón (08-oct-2026). */
class SalidaTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Clave-de-prueba-123!';

    private LandlordAdmin $super;
    private LandlordAdmin $vendedor;
    private LandlordAdmin $gerente;
    private LandlordAdmin $soporte;
    private Producto $svi;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response('', 204)]);

        $this->super = $this->operador('super', 'superadmin');
        $this->vendedor = $this->operador('vend', 'vendedor');
        $this->gerente = $this->operador('ger', 'gerente');
        $this->soporte = $this->operador('sop', 'soporte');
        NivelAutorizacion::create(['nivel' => 1, 'puesto' => 'Gerencia', 'usuario_id' => $this->gerente->id, 'dias_max' => 3]);

        $this->svi = Producto::create(['slug' => 'svi', 'nombre' => 'Bridge SVI', 'base_url_interna' => 'http://svi.test',
            'modo_datos' => Producto::MODO_DEDICADA, 'token_interno' => 't', 'estatus_salida' => true]);
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

    private function suscripcion(string $estatus = 'activo'): Suscripcion
    {
        $c = Cliente::create(['slug' => 'acme', 'nombre' => 'ACME', 'estatus' => 'activo']);
        $c->operadores()->attach($this->vendedor->id);

        return Suscripcion::create(['cliente_id' => $c->id, 'producto_id' => $this->svi->id, 'estatus' => $estatus, 'admin_email' => 'admin@acme.test',
            'modalidad_pago' => 'anual', 'fecha_proximo_pago' => VigenciaService::hoy()->addDays(40)->toDateString()]);
    }

    private function solicitar(Suscripcion $s, array $datos)
    {
        return $this->withHeaders($this->como($this->vendedor))->postJson("/api/suscripciones/{$s->id}/salidas", $datos);
    }

    private function resolver(int $id, string $email, string $accion = 'autorizar', ?string $comentario = null, string $pw = self::PW)
    {
        return $this->withHeaders($this->como($this->vendedor))->postJson("/api/salidas/{$id}/resolver",
            ['accion' => $accion, 'email' => $email, 'password' => $pw, 'comentario' => $comentario]);
    }

    private function corte(string $dias = '+1 day'): void
    {
        Carbon::setTestNow(now()->modify($dias));
        Artisan::call('landlord:procesar-vencimientos');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_retiro_se_programa_y_lo_aplica_el_corte(): void
    {
        $s = $this->suscripcion();
        SolicitudPlan::create(['suscripcion_id' => $s->id, 'plan_actual' => null, 'plan_nuevo' => 'x', 'direccion' => 'bajada',
            'aplicacion' => 'renovacion', 'motivo' => 'x', 'estado' => 'programada', 'fecha_efectiva' => $s->fecha_proximo_pago]);

        $id = $this->solicitar($s, ['tipo' => 'retiro', 'motivo' => 'El cliente ya no usará SVI'])
            ->assertCreated()->assertJsonPath('data.estado', 'solicitada')->json('data.id');

        // Una sola solicitud abierta; el vendedor no se autoriza a sí mismo
        $this->solicitar($s, ['tipo' => 'retiro', 'motivo' => 'otra'])->assertStatus(422);
        $this->resolver($id, 'vend@kernia.test')->assertStatus(422);

        $this->resolver($id, 'ger@kernia.test')->assertOk()
            ->assertJsonPath('aplicada', false)->assertJsonPath('data.estado', 'programada')
            ->assertJsonPath('data.fecha_efectiva', VigenciaService::hoy()->addDay()->toDateString())
            ->assertJsonPath('suscripcion.estatus', 'activo');

        // Hasta el corte, el cliente opera y ve el aviso
        $this->assertSame('retiro', app(VigenciaService::class)->aviso($s->fresh())['tipo']);

        $this->corte();
        $s->refresh();
        $this->assertSame(Suscripcion::ESTATUS_RETIRADO, $s->estatus);
        $this->assertSame('aplicada', SolicitudSalida::find($id)->estado);
        $this->assertSame('cancelada', SolicitudPlan::first()->estado);
        $this->assertNull(app(VigenciaService::class)->aviso($s));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/clientes/acme/estatus') && $r['estatus'] === 'retirado');
        $this->assertTrue(Auditoria::where('accion', 'suscripcion.retiro')->exists());
        $this->assertSame(['autoriza_propia', 'autorizada'], SolicitudSalidaIntento::orderBy('id')->pluck('resultado')->all());
    }

    public function test_reactivacion_se_aplica_al_autorizar_y_el_estatus_no_se_mueve_por_fuera(): void
    {
        $s = $this->suscripcion('retirado');

        // Ni el panel ni el comando reactivan sin solicitud
        $this->withHeaders($this->como($this->super))->patchJson("/api/suscripciones/{$s->id}/estatus", ['estatus' => 'activo', 'motivo' => 'x'])
            ->assertStatus(422);
        $this->assertSame(1, Artisan::call('landlord:cambiar-estatus-suscripcion', ['cliente_slug' => 'acme', 'producto_slug' => 'svi', 'estatus' => 'activo']));
        // Desde retirado no se pide otro retiro
        $this->solicitar($s, ['tipo' => 'retiro', 'motivo' => 'x'])->assertStatus(422);

        $id = $this->solicitar($s, ['tipo' => 'reactivacion', 'motivo' => 'Regresa el cliente'])->assertCreated()->json('data.id');
        $this->resolver($id, 'ger@kernia.test')->assertOk()
            ->assertJsonPath('aplicada', true)->assertJsonPath('data.estado', 'aplicada')->assertJsonPath('suscripcion.estatus', 'activo');
    }

    public function test_finiquito_exige_conformidad_y_slug_y_abre_el_plazo_de_descarga(): void
    {
        $s = $this->suscripcion('suspendido');
        Prorroga::create(['suscripcion_id' => $s->id, 'fecha_vencimiento' => now()->toDateString(), 'dias' => 2, 'motivo' => 'otro', 'estado' => 'solicitada']);

        $base = ['tipo' => 'finiquito', 'motivo' => 'Cierre de la empresa'];
        $this->solicitar($s, $base)->assertStatus(422);
        $this->solicitar($s, [...$base, 'conformidad_tipo' => 'correo', 'conformidad_referencia' => 'Correo del 07/10', 'confirmacion_slug' => 'otro'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'acme'));

        $id = $this->solicitar($s, [...$base, 'conformidad_tipo' => 'correo', 'conformidad_referencia' => 'Correo del 07/10', 'confirmacion_slug' => 'ACME'])
            ->assertCreated()->assertJsonPath('data.conformidad_tipo', 'correo')->json('data.id');

        $this->resolver($id, 'ger@kernia.test', 'rechazar')->assertStatus(422); // sin motivo de rechazo
        $this->resolver($id, 'ger@kernia.test')->assertOk()->assertJsonPath('data.estado', 'programada');

        $this->corte();
        $s->refresh();
        $this->assertSame(Suscripcion::ESTATUS_EN_FINIQUITO, $s->estatus);
        $this->assertSame(VigenciaService::hoy()->addDays(15)->toDateString(), $s->descarga_hasta->toDateString());
        $this->assertSame('cancelada', Prorroga::first()->estado);

        $aviso = app(VigenciaService::class)->aviso($s);
        $this->assertSame(['critico', 'finiquito'], [$aviso['nivel'], $aviso['tipo']]);

        // Un pago ya no reactiva una suscripción en finiquito
        $this->withHeaders($this->como($this->super))->postJson("/api/suscripciones/{$s->id}/pagos",
            ['fecha_pago' => now()->toDateString(), 'referencia' => 'X1'])->assertStatus(422)->assertJsonMissingPath('errors');
    }

    public function test_restablecer_admin_se_permite_en_finiquito_y_no_en_retirado(): void
    {
        $s = $this->suscripcion('en_finiquito');
        $this->withHeaders($this->como($this->soporte))->postJson("/api/suscripciones/{$s->id}/restablecer-admin")
            ->assertOk()->assertJsonPath('email', 'admin@acme.test');

        $s->update(['estatus' => 'retirado']);
        $this->withHeaders($this->como($this->soporte))->postJson("/api/suscripciones/{$s->id}/restablecer-admin")->assertStatus(422);
    }

    public function test_app_que_no_reconoce_los_estados_no_se_retira_ni_finiquita(): void
    {
        $this->svi->update(['estatus_salida' => false]);
        $s = $this->suscripcion();

        $this->withHeaders($this->como($this->vendedor))->getJson("/api/clientes/{$s->cliente_id}")
            ->assertOk()
            ->assertJsonPath('data.suscripciones.0.salidas_posibles.retiro.permitido', false)
            ->assertJsonPath('data.suscripciones.0.salidas_posibles.finiquito.permitido', false);
        $this->solicitar($s, ['tipo' => 'retiro', 'motivo' => 'x'])->assertStatus(422);

        // Si se apaga entre la autorización y el corte, la salida falla sin mandar nada a la app
        $this->svi->update(['estatus_salida' => true]);
        $id = $this->solicitar($s, ['tipo' => 'retiro', 'motivo' => 'x'])->assertCreated()->json('data.id');
        $this->resolver($id, 'ger@kernia.test')->assertOk();
        Artisan::call('landlord:estatus-salida', ['producto_slug' => 'svi', '--apagar' => true]);
        $this->corte();

        $this->assertSame('fallida', SolicitudSalida::find($id)->estado);
        $this->assertSame('activo', $s->fresh()->estatus);
        Http::assertNothingSent();
    }

    public function test_soporte_no_solicita_y_el_vendedor_no_ve_salidas_fuera_de_su_cartera(): void
    {
        $s = $this->suscripcion();
        $this->withHeaders($this->como($this->soporte))->postJson("/api/suscripciones/{$s->id}/salidas", ['tipo' => 'retiro', 'motivo' => 'x'])
            ->assertStatus(403);

        $id = $this->solicitar($s, ['tipo' => 'retiro', 'motivo' => 'x'])->json('data.id');
        $this->withHeaders($this->como($this->gerente))->getJson('/api/salidas/pendientes')->assertOk()->assertJsonCount(1, 'data');

        $otro = $this->operador('otro', 'vendedor');
        $this->withHeaders($this->como($otro))->getJson('/api/salidas/pendientes')->assertOk()->assertJsonCount(0, 'data');
        $this->withHeaders($this->como($otro))->postJson("/api/salidas/{$id}/cancelar", ['motivo' => 'x'])->assertNotFound();

        $this->withHeaders($this->como($this->vendedor))->postJson("/api/salidas/{$id}/cancelar", ['motivo' => 'Se arrepintió'])
            ->assertOk()->assertJsonPath('data.estado', 'cancelada');
    }
}
