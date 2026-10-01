<?php

namespace Tests\Feature\Landlord;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use App\Models\Landlord\ProductoExtra;
use App\Models\Landlord\ProductoModulo;
use App\Models\Landlord\ProductoPlan;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\SuscripcionOnboardingService;
use App\Services\Landlord\SuscripcionPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Estándar v2.1 (01-oct-2026): plan = edición (módulos + límites base) + extras. */
class SuscripcionPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->producto = Producto::create([
            'slug' => 'comercializa', 'nombre' => 'Comercializa', 'base_url_interna' => 'http://127.0.0.1:8100',
            'modo_datos' => Producto::MODO_COMPARTIDA, 'token_interno' => 'token-com',
        ]);
        foreach (['ventas', 'compras', 'inventarios', 'tesoreria', 'viaticos'] as $clave) {
            ProductoModulo::create(['producto_id' => $this->producto->id, 'clave' => $clave, 'nombre' => ucfirst($clave)]);
        }
        $planes = [
            'basico' => [['ventas', 'compras', 'inventarios'], 4],
            'profesional' => [['ventas', 'compras', 'inventarios', 'tesoreria'], 8],
            'corporativo' => [['ventas', 'compras', 'inventarios', 'tesoreria', 'viaticos'], 10],
        ];
        foreach ($planes as $codigo => [$modulos, $max]) {
            ProductoPlan::create(['producto_id' => $this->producto->id, 'codigo' => $codigo, 'nombre' => ucfirst($codigo),
                'modulos' => $modulos, 'limites' => ['max_empresas' => $max]]);
        }
        ProductoExtra::create(['producto_id' => $this->producto->id, 'codigo' => 'empresa_adicional',
            'nombre' => 'Empresa adicional', 'limite' => 'max_empresas', 'incremento' => 1]);
    }

    private function suscripcion(): Suscripcion
    {
        $cliente = Cliente::create(['slug' => 'acme', 'nombre' => 'Acme', 'estatus' => Cliente::ESTATUS_ACTIVO]);

        return Suscripcion::create(['cliente_id' => $cliente->id, 'producto_id' => $this->producto->id, 'estatus' => Suscripcion::ESTATUS_ACTIVO]);
    }

    public function test_el_plan_dicta_los_modulos_y_bajar_de_plan_no_borra_filas(): void
    {
        $s = $this->suscripcion();
        $servicio = app(SuscripcionPlanService::class);

        $servicio->aplicarPlan($s, 'corporativo');
        $this->assertSame(['ventas', 'compras', 'inventarios', 'tesoreria', 'viaticos'], $s->fresh()->clavesModulosActivos());

        $servicio->aplicarPlan($s->fresh(), 'basico');
        $this->assertSame(['ventas', 'compras', 'inventarios'], $s->fresh()->clavesModulosActivos());
        $this->assertSame(5, $s->modulos()->count(), 'Los módulos retirados quedan inactivos, no se borran.');
    }

    public function test_limite_efectivo_es_base_del_plan_mas_extras(): void
    {
        $s = $this->suscripcion();
        $servicio = app(SuscripcionPlanService::class);
        $servicio->aplicarPlan($s, 'basico');

        $this->assertSame(['max_empresas' => 4], $servicio->limitesEfectivos($s->fresh()));

        $servicio->agregarExtra($s, 'empresa_adicional', 3, 'pago 123', 'ventas');
        $servicio->agregarExtra($s, 'empresa_adicional', -1, 'ajuste', 'ventas');
        $this->assertSame(['max_empresas' => 6], $servicio->limitesEfectivos($s->fresh()));

        $servicio->aplicarPlan($s->fresh(), 'corporativo');
        $this->assertSame(['max_empresas' => 12], $servicio->limitesEfectivos($s->fresh()), 'Los extras se conservan al cambiar de plan.');
        $this->assertSame(2, $s->extras()->count(), 'Cada movimiento queda en bitácora.');
    }

    public function test_no_se_retiran_mas_extras_de_los_contratados(): void
    {
        $s = $this->suscripcion();
        $servicio = app(SuscripcionPlanService::class);
        $servicio->aplicarPlan($s, 'basico');
        $servicio->agregarExtra($s, 'empresa_adicional', 1);

        $this->expectException(RuntimeException::class);
        $servicio->agregarExtra($s, 'empresa_adicional', -2);
    }

    public function test_plan_inexistente_se_rechaza(): void
    {
        $this->expectException(RuntimeException::class);
        app(SuscripcionPlanService::class)->aplicarPlan($this->suscripcion(), 'premium');
    }

    public function test_resolve_envia_modulos_y_limites_del_plan(): void
    {
        $s = $this->suscripcion();
        $servicio = app(SuscripcionPlanService::class);
        $servicio->aplicarPlan($s, 'profesional');
        $servicio->agregarExtra($s, 'empresa_adicional', 2);

        $this->withHeaders(['X-Internal-Token' => 'token-com'])
            ->get('/internal/v1/productos/comercializa/resolve/acme')
            ->assertOk()
            ->assertJsonPath('suscripcion.plan', 'profesional')
            ->assertJsonPath('modulos', ['ventas', 'compras', 'inventarios', 'tesoreria'])
            ->assertJsonPath('limites.max_empresas', 10);
    }

    public function test_alta_de_producto_con_planes_exige_plan_valido_antes_de_llamar_a_la_app(): void
    {
        Http::fake();
        $cliente = Cliente::create(['slug' => 'nuevo', 'nombre' => 'Nuevo', 'estatus' => Cliente::ESTATUS_ACTIVO]);

        try {
            app(SuscripcionOnboardingService::class)->aprovisionar($cliente, $this->producto, ['nombre' => 'A', 'email' => 'a@nuevo.test'], 'premium');
            $this->fail('Debió rechazar el plan.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('requiere un plan válido', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, Suscripcion::where('cliente_id', $cliente->id)->count());
    }

    public function test_alta_con_plan_valido_deja_los_modulos_del_plan(): void
    {
        Http::fake(['*/api/internal/v1/provision' => Http::response(['ref_externa' => null, 'status' => 'ready'], 201)]);
        $cliente = Cliente::create(['slug' => 'nuevo', 'nombre' => 'Nuevo', 'estatus' => Cliente::ESTATUS_ACTIVO]);

        $s = app(SuscripcionOnboardingService::class)->aprovisionar($cliente, $this->producto, ['nombre' => 'A', 'email' => 'a@nuevo.test'], 'basico');

        $this->assertSame('basico', $s->plan);
        $this->assertSame(['ventas', 'compras', 'inventarios'], $s->clavesModulosActivos());
    }
}
