<?php

namespace Tests\Feature\Landlord;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AprovisionarSuscripcionCommandTest extends TestCase
{
    use RefreshDatabase;

    private function preparar(): void
    {
        Cliente::create(['slug' => 'acme', 'nombre' => 'Acme', 'estatus' => Cliente::ESTATUS_ACTIVO]);
        Producto::create([
            'slug' => 'hrm', 'nombre' => 'Bridge HRM', 'base_url_interna' => 'http://127.0.0.1:8300',
            'modo_datos' => Producto::MODO_COMPARTIDA, 'token_interno' => 'token-hrm',
        ]);
    }

    /**
     * 30-sep-2026: Kernia generaba la temporal y no la mostraba -- nadie
     * podía hacer el primer ingreso. Se muestra una vez y debe ser
     * EXACTAMENTE la que recibió la app.
     */
    public function test_muestra_una_vez_la_password_que_recibio_la_app(): void
    {
        $this->preparar();
        Http::fake(['*/api/internal/v1/provision' => Http::response(['ref_externa' => '1'], 201)]);

        $codigo = Artisan::call('landlord:aprovisionar-suscripcion', [
            'cliente_slug' => 'acme', 'producto_slug' => 'hrm',
            'admin_nombre' => 'Admin', 'admin_email' => 'admin@acme.test', '--plan' => 'basico',
        ]);
        $salida = Artisan::output();

        $this->assertSame(0, $codigo);
        $enviada = Http::recorded()->first()[0]['admin_inicial']['password_temporal'];
        $this->assertStringContainsString("Contraseña: {$enviada}", $salida);
        $this->assertSame(1, substr_count($salida, $enviada), 'Debe mostrarse una sola vez.');
    }

    public function test_no_muestra_password_si_la_provision_falla(): void
    {
        $this->preparar();
        Http::fake(['*/api/internal/v1/provision' => Http::response(['message' => 'error'], 500)]);

        Artisan::call('landlord:aprovisionar-suscripcion', [
            'cliente_slug' => 'acme', 'producto_slug' => 'hrm',
            'admin_nombre' => 'Admin', 'admin_email' => 'admin@acme.test',
        ]);

        $this->assertStringNotContainsString('Contraseña:', Artisan::output());
    }
}
