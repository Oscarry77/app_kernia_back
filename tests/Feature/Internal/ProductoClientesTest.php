<?php

namespace Tests\Feature\Internal;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductoClientesTest extends TestCase
{
    use RefreshDatabase;

    private function producto(string $slug, string $token): Producto
    {
        return Producto::create([
            'slug' => $slug, 'nombre' => ucfirst($slug), 'base_url_interna' => "http://127.0.0.1:9000/{$slug}",
            'modo_datos' => Producto::MODO_DEDICADA, 'token_interno' => $token, 'prefijo_db' => $slug,
        ]);
    }

    private function suscribir(Producto $producto, string $slug, string $estatus, string $estatusCliente = Cliente::ESTATUS_ACTIVO): void
    {
        $cliente = Cliente::firstOrCreate(['slug' => $slug], ['nombre' => ucfirst($slug), 'estatus' => $estatusCliente]);
        Suscripcion::create(['cliente_id' => $cliente->id, 'producto_id' => $producto->id, 'estatus' => $estatus]);
    }

    /**
     * 01-oct-2026: Comercializa necesita correr tareas programadas por
     * cliente sin mantener su propia lista. Solo activos efectivos, sin
     * conexión ni secretos, y solo los de ESE producto.
     */
    public function test_lista_solo_clientes_activos_del_producto_y_sin_secretos(): void
    {
        $com = $this->producto('comercializa', 'token-com');
        $hrm = $this->producto('hrm', 'token-hrm');
        $this->suscribir($com, 'beta', Suscripcion::ESTATUS_ACTIVO);
        $this->suscribir($com, 'alfa', Suscripcion::ESTATUS_ACTIVO);
        $this->suscribir($com, 'suspendida', Suscripcion::ESTATUS_SUSPENDIDO);
        $this->suscribir($com, 'cliente-suspendido', Suscripcion::ESTATUS_ACTIVO, Cliente::ESTATUS_SUSPENDIDO);
        $this->suscribir($com, 'en-alta', Suscripcion::ESTATUS_EN_APROVISIONAMIENTO);
        $this->suscribir($hrm, 'solo-hrm', Suscripcion::ESTATUS_ACTIVO);

        foreach (['/api/internal', '/internal'] as $prefijo) {
            $respuesta = $this->withHeaders(['X-Internal-Token' => 'token-com'])
                ->get("{$prefijo}/v1/productos/comercializa/clientes");

            $respuesta->assertOk();
            $this->assertSame(['alfa', 'beta'], array_column($respuesta->json('clientes'), 'slug'));
            $this->assertStringNotContainsString('db_', $respuesta->getContent());
        }
    }

    public function test_token_de_otro_producto_no_lista_clientes(): void
    {
        $this->producto('comercializa', 'token-com');
        $this->producto('hrm', 'token-hrm');

        $this->withHeaders(['X-Internal-Token' => 'token-hrm'])
            ->get('/internal/v1/productos/comercializa/clientes')
            ->assertUnauthorized();

        $this->get('/internal/v1/productos/comercializa/clientes')->assertUnauthorized();
    }
}
