<?php

namespace Tests\Feature\Landlord;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\SuscripcionOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SuscripcionOnboardingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function crearProductoCompartida(): Producto
    {
        return Producto::create([
            'slug' => 'hrm', 'nombre' => 'Bridge HRM', 'base_url_interna' => 'http://127.0.0.1:8300',
            'modo_datos' => Producto::MODO_COMPARTIDA, 'token_interno' => 'token-hrm',
        ]);
    }

    public function test_aprovisiona_en_modo_compartida_sin_tocar_conexion(): void
    {
        Http::fake(['*/api/internal/v1/provision' => Http::response(['ref_externa' => '99'], 200)]);
        $cliente = Cliente::create(['slug' => 'acme', 'nombre' => 'Acme', 'estatus' => Cliente::ESTATUS_ACTIVO]);
        $producto = $this->crearProductoCompartida();

        $suscripcion = app(SuscripcionOnboardingService::class)->aprovisionar(
            $cliente, $producto, ['nombre' => 'Admin', 'email' => 'admin@acme.test'], 'estandar'
        );

        $this->assertSame(Suscripcion::ESTATUS_ACTIVO, $suscripcion->estatus);
        $this->assertSame('99', $suscripcion->ref_externa);
        $this->assertSame('admin@acme.test', $suscripcion->admin_email);
        $this->assertNull($suscripcion->db_database);
        $this->assertNotNull($suscripcion->provisionada_en);
    }

    public function test_provision_fallida_deja_suscripcion_en_fallido_no_activo(): void
    {
        Http::fake(['*/api/internal/v1/provision' => Http::response(['message' => 'error'], 500)]);
        $cliente = Cliente::create(['slug' => 'acme', 'nombre' => 'Acme', 'estatus' => Cliente::ESTATUS_ACTIVO]);
        $producto = $this->crearProductoCompartida();

        try {
            app(SuscripcionOnboardingService::class)->aprovisionar(
                $cliente, $producto, ['nombre' => 'Admin', 'email' => 'admin@acme.test']
            );
            $this->fail('Debió lanzar excepción.');
        } catch (RuntimeException) {
            // esperado
        }

        $suscripcion = Suscripcion::where('cliente_id', $cliente->id)->where('producto_id', $producto->id)->first();
        $this->assertNotNull($suscripcion, 'La suscripción debe quedar registrada aunque falle.');
        $this->assertSame(Suscripcion::ESTATUS_FALLIDO, $suscripcion->estatus);
    }

    public function test_no_permite_duplicar_suscripcion_existente(): void
    {
        $cliente = Cliente::create(['slug' => 'acme', 'nombre' => 'Acme', 'estatus' => Cliente::ESTATUS_ACTIVO]);
        $producto = $this->crearProductoCompartida();
        Suscripcion::create(['cliente_id' => $cliente->id, 'producto_id' => $producto->id, 'estatus' => Suscripcion::ESTATUS_ACTIVO]);

        $this->expectException(RuntimeException::class);
        app(SuscripcionOnboardingService::class)->aprovisionar(
            $cliente, $producto, ['nombre' => 'Admin', 'email' => 'admin@acme.test']
        );
    }

    public function test_dedicada_sin_credenciales_admin_falla_antes_de_llamar_a_la_app(): void
    {
        Http::fake();
        $cliente = Cliente::create(['slug' => 'acme', 'nombre' => 'Acme', 'estatus' => Cliente::ESTATUS_ACTIVO]);
        $producto = Producto::create([
            'slug' => 'comercializa', 'nombre' => 'Comercializa', 'base_url_interna' => 'http://127.0.0.1:8100',
            'modo_datos' => Producto::MODO_DEDICADA, 'token_interno' => 'token-com', 'prefijo_db' => 'com',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TENANT_PROVISION_DB_ADMIN');
        app(SuscripcionOnboardingService::class)->aprovisionar(
            $cliente, $producto, ['nombre' => 'Admin', 'email' => 'admin@acme.test']
        );

        Http::assertNothingSent();
    }

    public function test_slug_con_guion_no_rompe_el_nombre_de_base_de_datos(): void
    {
        $this->assertSame('svi_pruebasvi', SuscripcionOnboardingService::nombreBaseDatos('svi', 'prueba-svi'));
        $this->assertSame('com_labormx', SuscripcionOnboardingService::nombreBaseDatos('com', 'labormx'));
    }

    /**
     * 22-sep-2026: Str::random() no garantiza mayúscula+minúscula+número --
     * la validación de complejidad de HRM rechazó una temporal generada así
     * en la prueba real. Se corre muchas veces porque es una garantía
     * estructural, no algo que una sola corrida pueda demostrar por azar.
     */
    public function test_password_temporal_siempre_cumple_complejidad_minima(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $password = SuscripcionOnboardingService::generarPasswordTemporal();

            $this->assertSame(16, strlen($password));
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
            $this->assertMatchesRegularExpression('/[!@#%*\-_=+]/', $password);
        }
    }
}
