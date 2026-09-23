<?php

namespace Tests\Feature\Landlord;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\SuscripcionEstatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SuscripcionEstatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private function crearSuscripcion(): Suscripcion
    {
        $producto = Producto::create([
            'slug' => 'svi', 'nombre' => 'Bridge SVI', 'base_url_interna' => 'http://127.0.0.1:8400',
            'modo_datos' => Producto::MODO_DEDICADA, 'token_interno' => 'token-svi', 'prefijo_db' => 'svi',
        ]);
        $cliente = Cliente::create(['slug' => 'demo-svi', 'nombre' => 'Demo SVI', 'estatus' => Cliente::ESTATUS_ACTIVO]);

        return Suscripcion::create(['cliente_id' => $cliente->id, 'producto_id' => $producto->id, 'estatus' => Suscripcion::ESTATUS_ACTIVO]);
    }

    public function test_push_exitoso_devuelve_true_y_aplica_localmente(): void
    {
        Http::fake(['*/api/internal/v1/clientes/*/estatus' => Http::response('', 204)]);
        $suscripcion = $this->crearSuscripcion();

        $ok = app(SuscripcionEstatusService::class)->cambiarEstatus($suscripcion, 'suspendido', 'vencimiento');

        $this->assertTrue($ok);
        $this->assertSame('suspendido', $suscripcion->fresh()->estatus);
    }

    public function test_push_fallido_igual_aplica_el_cambio_local(): void
    {
        Http::fake(['*/api/internal/v1/clientes/*/estatus' => Http::response(['message' => 'error'], 500)]);
        $suscripcion = $this->crearSuscripcion();

        $ok = app(SuscripcionEstatusService::class)->cambiarEstatus($suscripcion, 'suspendido');

        $this->assertFalse($ok);
        $this->assertSame('suspendido', $suscripcion->fresh()->estatus, 'El estatus local debe cambiar aunque el push a la app falle.');
    }
}
