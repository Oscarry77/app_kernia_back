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
            LandlordAdmin::create(['nombre' => 'Op', 'email' => 'op@kernia.test', 'rol' => 'superadmin', 'password' => 'Op-123456789!', 'activo' => true])
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
        $this->withHeaders($this->auth())->postJson('/api/clientes', [...self::moral(), 'slug' => 'Acme SA'])->assertStatus(422);
        $id = $this->withHeaders($this->auth())->postJson('/api/clientes', self::moral())
            ->assertCreated()->assertJsonPath('data.rfc', 'ACM010101AB1')->json('data.id');

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

    private static function moral(array $extra = []): array
    {
        return [
            'slug' => 'acme', 'tipo_persona' => 'moral', 'rfc' => 'acm010101ab1',
            'razon_social' => 'Empresa única en México', 'regimen_capital' => 'SA DE CV', 'nombre_comercial' => 'Tiendas Única',
            'regimen_fiscal' => '601', 'estatus_padron' => 'activo', 'fecha_inicio_operaciones' => '2010-01-15',
            'codigo_postal' => '64000', 'tipo_vialidad' => 'AVENIDA', 'nombre_vialidad' => 'Constitución', 'numero_exterior' => '100',
            'colonia' => 'Centro', 'municipio' => 'Monterrey', 'entidad_federativa' => 'NUEVO LEÓN',
            'correo' => 'Contacto@Acme.test', 'telefono_lada' => '81', 'telefono_numero' => '12345678',
            ...$extra,
        ];
    }

    private static function fisica(array $extra = []): array
    {
        return [
            'slug' => 'juan-perez', 'tipo_persona' => 'fisica', 'rfc' => 'PEGJ800101AB1', 'curp' => 'PEGJ800101HNLRNN09',
            'nombres' => 'Juan', 'primer_apellido' => 'Pérez', 'segundo_apellido' => 'González',
            'regimen_fiscal' => '626', 'codigo_postal' => '64000', 'entidad_federativa' => 'NUEVO LEÓN', 'correo' => 'juan@correo.test',
            ...$extra,
        ];
    }

    /** 02-oct-2026: datos según la Constancia de Situación Fiscal, con tipo de persona. */
    public function test_persona_moral_arma_su_nombre_y_normaliza_datos(): void
    {
        $r = $this->withHeaders($this->auth())->postJson('/api/clientes', self::moral())->assertCreated();

        $r->assertJsonPath('data.nombre', 'EMPRESA ÚNICA EN MÉXICO, SA DE CV')
            ->assertJsonPath('data.fiscal.razon_social', 'EMPRESA ÚNICA EN MÉXICO')
            ->assertJsonPath('data.fiscal.correo', 'contacto@acme.test')
            ->assertJsonPath('data.fiscal.regimen_fiscal_nombre', 'General de Ley Personas Morales')
            ->assertJsonPath('data.datos_fiscales_completos', true);
    }

    public function test_persona_fisica_requiere_curp_y_nombre_y_arma_su_nombre(): void
    {
        $this->withHeaders($this->auth())->postJson('/api/clientes', self::fisica(['curp' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('curp');

        $this->withHeaders($this->auth())->postJson('/api/clientes', self::fisica())
            ->assertCreated()->assertJsonPath('data.nombre', 'JUAN PÉREZ GONZÁLEZ')->assertJsonPath('data.fiscal.razon_social', null);
    }

    public function test_reglas_segun_tipo_de_persona(): void
    {
        $h = $this->withHeaders($this->auth());

        // RFC de 13 en una moral, de 12 en una física
        $h->postJson('/api/clientes', self::moral(['rfc' => 'PEGJ800101AB1']))->assertJsonValidationErrors('rfc');
        $h->postJson('/api/clientes', self::fisica(['rfc' => 'ACM010101AB1']))->assertJsonValidationErrors('rfc');
        // Régimen que no aplica al tipo
        $h->postJson('/api/clientes', self::moral(['regimen_fiscal' => '612']))->assertJsonValidationErrors('regimen_fiscal');
        $h->postJson('/api/clientes', self::fisica(['regimen_fiscal' => '601']))->assertJsonValidationErrors('regimen_fiscal');
        // Campos de persona física en una moral
        $h->postJson('/api/clientes', self::moral(['curp' => 'PEGJ800101HNLRNN09']))->assertJsonValidationErrors('curp');
        // Teléfono: lada + número = 10 dígitos; CP de 5
        $h->postJson('/api/clientes', self::moral(['telefono_lada' => '81', 'telefono_numero' => '1234567']))->assertJsonValidationErrors('telefono_numero');
        $h->postJson('/api/clientes', self::moral(['codigo_postal' => '640']))->assertJsonValidationErrors('codigo_postal');
    }

    public function test_editar_no_cambia_el_slug_y_cambiar_de_tipo_limpia_los_datos_del_otro(): void
    {
        $id = $this->withHeaders($this->auth())->postJson('/api/clientes', self::moral())->json('data.id');

        $this->withHeaders($this->auth())->putJson("/api/clientes/{$id}", self::moral(['slug' => 'otro']))->assertJsonValidationErrors('slug');

        $datos = self::fisica();
        unset($datos['slug']);
        $this->withHeaders($this->auth())->putJson("/api/clientes/{$id}", $datos)->assertOk()
            ->assertJsonPath('data.slug', 'acme')
            ->assertJsonPath('data.fiscal.tipo_persona', 'fisica')
            ->assertJsonPath('data.fiscal.razon_social', null)
            ->assertJsonPath('data.fiscal.regimen_capital', null);

        $this->assertSame('cliente.editado', Auditoria::latest('id')->value('accion'));
    }

    public function test_catalogo_fiscal(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/catalogo/fiscal')->assertOk()
            ->assertJsonCount(32, 'data.entidades_federativas')
            ->assertJsonPath('data.regimenes_fiscales.0.clave', '601');
    }

    /** Fase 2: definir vigencia, registrar pago y la vista general de vigencias. */
    public function test_vigencia_pago_y_vista_general(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-05 10:00', 'America/Mexico_City'));
        $c = Cliente::create(['slug' => 'vig', 'nombre' => 'VIG', 'estatus' => 'activo']);
        $s = Suscripcion::create(['cliente_id' => $c->id, 'producto_id' => Producto::first()->id, 'estatus' => 'activo']);
        $h = $this->withHeaders($this->auth());

        $h->getJson('/api/vigencias?filtro=sin_definir')->assertOk()->assertJsonPath('data.0.id', $s->id);
        $h->postJson("/api/suscripciones/{$s->id}/pagos", ['referencia' => 'X'])->assertStatus(422);

        $h->putJson("/api/suscripciones/{$s->id}/vigencia", [
            'modalidad_pago' => 'trimestral', 'fecha_proximo_pago' => '2026-10-20', 'suspension_automatica' => true,
        ])->assertOk()->assertJsonPath('data.dias_restantes', 15)->assertJsonPath('data.aviso.nivel', 'info');

        $h->getJson('/api/vigencias?filtro=por_vencer&dias=30')->assertJsonPath('data.0.dias_restantes', 15);

        $h->postJson("/api/suscripciones/{$s->id}/pagos", ['referencia' => 'TRF-99', 'monto' => 4500])
            ->assertCreated()->assertJsonPath('data.fecha_proximo_pago', '2027-01-20')->assertJsonPath('reactivada', false);

        $h->getJson("/api/suscripciones/{$s->id}/pagos")->assertJsonPath('data.0.periodo_desde', '2026-10-20')
            ->assertJsonPath('data.0.registrado_por', 'Op');
        $this->assertSame(['suscripcion.vigencia', 'suscripcion.pago'], Auditoria::orderBy('id')->pluck('accion')->all());
    }

    public function test_tenants_heredado_es_solo_consulta(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/tenants')->assertOk();
        $this->withHeaders($this->auth())->postJson('/api/tenants', [])->assertStatus(405);
    }
}
