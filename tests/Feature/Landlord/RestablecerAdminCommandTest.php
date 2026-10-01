<?php

namespace Tests\Feature\Landlord;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RestablecerAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private function preparar(string $estatus = Suscripcion::ESTATUS_ACTIVO): void
    {
        $cliente = Cliente::create(['slug' => 'acme', 'nombre' => 'Acme', 'estatus' => Cliente::ESTATUS_ACTIVO]);
        $producto = Producto::create([
            'slug' => 'svi', 'nombre' => 'Bridge SVI', 'base_url_interna' => 'http://127.0.0.1:8400',
            'modo_datos' => Producto::MODO_DEDICADA, 'token_interno' => 'token-svi', 'prefijo_db' => 'svi',
        ]);
        Suscripcion::create([
            'cliente_id' => $cliente->id, 'producto_id' => $producto->id,
            'estatus' => $estatus, 'admin_email' => 'admin@acme.test',
        ]);
    }

    /** Estándar v2 §6.2: la temporal mostrada es exactamente la que recibió la app. */
    public function test_restablece_y_muestra_una_vez_la_password_que_recibio_la_app(): void
    {
        $this->preparar();
        Http::fake(['*/api/internal/v1/clientes/acme/admin/restablecer' => Http::response('', 204)]);

        $codigo = Artisan::call('landlord:restablecer-admin', ['cliente_slug' => 'acme', 'producto_slug' => 'svi']);
        $salida = Artisan::output();

        $this->assertSame(0, $codigo);
        $peticion = Http::recorded()->first()[0];
        $this->assertSame('admin@acme.test', $peticion['email']);
        $this->assertTrue($peticion->hasHeader('X-Internal-Token', 'token-svi'));
        $this->assertSame(1, substr_count($salida, $peticion['password_temporal']));
    }

    public function test_si_la_app_rechaza_no_muestra_password(): void
    {
        $this->preparar();
        Http::fake(['*/admin/restablecer' => Http::response(['message' => 'No encontrado.'], 404)]);

        $codigo = Artisan::call('landlord:restablecer-admin', ['cliente_slug' => 'acme', 'producto_slug' => 'svi']);

        $this->assertSame(1, $codigo);
        $this->assertStringNotContainsString('Contraseña:', Artisan::output());
    }

    public function test_no_restablece_una_suscripcion_suspendida(): void
    {
        $this->preparar(Suscripcion::ESTATUS_SUSPENDIDO);
        Http::fake();

        $this->assertSame(1, Artisan::call('landlord:restablecer-admin', ['cliente_slug' => 'acme', 'producto_slug' => 'svi']));
        Http::assertNothingSent();
    }
}
