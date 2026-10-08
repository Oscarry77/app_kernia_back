<?php

namespace Tests\Feature\Panel;

use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\Producto;
use App\Models\Landlord\ProductoExtra;
use App\Models\Landlord\ProductoModulo;
use App\Models\Landlord\ProductoPlan;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\SuscripcionPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Catálogo de productos, planes y extras editable desde el panel (02-oct-2026). */
class CatalogoTest extends TestCase
{
    use RefreshDatabase;

    private ?array $sesion = null;
    private Producto $com;
    private Producto $svi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->com = Producto::create(['slug' => 'comercializa', 'nombre' => 'Comercializa', 'base_url_interna' => 'http://x',
            'modo_datos' => Producto::MODO_DEDICADA, 'token_interno' => 't1', 'permite_ws_cntpaq' => true]);
        $this->svi = Producto::create(['slug' => 'svi', 'nombre' => 'SVI', 'base_url_interna' => 'http://y',
            'modo_datos' => Producto::MODO_DEDICADA, 'token_interno' => 't2', 'permite_ws_cntpaq' => false]);
        foreach (['ventas' => null, 'compras' => null, 'tesoreria' => null, 'viaticos' => ['tesoreria']] as $clave => $requiere) {
            ProductoModulo::create(['producto_id' => $this->com->id, 'clave' => $clave, 'nombre' => ucfirst($clave), 'requiere' => $requiere]);
        }
        ProductoPlan::create(['producto_id' => $this->com->id, 'codigo' => 'basico', 'nombre' => 'Básico', 'orden' => 1,
            'modulos' => ['ventas', 'compras'], 'limites' => ['max_empresas' => 4]]);
        ProductoExtra::create(['producto_id' => $this->com->id, 'codigo' => 'empresa_adicional', 'nombre' => 'Empresa adicional',
            'limite' => 'max_empresas', 'incremento' => 1]);
    }

    private function auth(): array
    {
        return $this->sesion ??= ['Authorization' => 'Bearer '.auth('api')->login(
            LandlordAdmin::create(['nombre' => 'Op', 'email' => 'op@kernia.test', 'rol' => 'superadmin', 'password' => 'Op-123456789!', 'activo' => true])
        )];
    }

    private function suscripcion(string $slug = 'acme'): Suscripcion
    {
        $c = Cliente::create(['slug' => $slug, 'nombre' => strtoupper($slug), 'estatus' => 'activo']);
        $s = Suscripcion::create(['cliente_id' => $c->id, 'producto_id' => $this->com->id, 'estatus' => 'activo']);
        app(SuscripcionPlanService::class)->aplicarPlan($s, 'basico');

        return $s->fresh();
    }

    public function test_editar_producto_no_expone_ni_cambia_datos_tecnicos(): void
    {
        $r = $this->withHeaders($this->auth())->putJson('/api/catalogo/productos/comercializa', [
            'nombre' => 'Comercializa', 'nombre_corto' => 'com', 'descripcion' => 'BackOffice', 'permite_ws_cntpaq' => true,
            'token_interno' => 'hackeo', 'base_url_interna' => 'http://otro',
        ])->assertOk()->assertJsonPath('data.nombre_corto', 'COM');

        $this->assertStringNotContainsString('t1', $r->getContent());
        $this->assertSame('http://x', $this->com->fresh()->base_url_interna);
        $this->assertSame('catalogo.producto_editado', Auditoria::latest('id')->value('accion'));
    }

    public function test_un_plan_no_puede_romper_la_dependencia_entre_modulos(): void
    {
        $this->withHeaders($this->auth())->postJson('/api/catalogo/productos/comercializa/planes', [
            'codigo' => 'viajes', 'nombre' => 'Viajes', 'modulos' => ['ventas', 'viaticos'], 'limites' => ['max_empresas' => 2],
        ])->assertStatus(422)->assertJsonPath('message', 'Viaticos requiere Tesoreria en el mismo plan.');

        $this->withHeaders($this->auth())->postJson('/api/catalogo/productos/comercializa/planes', [
            'codigo' => 'corporativo', 'nombre' => 'Corporativo', 'modulos' => ['ventas', 'tesoreria', 'viaticos'], 'limites' => ['max_empresas' => 10],
        ])->assertCreated();

        $this->withHeaders($this->auth())->postJson('/api/catalogo/productos/comercializa/planes', [
            'codigo' => 'raro', 'nombre' => 'Raro', 'modulos' => ['ventas'], 'limites' => ['max_galaxias' => 1],
        ])->assertStatus(422);
    }

    public function test_cambiar_modulos_de_un_plan_actualiza_a_sus_clientes(): void
    {
        $a = $this->suscripcion('acme');
        $b = $this->suscripcion('beta');

        $this->withHeaders($this->auth())->putJson('/api/catalogo/productos/comercializa/planes/basico', [
            'nombre' => 'Básico', 'modulos' => ['ventas', 'compras', 'tesoreria'], 'limites' => ['max_empresas' => 5], 'activo' => true,
        ])->assertOk()->assertJsonPath('clientes_afectados', 2);

        foreach ([$a, $b] as $s) {
            $this->assertSame(['ventas', 'compras', 'tesoreria'], $s->fresh()->clavesModulosActivos());
            $this->assertSame(['max_empresas' => 5], app(SuscripcionPlanService::class)->limitesEfectivos($s->fresh()));
        }
    }

    /** 05-oct-2026: con clientes, el catálogo no puede quitar módulos ni reducir límites (afectaría a todos sin autorización). */
    public function test_plan_con_clientes_no_pierde_modulos_ni_reduce_limites(): void
    {
        $s = $this->suscripcion();
        $editar = fn (array $modulos, array $limites) => $this->withHeaders($this->auth())->putJson('/api/catalogo/productos/comercializa/planes/basico', [
            'nombre' => 'Básico', 'modulos' => $modulos, 'limites' => $limites, 'activo' => true,
        ]);

        $editar(['ventas'], ['max_empresas' => 4])->assertStatus(422)
            ->assertJsonPath('message', 'El plan Básico tiene 1 cliente: no se puede guardar porque quita Compras. Crea un plan nuevo y cambia a cada cliente con una solicitud de cambio de plan.');
        $editar(['ventas', 'compras'], ['max_empresas' => 3])->assertStatus(422);
        $this->assertSame(['ventas', 'compras'], $s->fresh()->clavesModulosActivos());
        $this->assertSame(['max_empresas' => 4], app(SuscripcionPlanService::class)->limitesEfectivos($s->fresh()));

        // Ampliar sí: quitar la clave es "sin límite"
        $editar(['ventas', 'compras'], [])->assertOk();
        // Descripción comercial, sin tocar lo demás
        $this->withHeaders($this->auth())->putJson('/api/catalogo/productos/comercializa/planes/basico', [
            'nombre' => 'Básico', 'descripcion' => 'Para empezar', 'modulos' => ['ventas', 'compras'], 'limites' => [], 'activo' => true,
        ])->assertOk()->assertJsonPath('data.planes.0.descripcion', 'Para empezar');

        // Sin clientes, el plan se edita libremente
        ProductoPlan::create(['producto_id' => $this->com->id, 'codigo' => 'vacio', 'nombre' => 'Vacío', 'modulos' => ['ventas', 'compras'], 'limites' => ['max_empresas' => 9]]);
        $this->withHeaders($this->auth())->putJson('/api/catalogo/productos/comercializa/planes/vacio', [
            'nombre' => 'Vacío', 'modulos' => ['ventas'], 'limites' => ['max_empresas' => 1], 'activo' => true,
        ])->assertOk();
    }

    public function test_desactivar_plan_o_extra_no_le_quita_nada_a_quien_ya_lo_tiene(): void
    {
        $s = $this->suscripcion();
        $planes = app(SuscripcionPlanService::class);
        $planes->agregarExtra($s, 'empresa_adicional', 2);

        $this->withHeaders($this->auth())->putJson('/api/catalogo/productos/comercializa/planes/basico', [
            'nombre' => 'Básico', 'modulos' => ['ventas', 'compras'], 'limites' => ['max_empresas' => 4], 'activo' => false,
        ])->assertOk();
        $this->withHeaders($this->auth())->putJson('/api/catalogo/productos/comercializa/extras/empresa_adicional', [
            'nombre' => 'Empresa adicional', 'limite' => 'max_empresas', 'incremento' => 1, 'activo' => false,
        ])->assertOk();

        // Sigue rigiendo: 4 + 2 adicionales
        $this->assertSame(['max_empresas' => 6], $planes->limitesEfectivos($s->fresh()));
        $this->assertSame(['ventas', 'compras'], $s->fresh()->clavesModulosActivos());

        // Pero ya no se asigna ni se vende a nadie más
        $nueva = Suscripcion::create(['cliente_id' => Cliente::create(['slug' => 'nuevo', 'nombre' => 'N', 'estatus' => 'activo'])->id,
            'producto_id' => $this->com->id, 'estatus' => 'activo']);
        $this->expectException(\RuntimeException::class);
        $planes->aplicarPlan($nueva, 'basico');
    }

    public function test_ws_cntpaq_solo_en_apps_que_lo_permiten(): void
    {
        $s = $this->suscripcion();
        $this->withHeaders($this->auth())->patchJson("/api/suscripciones/{$s->id}/ws-cntpaq", ['habilitado' => true])
            ->assertOk()->assertJsonPath('data.ws_cntpaq_habilitado', true);

        $c = Cliente::create(['slug' => 'svi-cli', 'nombre' => 'S', 'estatus' => 'activo']);
        $svi = Suscripcion::create(['cliente_id' => $c->id, 'producto_id' => $this->svi->id, 'estatus' => 'activo']);
        $this->withHeaders($this->auth())->patchJson("/api/suscripciones/{$svi->id}/ws-cntpaq", ['habilitado' => true])->assertStatus(422);
    }

    public function test_catalogo_completo_incluye_inactivos_y_conteo_de_clientes(): void
    {
        $this->suscripcion();
        ProductoPlan::create(['producto_id' => $this->com->id, 'codigo' => 'viejo', 'nombre' => 'Viejo', 'orden' => 9,
            'modulos' => ['ventas'], 'limites' => ['max_empresas' => 1], 'activo' => false]);

        $this->withHeaders($this->auth())->getJson('/api/catalogo/productos')->assertJsonCount(1, 'data.0.planes');
        $this->withHeaders($this->auth())->getJson('/api/catalogo/productos?completo=1')
            ->assertJsonCount(2, 'data.0.planes')
            ->assertJsonPath('data.0.planes.0.clientes', 1)
            ->assertJsonPath('limites_disponibles', ['max_empresas', 'max_empleados', 'max_usuarios']);
    }
}
