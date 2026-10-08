<?php

namespace Tests\Feature\Panel;

use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\NivelAutorizacion;
use App\Models\Landlord\Producto;
use App\Models\Landlord\ProductoExtra;
use App\Models\Landlord\ProductoModulo;
use App\Models\Landlord\ProductoPlan;
use App\Models\Landlord\SolicitudPlan;
use App\Models\Landlord\SolicitudPlanIntento;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\SuscripcionPlanService;
use App\Services\Landlord\VigenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/** Cambio de plan por solicitud con autorización del escalafón (05-oct-2026). */
class CambioPlanTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Clave-de-prueba-123!';

    private LandlordAdmin $super;
    private LandlordAdmin $vendedor;
    private LandlordAdmin $gerente;
    private Producto $com;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = $this->operador('super', 'superadmin');
        $this->vendedor = $this->operador('vend', 'vendedor');
        $this->gerente = $this->operador('ger', 'gerente');
        NivelAutorizacion::create(['nivel' => 1, 'puesto' => 'Gerencia', 'usuario_id' => $this->gerente->id, 'dias_max' => 3]);

        $this->com = Producto::create(['slug' => 'comercializa', 'nombre' => 'Comercializa', 'base_url_interna' => 'http://x',
            'modo_datos' => Producto::MODO_COMPARTIDA, 'token_interno' => 't']);
        foreach (['ventas' => null, 'compras' => null, 'inventarios' => null, 'tesoreria' => null, 'viaticos' => ['tesoreria']] as $clave => $req) {
            ProductoModulo::create(['producto_id' => $this->com->id, 'clave' => $clave, 'nombre' => ucfirst($clave), 'requiere' => $req]);
        }
        $planes = [
            ['basico', 'Backoffice Básico', ['ventas', 'compras', 'inventarios'], 4],
            ['profesional', 'Backoffice Profesional', ['ventas', 'compras', 'inventarios', 'tesoreria'], 8],
            ['corporativo', 'Backoffice Corporativo', ['ventas', 'compras', 'inventarios', 'tesoreria', 'viaticos'], 10],
        ];
        foreach ($planes as $i => [$codigo, $nombre, $modulos, $max]) {
            ProductoPlan::create(['producto_id' => $this->com->id, 'codigo' => $codigo, 'nombre' => $nombre, 'orden' => $i + 1,
                'modulos' => $modulos, 'limites' => ['max_empresas' => $max]]);
        }
        ProductoExtra::create(['producto_id' => $this->com->id, 'codigo' => 'empresa_adicional', 'nombre' => 'Empresa adicional',
            'limite' => 'max_empresas', 'incremento' => 1]);
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

    /** Suscripción de un cliente en la cartera del vendedor. */
    private function suscripcion(string $plan, ?string $proximoPago = '+40 days'): Suscripcion
    {
        $c = Cliente::create(['slug' => 'acme', 'nombre' => 'ACME', 'estatus' => 'activo']);
        $c->operadores()->attach($this->vendedor->id);
        $s = Suscripcion::create(['cliente_id' => $c->id, 'producto_id' => $this->com->id, 'estatus' => 'activo', 'modalidad_pago' => 'anual',
            'fecha_proximo_pago' => $proximoPago ? VigenciaService::hoy()->modify($proximoPago)->toDateString() : null]);
        app(SuscripcionPlanService::class)->aplicarPlan($s, $plan);

        return $s->fresh();
    }

    private function resolver(SolicitudPlan|int $sol, LandlordAdmin $sesion, string $email, string $accion = 'autorizar', ?string $comentario = null, string $pw = self::PW)
    {
        $id = $sol instanceof SolicitudPlan ? $sol->id : $sol;

        return $this->withHeaders($this->como($sesion))->postJson("/api/cambios-plan/{$id}/resolver",
            ['accion' => $accion, 'email' => $email, 'password' => $pw, 'comentario' => $comentario]);
    }

    public function test_vista_previa_dice_que_cambia(): void
    {
        $s = $this->suscripcion('corporativo');
        app(SuscripcionPlanService::class)->agregarExtra($s, 'empresa_adicional', 1);

        $this->withHeaders($this->como($this->vendedor))->getJson("/api/suscripciones/{$s->id}/plan/vista-previa?plan=basico")
            ->assertOk()
            ->assertJsonPath('data.direccion', 'bajada')
            ->assertJsonPath('data.modulos_pierde', ['tesoreria', 'viaticos'])
            ->assertJsonPath('data.modulos_gana', [])
            ->assertJsonPath('data.limites_antes.max_empresas', 11)
            ->assertJsonPath('data.limites_despues.max_empresas', 5)
            ->assertJsonPath('data.limites_reducidos', ['max_empresas']);

        $this->withHeaders($this->como($this->vendedor))->getJson("/api/suscripciones/{$s->id}/plan/vista-previa?plan=corporativo")
            ->assertStatus(422);
    }

    public function test_subida_se_solicita_y_se_aplica_al_autorizar(): void
    {
        $s = $this->suscripcion('basico');

        // Pedir "en la renovación" no aplica a una subida: siempre es inmediata
        $id = $this->withHeaders($this->como($this->vendedor))->postJson("/api/suscripciones/{$s->id}/cambios-plan",
            ['plan' => 'profesional', 'aplicacion' => 'renovacion', 'motivo' => 'Contrató Tesorería'])
            ->assertCreated()->assertJsonPath('data.direccion', 'subida')->assertJsonPath('data.aplicacion', 'inmediata')
            ->json('data.id');

        // Mientras no se autoriza, nada cambia; y no se abre una segunda solicitud
        $this->assertSame('basico', $s->fresh()->plan);
        $this->withHeaders($this->como($this->vendedor))->postJson("/api/suscripciones/{$s->id}/cambios-plan",
            ['plan' => 'corporativo', 'motivo' => 'x'])->assertStatus(422);

        // El vendedor no puede autorizar su propia solicitud ni tiene nivel
        $this->resolver($id, $this->vendedor, 'vend@kernia.test')->assertStatus(422);
        // Contraseña equivocada: queda registrada
        $this->resolver($id, $this->vendedor, 'ger@kernia.test', pw: 'mala')->assertStatus(422);

        $this->resolver($id, $this->vendedor, 'ger@kernia.test')->assertOk()
            ->assertJsonPath('aplicada', true)->assertJsonPath('data.estado', 'aplicada')
            ->assertJsonPath('suscripcion.plan', 'profesional')->assertJsonPath('suscripcion.limites.max_empresas', 8);

        $this->assertSame(['ventas', 'compras', 'inventarios', 'tesoreria'], $s->fresh()->clavesModulosActivos());
        $this->assertSame(['autoriza_propia', 'credencial_invalida', 'autorizada'],
            SolicitudPlanIntento::orderBy('id')->pluck('resultado')->all());
        $this->assertTrue(Auditoria::where('accion', 'suscripcion.plan_cambiado')->exists());
    }

    public function test_bajada_se_programa_a_la_renovacion_y_la_aplica_el_corte(): void
    {
        $s = $this->suscripcion('corporativo', '+10 days');

        $id = $this->withHeaders($this->como($this->vendedor))->postJson("/api/suscripciones/{$s->id}/cambios-plan",
            ['plan' => 'basico', 'aplicacion' => 'renovacion', 'motivo' => 'Reduce operación'])
            ->assertCreated()->assertJsonPath('data.direccion', 'bajada')->json('data.id');

        $this->resolver($id, $this->vendedor, 'ger@kernia.test')->assertOk()
            ->assertJsonPath('aplicada', false)->assertJsonPath('data.estado', 'programada')
            ->assertJsonPath('data.fecha_efectiva', $s->fecha_proximo_pago->toDateString());

        // El detalle muestra el cambio programado; el plan sigue igual
        $this->withHeaders($this->como($this->vendedor))->getJson("/api/clientes/{$s->cliente_id}")
            ->assertJsonPath('data.suscripciones.0.plan', 'corporativo')
            ->assertJsonPath('data.suscripciones.0.cambio_plan.estado', 'programada');

        // El corte antes de la fecha no hace nada
        $this->artisan('landlord:procesar-vencimientos')->assertSuccessful();
        $this->assertSame('corporativo', $s->fresh()->plan);

        // Llega la fecha: el corte la aplica (aunque se haya pagado y recorrido la fecha de próximo pago)
        $s->update(['fecha_proximo_pago' => VigenciaService::hoy()->addYear()->toDateString()]);
        SolicitudPlan::find($id)->update(['fecha_efectiva' => VigenciaService::hoy()->toDateString()]);
        $this->artisan('landlord:procesar-vencimientos')->assertSuccessful();

        $this->assertSame('basico', $s->fresh()->plan);
        $this->assertSame('aplicada', SolicitudPlan::find($id)->estado);
        $this->assertSame(['ventas', 'compras', 'inventarios'], $s->fresh()->clavesModulosActivos());
    }

    public function test_bajada_inmediata_y_reglas_de_la_baja(): void
    {
        $s = $this->suscripcion('profesional', null);

        // Sin fecha de próximo pago, la baja no puede esperar a la renovación
        $this->withHeaders($this->como($this->vendedor))->postJson("/api/suscripciones/{$s->id}/cambios-plan",
            ['plan' => 'basico', 'aplicacion' => 'renovacion', 'motivo' => 'x'])
            ->assertStatus(422)->assertJsonPath('message', 'Esta suscripción no tiene fecha de próximo pago: la baja solo puede aplicarse en el siguiente corte de las 00:00.');

        $id = $this->withHeaders($this->como($this->vendedor))->postJson("/api/suscripciones/{$s->id}/cambios-plan",
            ['plan' => 'basico', 'aplicacion' => 'inmediata', 'motivo' => 'Ajuste'])->assertCreated()->json('data.id');

        // "Inmediata" en una baja = el siguiente corte de las 00:00, nunca a media jornada (dueño, 05-oct)
        $this->resolver($id, $this->vendedor, 'ger@kernia.test')->assertOk()
            ->assertJsonPath('aplicada', false)->assertJsonPath('data.fecha_efectiva', VigenciaService::hoy()->addDay()->toDateString());
        $this->assertSame('profesional', $s->fresh()->plan);

        // La app del cliente muestra el aviso del cambio programado
        $aviso = app(VigenciaService::class)->aviso($s->fresh());
        $this->assertSame('cambio_plan', $aviso['tipo']);
        $this->assertStringContainsString('Backoffice Básico', $aviso['mensaje']);

        $this->travel(1)->days();
        $this->artisan('landlord:procesar-vencimientos')->assertSuccessful();
        $this->assertSame('basico', $s->fresh()->plan);
        $this->assertNull(app(VigenciaService::class)->aviso($s->fresh()));
    }

    public function test_rechazo_devuelve_el_resumen_de_pagos_y_cancelar(): void
    {
        $s = $this->suscripcion('corporativo');

        $id = $this->withHeaders($this->como($this->vendedor))->postJson("/api/suscripciones/{$s->id}/cambios-plan",
            ['plan' => 'basico', 'motivo' => 'x'])->json('data.id');

        // Pendientes, con el resumen de pagos para quien resuelve
        $this->withHeaders($this->como($this->gerente))->getJson('/api/cambios-plan/pendientes')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.pagos.modalidad_pago', 'anual');

        $this->resolver($id, $this->gerente, 'ger@kernia.test', 'rechazar')->assertStatus(422); // sin motivo
        $this->resolver($id, $this->gerente, 'ger@kernia.test', 'rechazar', 'Primero hay que negociar')->assertOk()
            ->assertJsonPath('data.estado', 'rechazada')->assertJsonPath('pagos.pagos_registrados', 0);
        $this->assertSame('corporativo', $s->fresh()->plan);

        // Una nueva solicitud se puede cancelar
        $nueva = $this->withHeaders($this->como($this->vendedor))->postJson("/api/suscripciones/{$s->id}/cambios-plan",
            ['plan' => 'profesional', 'motivo' => 'x'])->json('data.id');
        $this->withHeaders($this->como($this->vendedor))->postJson("/api/cambios-plan/{$nueva}/cancelar", ['motivo' => 'El cliente se arrepintió'])
            ->assertOk()->assertJsonPath('data.estado', 'cancelada');
    }

    public function test_sin_escalafon_solo_el_superadmin_y_el_cambio_directo_es_solo_suyo(): void
    {
        NivelAutorizacion::query()->delete();
        $s = $this->suscripcion('basico');
        $id = $this->withHeaders($this->como($this->vendedor))->postJson("/api/suscripciones/{$s->id}/cambios-plan",
            ['plan' => 'profesional', 'motivo' => 'x'])->json('data.id');

        $this->resolver($id, $this->vendedor, 'ger@kernia.test')->assertStatus(422)
            ->assertJsonPath('message', 'Aún no hay escalafón capturado: solo el superadministrador puede resolver.');
        $this->resolver($id, $this->vendedor, 'super@kernia.test')->assertOk()->assertJsonPath('aplicada', true);

        // Cambio directo (sin solicitud): ni el gerente
        $this->withHeaders($this->como($this->gerente))->patchJson("/api/suscripciones/{$s->id}/plan", ['plan' => 'basico'])->assertForbidden();
        $this->withHeaders($this->como($this->super))->patchJson("/api/suscripciones/{$s->id}/plan", ['plan' => 'basico'])->assertOk();
    }

    public function test_soporte_no_solicita_y_un_vendedor_no_ve_clientes_ajenos(): void
    {
        $s = $this->suscripcion('basico');
        $otro = $this->operador('otro', 'vendedor');

        $this->withHeaders($this->como($this->operador('sop', 'soporte')))->postJson("/api/suscripciones/{$s->id}/cambios-plan",
            ['plan' => 'profesional', 'motivo' => 'x'])->assertForbidden();
        $this->withHeaders($this->como($otro))->postJson("/api/suscripciones/{$s->id}/cambios-plan",
            ['plan' => 'profesional', 'motivo' => 'x'])->assertNotFound();
    }
}
