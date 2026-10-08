<?php

namespace Tests\Feature\Panel;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\VigenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/** Tipos de cliente: comercial, demo, capacitación y prueba (05-oct-2026). */
class TipoClienteTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Clave-de-prueba-123!';

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

    private function datos(string $slug, ?string $tipo = null): array
    {
        return array_filter([
            'slug' => $slug, 'tipo' => $tipo, 'tipo_persona' => 'moral', 'rfc' => 'AAA010101AAA', 'razon_social' => 'EMPRESA DE PRUEBA',
            'regimen_fiscal' => '601', 'codigo_postal' => '01000', 'entidad_federativa' => 'CIUDAD DE MÉXICO', 'correo' => 'x@y.mx',
        ]);
    }

    public function test_alta_con_tipo_prefijo_y_permisos(): void
    {
        $super = $this->operador('super', 'superadmin');
        $vendedor = $this->operador('vend', 'vendedor');

        $this->withHeaders($this->como($vendedor))->postJson('/api/clientes', $this->datos('acme'))
            ->assertCreated()->assertJsonPath('data.tipo', 'comercial');
        $this->withHeaders($this->como($vendedor))->postJson('/api/clientes', $this->datos('presentacion', 'demo'))
            ->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->withHeaders($this->como($vendedor))->postJson('/api/clientes', $this->datos('demo-ferreteria', 'demo'))
            ->assertCreated()->assertJsonPath('data.tipo', 'demo');
        $this->withHeaders($this->como($vendedor))->postJson('/api/clientes', $this->datos('cap-curso1', 'capacitacion'))->assertCreated();
        $this->withHeaders($this->como($vendedor))->postJson('/api/clientes', $this->datos('prueba-x', 'prueba'))
            ->assertStatus(422)->assertJsonValidationErrors('tipo');
        $this->withHeaders($this->como($super))->postJson('/api/clientes', $this->datos('prueba-x', 'prueba'))->assertCreated();

        // Cambiar el tipo después: solo el superadmin
        $demo = Cliente::where('slug', 'demo-ferreteria')->first();
        $gerente = $this->operador('ger', 'gerente');
        $this->withHeaders($this->como($gerente))->putJson("/api/clientes/{$demo->id}", [...$this->datos('', 'comercial'), 'slug' => null])
            ->assertStatus(422)->assertJsonValidationErrors('tipo');
        $this->withHeaders($this->como($super))->putJson("/api/clientes/{$demo->id}", [...$this->datos('', 'comercial'), 'slug' => null])
            ->assertOk()->assertJsonPath('data.tipo', 'comercial');
    }

    public function test_visibilidad_por_tipo(): void
    {
        $vendedor = $this->operador('vend', 'vendedor');
        $direccion = $this->operador('dir', 'direccion');
        $super = $this->operador('super', 'superadmin');

        $mio = Cliente::create(['slug' => 'mio', 'nombre' => 'Mío', 'estatus' => 'activo']);
        $mio->operadores()->attach($vendedor->id);
        Cliente::create(['slug' => 'ajeno', 'nombre' => 'Ajeno', 'estatus' => 'activo']);
        Cliente::create(['slug' => 'demo-a', 'nombre' => 'Demo', 'estatus' => 'activo', 'tipo' => 'demo']);
        Cliente::create(['slug' => 'cap-a', 'nombre' => 'Cap', 'estatus' => 'activo', 'tipo' => 'capacitacion']);
        $prueba = Cliente::create(['slug' => 'prueba-a', 'nombre' => 'Prueba', 'estatus' => 'activo', 'tipo' => 'prueba']);

        $slugs = fn (LandlordAdmin $a) => collect($this->withHeaders($this->como($a))->getJson('/api/clientes')->json('data'))->pluck('slug')->sort()->values()->all();

        $this->assertSame(['cap-a', 'demo-a', 'mio'], $slugs($vendedor));
        $this->assertSame(['ajeno', 'cap-a', 'demo-a', 'mio'], $slugs($direccion));
        $this->assertSame(['ajeno', 'cap-a', 'demo-a', 'mio', 'prueba-a'], $slugs($super));

        $this->withHeaders($this->como($direccion))->getJson("/api/clientes/{$prueba->id}")->assertNotFound();
        $this->withHeaders($this->como($super))->getJson("/api/clientes/{$prueba->id}")->assertOk();
    }

    public function test_no_comerciales_fuera_de_vigencias_cortes_y_avisos(): void
    {
        Http::fake(['*/estatus' => Http::response('', 204)]);
        $p = Producto::create(['slug' => 'comercializa', 'nombre' => 'Comercializa', 'base_url_interna' => 'http://x',
            'modo_datos' => Producto::MODO_COMPARTIDA, 'token_interno' => 't']);
        $vencida = VigenciaService::hoy()->subDay()->toDateString();
        $suscribir = fn (string $slug, string $tipo) => Suscripcion::create(['producto_id' => $p->id, 'estatus' => 'activo',
            'cliente_id' => Cliente::create(['slug' => $slug, 'nombre' => $slug, 'estatus' => 'activo', 'tipo' => $tipo])->id,
            'modalidad_pago' => 'mensual', 'fecha_proximo_pago' => $vencida, 'suspension_automatica' => true]);

        $comercial = $suscribir('acme', 'comercial');
        $demo = $suscribir('demo-a', 'demo');

        $this->assertNotNull(app(VigenciaService::class)->aviso($comercial->fresh()));
        $this->assertNull(app(VigenciaService::class)->aviso($demo->fresh()));

        $this->withHeaders($this->como($this->operador('super', 'superadmin')))->getJson('/api/vigencias')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.cliente_slug', 'acme');

        $this->artisan('landlord:procesar-vencimientos')->assertSuccessful();
        $this->assertSame('suspendido', $comercial->fresh()->estatus);
        $this->assertSame('activo', $demo->fresh()->estatus);
    }
}
