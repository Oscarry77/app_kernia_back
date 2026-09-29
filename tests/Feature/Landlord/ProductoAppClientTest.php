<?php

namespace Tests\Feature\Landlord;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\ProductoAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ProductoAppClientTest extends TestCase
{
    use RefreshDatabase;

    private function crearSuscripcion(string $modoDatos, array $extra = []): Suscripcion
    {
        $producto = Producto::create([
            'slug' => 'hrm',
            'nombre' => 'Bridge HRM',
            'base_url_interna' => 'http://127.0.0.1:8300',
            'modo_datos' => $modoDatos,
            'token_interno' => 'token-hrm',
            'prefijo_db' => null,
        ]);
        $cliente = Cliente::create(['slug' => 'acme', 'nombre' => 'Acme', 'estatus' => Cliente::ESTATUS_ACTIVO]);

        return Suscripcion::create([
            'cliente_id' => $cliente->id,
            'producto_id' => $producto->id,
            'estatus' => Suscripcion::ESTATUS_EN_APROVISIONAMIENTO,
            'plan' => 'estandar',
            ...$extra,
        ]);
    }

    public function test_provisionar_compartida_no_manda_conexion_y_devuelve_ref_externa(): void
    {
        Http::fake(['*/api/internal/v1/provision' => Http::response(['ref_externa' => '42'], 200)]);
        $suscripcion = $this->crearSuscripcion(Producto::MODO_COMPARTIDA);

        $cuerpo = (new ProductoAppClient())->provisionar($suscripcion, [
            'nombre' => 'Admin', 'email' => 'admin@acme.test', 'password_temporal' => 'temp123',
        ]);

        $this->assertSame('42', $cuerpo['ref_externa']);
        Http::assertSent(function ($request) {
            return $request->url() === 'http://127.0.0.1:8300/api/internal/v1/provision'
                && $request->hasHeader('X-Internal-Token', 'token-hrm')
                && $request->hasHeader('Idempotency-Key')
                && ! isset($request->data()['conexion'])
                && $request['admin_inicial']['email'] === 'admin@acme.test';
        });
    }

    public function test_provisionar_dedicada_incluye_conexion(): void
    {
        Http::fake(['*/api/internal/v1/provision' => Http::response(['ref_externa' => null], 200)]);
        $suscripcion = $this->crearSuscripcion(Producto::MODO_DEDICADA, [
            'db_driver' => 'mysql', 'db_host' => '127.0.0.1', 'db_port' => '3306',
            'db_database' => 'hrm_acme', 'db_username' => 'tn_acme', 'db_password' => 'secreto',
        ]);

        (new ProductoAppClient())->provisionar($suscripcion, [
            'nombre' => 'Admin', 'email' => 'admin@acme.test', 'password_temporal' => 'temp123',
        ]);

        Http::assertSent(fn ($request) => ($request['conexion']['db_database'] ?? null) === 'hrm_acme');
    }

    public function test_provisionar_falla_lanza_excepcion(): void
    {
        Http::fake(['*/api/internal/v1/provision' => Http::response(['message' => 'error'], 500)]);
        $suscripcion = $this->crearSuscripcion(Producto::MODO_COMPARTIDA);

        $this->expectException(RuntimeException::class);
        (new ProductoAppClient())->provisionar($suscripcion, ['nombre' => 'A', 'email' => 'a@a.test', 'password_temporal' => 'x']);
    }

    /**
     * 22-sep-2026: una app cuya validación falla y redirige (302) a home en
     * vez de responder JSON -- Guzzle sigue la redirección en silencio,
     * termina en 200 + HTML, y `failed()` nunca es true (encontrado en la
     * prueba real contra HRM). No debe interpretarse como éxito.
     */
    public function test_provisionar_rechaza_respuesta_200_que_no_es_json(): void
    {
        Http::fake(['*/api/internal/v1/provision' => Http::response('<!DOCTYPE html><title>Laravel</title>', 200, ['Content-Type' => 'text/html; charset=utf-8'])]);
        $suscripcion = $this->crearSuscripcion(Producto::MODO_COMPARTIDA);

        $this->expectException(RuntimeException::class);
        (new ProductoAppClient())->provisionar($suscripcion, ['nombre' => 'A', 'email' => 'a@a.test', 'password_temporal' => 'x']);
    }

    public function test_provisionar_manda_accept_json_y_no_sigue_redirecciones(): void
    {
        Http::fake(['*/api/internal/v1/provision' => Http::response(['ref_externa' => null], 200)]);
        $suscripcion = $this->crearSuscripcion(Producto::MODO_COMPARTIDA);

        (new ProductoAppClient())->provisionar($suscripcion, ['nombre' => 'A', 'email' => 'a@a.test', 'password_temporal' => 'x']);

        Http::assertSent(fn ($request) => $request->hasHeader('Accept', 'application/json'));
    }

    public function test_notificar_estatus_manda_el_body_esperado(): void
    {
        Http::fake(['*/api/internal/v1/clientes/*/estatus' => Http::response('', 204)]);
        $suscripcion = $this->crearSuscripcion(Producto::MODO_COMPARTIDA);

        (new ProductoAppClient())->notificarEstatus($suscripcion, 'suspendido', 'vencimiento');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/clientes/acme/estatus')
                && $request['estatus'] === 'suspendido'
                && $request['motivo'] === 'vencimiento'
                && isset($request['efectivo_desde']);
        });
    }

    public function test_notificar_estatus_falla_lanza_excepcion(): void
    {
        Http::fake(['*/api/internal/v1/clientes/*/estatus' => Http::response(['message' => 'error'], 500)]);
        $suscripcion = $this->crearSuscripcion(Producto::MODO_COMPARTIDA);

        $this->expectException(RuntimeException::class);
        (new ProductoAppClient())->notificarEstatus($suscripcion, 'suspendido');
    }

    public function test_metricas_degrada_a_ok_false_si_falla(): void
    {
        Http::fake(['*/api/internal/v1/metricas/*' => Http::response(['message' => 'error'], 500)]);
        $suscripcion = $this->crearSuscripcion(Producto::MODO_COMPARTIDA);

        $this->assertSame(['ok' => false], (new ProductoAppClient())->metricas($suscripcion));
    }

    public function test_health_degrada_a_ok_false_si_falla(): void
    {
        Http::fake(['*/api/internal/v1/health' => Http::response(null, 500)]);
        $producto = Producto::create([
            'slug' => 'hrm', 'nombre' => 'Bridge HRM', 'base_url_interna' => 'http://127.0.0.1:8300',
            'modo_datos' => Producto::MODO_COMPARTIDA, 'token_interno' => 'token-hrm',
        ]);

        $this->assertSame(['ok' => false], (new ProductoAppClient())->health($producto));
    }

    public function test_estado_aprovisionamiento_consulta_por_cliente_id_y_404_devuelve_null(): void
    {
        $suscripcion = $this->crearSuscripcion(Producto::MODO_COMPARTIDA);
        $url = "http://127.0.0.1:8300/api/internal/v1/provision/{$suscripcion->cliente->id}/status";

        Http::fake([$url => Http::sequence()
            ->push(['status' => 'ready'], 200)
            ->push(['message' => 'no existe'], 404)]);

        $this->assertSame('ready', (new ProductoAppClient())->estadoAprovisionamiento($suscripcion)['status']);
        $this->assertNull((new ProductoAppClient())->estadoAprovisionamiento($suscripcion));
    }
}
