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

    /**
     * 01-oct-2026: un push fallido se perdía. HRM no consulta `resolve`, así
     * que un cliente suspendido seguía operando. Ahora queda pendiente y se
     * reintenta hasta que la app confirme.
     */
    public function test_push_fallido_queda_pendiente_y_el_reintento_lo_entrega(): void
    {
        Http::fake(['*/api/internal/v1/clientes/*/estatus' => Http::sequence()
            ->push(['message' => 'caída'], 503)
            ->push('', 204)]);
        $suscripcion = $this->crearSuscripcion();
        $servicio = app(SuscripcionEstatusService::class);

        $servicio->cambiarEstatus($suscripcion, 'suspendido', 'vencimiento');
        $pendiente = $suscripcion->fresh();
        $this->assertSame('suspendido', $pendiente->estatus_por_notificar);
        $this->assertSame(1, $pendiente->estatus_notificacion_intentos);

        $this->travel(2)->minutes();
        $this->assertSame(['confirmadas' => 1, 'fallidas' => 0], $servicio->reintentarPendientes());

        $confirmada = $suscripcion->fresh();
        $this->assertNull($confirmada->estatus_por_notificar);
        $this->assertSame(0, $confirmada->estatus_notificacion_intentos);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['estatus'] === 'suspendido' && $r['motivo'] === 'vencimiento');
    }

    public function test_reintento_respeta_la_espera_y_manda_el_ultimo_estatus(): void
    {
        Http::fake(['*/api/internal/v1/clientes/*/estatus' => Http::sequence()
            ->push(['message' => 'caída'], 503)
            ->push(['message' => 'caída'], 503)
            ->push('', 204)]);
        $suscripcion = $this->crearSuscripcion();
        $servicio = app(SuscripcionEstatusService::class);

        $servicio->cambiarEstatus($suscripcion, 'suspendido');
        $servicio->cambiarEstatus($suscripcion->fresh(), 'activo');

        // Recién falló: todavía no toca reintentar.
        $this->assertSame(['confirmadas' => 0, 'fallidas' => 0], $servicio->reintentarPendientes());

        $this->travel(2)->minutes();
        $this->assertSame(['confirmadas' => 1, 'fallidas' => 0], $servicio->reintentarPendientes());

        $ultimo = collect(Http::recorded())->last()[0];
        $this->assertSame('activo', $ultimo['estatus'], 'Solo debe viajar el último estatus pedido.');
    }
}
