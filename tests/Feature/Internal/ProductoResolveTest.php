<?php

namespace Tests\Feature\Internal;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use App\Models\Landlord\ProductoModulo;
use App\Models\Landlord\Suscripcion;
use App\Models\Landlord\SuscripcionModulo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductoResolveTest extends TestCase
{
    use RefreshDatabase;

    private function crearProducto(string $slug, string $modoDatos, ?string $token = null): Producto
    {
        return Producto::create([
            'slug' => $slug,
            'nombre' => ucfirst($slug),
            'base_url_interna' => "http://127.0.0.1:9000/{$slug}",
            'modo_datos' => $modoDatos,
            'token_interno' => $token ?? "token-{$slug}",
            'prefijo_db' => $slug,
        ]);
    }

    public function test_cliente_activo_con_producto_contratado_resuelve_200(): void
    {
        $producto = $this->crearProducto('comercializa', Producto::MODO_DEDICADA, 'token-real');
        $cliente = Cliente::create(['slug' => 'labormx', 'nombre' => 'LABRMX', 'estatus' => Cliente::ESTATUS_ACTIVO]);
        Suscripcion::create([
            'cliente_id' => $cliente->id,
            'producto_id' => $producto->id,
            'estatus' => Suscripcion::ESTATUS_ACTIVO,
            'plan' => 'estandar',
            'db_driver' => 'mysql',
            'db_host' => '127.0.0.1',
            'db_port' => '3306',
            'db_database' => 'kernia_labormx',
            'db_username' => 'tn_com_labormx',
            'db_password' => 'secreto',
        ]);

        $response = $this->getJson('/api/internal/v1/productos/comercializa/resolve/labormx', [
            'X-Internal-Token' => 'token-real',
        ]);

        $response->assertStatus(200)->assertJson([
            'cliente' => ['slug' => 'labormx', 'nombre' => 'LABRMX'],
            'suscripcion' => ['estatus' => 'activo', 'plan' => 'estandar', 'modo_datos' => 'dedicada'],
            'aviso' => null,
        ]);
        $response->assertJsonPath('conexion.db_database', 'kernia_labormx');
        $response->assertJsonMissingPath('modulos');
    }

    public function test_cliente_sin_ese_producto_da_404_generico(): void
    {
        $producto = $this->crearProducto('comercializa', Producto::MODO_DEDICADA, 'token-real');
        Cliente::create(['slug' => 'labormx', 'nombre' => 'LABRMX', 'estatus' => Cliente::ESTATUS_ACTIVO]);
        // Sin suscripción para este producto.

        $response = $this->getJson('/api/internal/v1/productos/comercializa/resolve/labormx', [
            'X-Internal-Token' => 'token-real',
        ]);

        $response->assertStatus(404);
    }

    public function test_cliente_inexistente_da_el_mismo_404(): void
    {
        $this->crearProducto('comercializa', Producto::MODO_DEDICADA, 'token-real');

        $response = $this->getJson('/api/internal/v1/productos/comercializa/resolve/no-existe', [
            'X-Internal-Token' => 'token-real',
        ]);

        $response->assertStatus(404);
    }

    public function test_token_de_otro_producto_no_resuelve_esta_suscripcion(): void
    {
        $comercializa = $this->crearProducto('comercializa', Producto::MODO_DEDICADA, 'token-comercializa');
        $this->crearProducto('hrm', Producto::MODO_COMPARTIDA, 'token-hrm');
        $cliente = Cliente::create(['slug' => 'labormx', 'nombre' => 'LABRMX', 'estatus' => Cliente::ESTATUS_ACTIVO]);
        Suscripcion::create([
            'cliente_id' => $cliente->id,
            'producto_id' => $comercializa->id,
            'estatus' => Suscripcion::ESTATUS_ACTIVO,
        ]);

        $response = $this->getJson('/api/internal/v1/productos/comercializa/resolve/labormx', [
            'X-Internal-Token' => 'token-hrm',
        ]);

        $response->assertStatus(401);
    }

    public function test_sin_token_da_401_sin_detalles(): void
    {
        $this->crearProducto('comercializa', Producto::MODO_DEDICADA, 'token-real');

        $response = $this->getJson('/api/internal/v1/productos/comercializa/resolve/labormx');

        $response->assertStatus(401);
    }

    public function test_cliente_suspendido_colapsa_estatus_efectivo_de_la_suscripcion(): void
    {
        $producto = $this->crearProducto('svi', Producto::MODO_DEDICADA, 'token-svi');
        $cliente = Cliente::create(['slug' => 'demo', 'nombre' => 'Demo', 'estatus' => Cliente::ESTATUS_SUSPENDIDO]);
        Suscripcion::create([
            'cliente_id' => $cliente->id,
            'producto_id' => $producto->id,
            'estatus' => Suscripcion::ESTATUS_ACTIVO,
        ]);

        $response = $this->getJson('/api/internal/v1/productos/svi/resolve/demo', [
            'X-Internal-Token' => 'token-svi',
        ]);

        $response->assertStatus(200)->assertJsonPath('suscripcion.estatus', 'suspendido');
    }

    public function test_modo_compartida_no_incluye_conexion(): void
    {
        $producto = $this->crearProducto('hrm', Producto::MODO_COMPARTIDA, 'token-hrm');
        $cliente = Cliente::create(['slug' => 'acme', 'nombre' => 'Acme', 'estatus' => Cliente::ESTATUS_ACTIVO]);
        Suscripcion::create([
            'cliente_id' => $cliente->id,
            'producto_id' => $producto->id,
            'estatus' => Suscripcion::ESTATUS_ACTIVO,
            'ref_externa' => '7',
        ]);

        $response = $this->getJson('/api/internal/v1/productos/hrm/resolve/acme', [
            'X-Internal-Token' => 'token-hrm',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('suscripcion.ref_externa', '7')
            ->assertJsonMissingPath('conexion');
    }

    public function test_producto_con_modulos_devuelve_solo_los_activos(): void
    {
        $producto = $this->crearProducto('comercializa', Producto::MODO_DEDICADA, 'token-real');
        foreach (['ventas', 'compras', 'inventarios', 'tesoreria', 'viaticos'] as $clave) {
            ProductoModulo::create(['producto_id' => $producto->id, 'clave' => $clave, 'nombre' => ucfirst($clave)]);
        }
        $cliente = Cliente::create(['slug' => 'labormx', 'nombre' => 'LABRMX', 'estatus' => Cliente::ESTATUS_ACTIVO]);
        $suscripcion = Suscripcion::create([
            'cliente_id' => $cliente->id,
            'producto_id' => $producto->id,
            'estatus' => Suscripcion::ESTATUS_ACTIVO,
        ]);
        foreach (['ventas', 'compras', 'inventarios'] as $clave) {
            SuscripcionModulo::create(['suscripcion_id' => $suscripcion->id, 'modulo_clave' => $clave, 'activo' => true]);
        }
        SuscripcionModulo::create(['suscripcion_id' => $suscripcion->id, 'modulo_clave' => 'tesoreria', 'activo' => false]);

        $response = $this->getJson('/api/internal/v1/productos/comercializa/resolve/labormx', [
            'X-Internal-Token' => 'token-real',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('modulos', ['ventas', 'compras', 'inventarios']);
    }

    public function test_producto_inexistente_da_404(): void
    {
        $response = $this->getJson('/api/internal/v1/productos/no-existe/resolve/labormx', [
            'X-Internal-Token' => 'cualquiera',
        ]);

        $response->assertStatus(404);
    }
}
