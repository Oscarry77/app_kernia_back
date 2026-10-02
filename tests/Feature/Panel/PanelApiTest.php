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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** API del panel kernia-admin para el modelo clientes/suscripciones (02-oct-2026). */
class PanelApiTest extends TestCase
{
    use RefreshDatabase;

    private ?array $sesion = null;

    protected function setUp(): void
    {
        parent::setUp();

        $p = Producto::create(['slug' => 'comercializa', 'nombre' => 'Comercializa', 'base_url_interna' => 'http://127.0.0.1:8100',
            'modo_datos' => Producto::MODO_COMPARTIDA, 'token_interno' => 'token-com']);
        foreach (['ventas', 'compras', 'inventarios', 'tesoreria'] as $k) {
            ProductoModulo::create(['producto_id' => $p->id, 'clave' => $k, 'nombre' => ucfirst($k)]);
        }
        ProductoPlan::create(['producto_id' => $p->id, 'codigo' => 'basico', 'nombre' => 'Básico', 'orden' => 1,
            'modulos' => ['ventas', 'compras', 'inventarios'], 'limites' => ['max_empresas' => 4]]);
        ProductoPlan::create(['producto_id' => $p->id, 'codigo' => 'profesional', 'nombre' => 'Profesional', 'orden' => 2,
            'modulos' => ['ventas', 'compras', 'inventarios', 'tesoreria'], 'limites' => ['max_empresas' => 8]]);
        ProductoExtra::create(['producto_id' => $p->id, 'codigo' => 'empresa_adicional', 'nombre' => 'Empresa adicional',
            'limite' => 'max_empresas', 'incremento' => 1]);
    }

    /**
     * Login bajo demanda: hacerlo en setUp deja el token en el singleton de
     * JWTAuth y "autentica" también las peticiones sin header de la misma
     * prueba (el servidor real sí responde 401; verificado en vivo).
     */
    private function auth(): array
    {
        return $this->sesion ??= ['Authorization' => 'Bearer '.auth('api')->login(
            LandlordAdmin::create(['nombre' => 'Op', 'email' => 'op@kernia.test', 'password' => 'Op-123456789!', 'activo' => true])
        )];
    }

    public function test_sin_sesion_no_hay_acceso(): void
    {
        $this->getJson('/api/clientes')->assertUnauthorized();
        $this->getJson('/api/catalogo/productos')->assertUnauthorized();
    }

    public function test_catalogo_no_expone_el_token_interno(): void
    {
        $r = $this->withHeaders($this->auth())->getJson('/api/catalogo/productos')->assertOk();

        $r->assertJsonPath('data.0.planes.1.codigo', 'profesional');
        $this->assertStringNotContainsString('token-com', $r->getContent());
    }

    public function test_flujo_completo_cliente_alta_plan_extra_estatus_y_restablecimiento(): void
    {
        Http::fake([
            '*/api/internal/v1/provision' => Http::response(['ref_externa' => null, 'status' => 'ready'], 201),
            '*/estatus' => Http::response('', 204),
            '*/admin/restablecer' => Http::response('', 204),
        ]);

        // Alta de cliente (slug inválido rechazado)
        $this->withHeaders($this->auth())->postJson('/api/clientes', ['slug' => 'Acme SA', 'nombre' => 'Acme'])->assertStatus(422);
        $id = $this->withHeaders($this->auth())->postJson('/api/clientes', ['slug' => 'acme', 'nombre' => 'Acme SA', 'rfc' => 'xaxx010101000'])
            ->assertCreated()->assertJsonPath('data.rfc', 'XAXX010101000')->json('data.id');

        // Alta de la suscripción con plan: devuelve la temporal una vez
        $r = $this->withHeaders($this->auth())->postJson("/api/clientes/{$id}/suscripciones", [
            'producto' => 'comercializa', 'plan' => 'basico', 'admin_nombre' => 'Admin', 'admin_email' => 'admin@acme.test',
        ])->assertCreated();
        $temporal = $r->json('password_temporal');
        $sid = $r->json('data.id');
        $this->assertSame(16, strlen($temporal));
        $r->assertJsonPath('data.estatus', 'activo')->assertJsonPath('data.limites.max_empresas', 4);

        // Plan y extra
        $this->withHeaders($this->auth())->patchJson("/api/suscripciones/{$sid}/plan", ['plan' => 'profesional'])
            ->assertOk()->assertJsonPath('data.modulos', ['ventas', 'compras', 'inventarios', 'tesoreria']);
        $this->withHeaders($this->auth())->postJson("/api/suscripciones/{$sid}/extras", ['extra' => 'empresa_adicional', 'cantidad' => 2, 'motivo' => 'pago 1'])
            ->assertOk()->assertJsonPath('data.limites.max_empresas', 10);

        // Estatus (pide motivo) y confirmación de la app
        $this->withHeaders($this->auth())->patchJson("/api/suscripciones/{$sid}/estatus", ['estatus' => 'suspendido'])->assertStatus(422);
        $this->withHeaders($this->auth())->patchJson("/api/suscripciones/{$sid}/estatus", ['estatus' => 'suspendido', 'motivo' => 'falta de pago'])
            ->assertOk()->assertJsonPath('app_confirmo', true)->assertJsonPath('data.estatus', 'suspendido');

        // No se restablece una suscripción suspendida; reactivada sí
        $this->withHeaders($this->auth())->postJson("/api/suscripciones/{$sid}/restablecer-admin")->assertStatus(422);
        $this->withHeaders($this->auth())->patchJson("/api/suscripciones/{$sid}/estatus", ['estatus' => 'activo', 'motivo' => 'pagó']);
        $nueva = $this->withHeaders($this->auth())->postJson("/api/suscripciones/{$sid}/restablecer-admin")
            ->assertOk()->assertJsonPath('email', 'admin@acme.test')->json('password_temporal');

        // Bitácora completa, con el operador, y sin ninguna contraseña
        $acciones = Auditoria::orderBy('id')->pluck('accion')->all();
        $this->assertSame(['cliente.creado', 'suscripcion.creada', 'suscripcion.plan_cambiado', 'suscripcion.extra',
            'suscripcion.estatus', 'suscripcion.estatus', 'suscripcion.admin_restablecido'], $acciones);
        $this->assertSame(1, Auditoria::distinct()->count('usuario_id'));
        $todo = Auditoria::all()->toJson();
        $this->assertStringNotContainsString($temporal, $todo);
        $this->assertStringNotContainsString($nueva, $todo);
    }

    public function test_detalle_no_expone_credenciales_de_base(): void
    {
        $c = Cliente::create(['slug' => 'dedi', 'nombre' => 'Dedi', 'estatus' => 'activo']);
        Suscripcion::create(['cliente_id' => $c->id, 'producto_id' => Producto::first()->id, 'estatus' => 'activo',
            'db_database' => 'com_dedi', 'db_username' => 'tn_comdedi_app', 'db_password' => 'secreto-db-123']);

        $r = $this->withHeaders($this->auth())->getJson("/api/clientes/{$c->id}")->assertOk();

        $this->assertStringNotContainsString('secreto-db-123', $r->getContent());
        $this->assertStringNotContainsString('tn_comdedi_app', $r->getContent());
    }

    public function test_tenants_heredado_es_solo_consulta(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/tenants')->assertOk();
        $this->withHeaders($this->auth())->postJson('/api/tenants', [])->assertStatus(405);
    }
}
