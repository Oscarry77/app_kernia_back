<?php

namespace Tests\Feature\Panel;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\FormularioSalida;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Prorroga;
use App\Models\Landlord\SolicitudPlan;
use App\Models\Landlord\SolicitudSalida;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\VigenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * (08-oct-2026) A pedido del dueño: un vendedor no ve NADA de los clientes de
 * otro vendedor, en ninguna pantalla ni ruta (listas, detalle, sub-recursos y
 * autorizaciones). Demo y capacitación sí son compartidos (decisión del 05-oct).
 */
class CarteraAislamientoTest extends TestCase
{
    use RefreshDatabase;

    private LandlordAdmin $ana;
    private LandlordAdmin $beto;
    private Suscripcion $deAna;
    private Suscripcion $deBeto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = $this->operador('ana');
        $this->beto = $this->operador('beto');
        $producto = Producto::create(['slug' => 'svi', 'nombre' => 'Bridge SVI', 'base_url_interna' => 'http://svi.test',
            'modo_datos' => Producto::MODO_DEDICADA, 'token_interno' => 't', 'estatus_salida' => true]);

        $this->deAna = $this->clienteDe($this->ana, 'cliente-ana', $producto);
        $this->deBeto = $this->clienteDe($this->beto, 'cliente-beto', $producto);

        // Algo pendiente en cada cliente de Beto, para que aparezca en las listas si hubiera fuga.
        SolicitudPlan::create(['suscripcion_id' => $this->deBeto->id, 'plan_nuevo' => 'x', 'direccion' => 'bajada', 'aplicacion' => 'renovacion',
            'motivo' => 'x', 'estado' => 'solicitada', 'solicitada_por' => $this->beto->id]);
        SolicitudSalida::create(['suscripcion_id' => $this->deBeto->id, 'tipo' => 'retiro', 'motivo' => 'x', 'estado' => 'solicitada', 'solicitada_por' => $this->beto->id]);
        Prorroga::create(['suscripcion_id' => $this->deBeto->id, 'fecha_vencimiento' => now()->toDateString(), 'dias' => 2, 'motivo' => 'otro', 'estado' => 'solicitada']);
        FormularioSalida::create(['cliente_id' => $this->deBeto->cliente_id, 'suscripcion_id' => $this->deBeto->id, 'origen' => 'asesor', 'evento' => 'retiro', 'motivo' => 'precio']);
    }

    private function operador(string $nombre): LandlordAdmin
    {
        return LandlordAdmin::create(['nombre' => ucfirst($nombre), 'email' => "{$nombre}@kernia.test", 'rol' => 'vendedor', 'password' => 'Clave-de-prueba-123!', 'activo' => true]);
    }

    private function clienteDe(LandlordAdmin $vendedor, string $slug, Producto $producto): Suscripcion
    {
        $c = Cliente::create(['slug' => $slug, 'nombre' => strtoupper($slug), 'estatus' => 'activo']);
        $c->operadores()->attach($vendedor->id);

        return Suscripcion::create(['cliente_id' => $c->id, 'producto_id' => $producto->id, 'estatus' => 'activo',
            'modalidad_pago' => 'anual', 'fecha_proximo_pago' => VigenciaService::hoy()->addDays(10)->toDateString()]);
    }

    private function como(LandlordAdmin $a): array
    {
        $this->app['auth']->forgetGuards();
        JWTAuth::unsetToken();
        $this->app['tymon.jwt']->unsetToken();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($a)];
    }

    public function test_un_vendedor_no_ve_nada_de_la_cartera_de_otro(): void
    {
        $h = $this->como($this->ana);
        $beto = $this->deBeto;

        // Listas: solo aparece el cliente de Ana
        $this->withHeaders($h)->getJson('/api/clientes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'cliente-ana');
        $this->withHeaders($h)->getJson('/api/vigencias?filtro=todas')->assertOk()->assertJsonCount(1, 'data');
        $this->withHeaders($h)->getJson('/api/cambios-plan/pendientes')->assertOk()->assertJsonCount(0, 'data');
        $this->withHeaders($h)->getJson('/api/salidas/pendientes')->assertOk()->assertJsonCount(0, 'data');
        $this->withHeaders($h)->getJson('/api/prorrogas/pendientes')->assertOk()->assertJsonCount(0, 'data');

        // Detalle y sub-recursos del cliente de Beto: 404, como si no existiera
        foreach ([
            "/api/clientes/{$beto->cliente_id}",
            "/api/suscripciones/{$beto->id}/pagos",
            "/api/suscripciones/{$beto->id}/prorrogas",
            "/api/suscripciones/{$beto->id}/cambios-plan",
            "/api/suscripciones/{$beto->id}/salidas",
            "/api/suscripciones/{$beto->id}/metricas",
        ] as $ruta) {
            $this->withHeaders($h)->getJson($ruta)->assertNotFound();
        }

        // Tampoco puede actuar sobre él
        $sp = SolicitudPlan::sole()->id;
        $ss = SolicitudSalida::sole()->id;
        $this->withHeaders($h)->putJson("/api/clientes/{$beto->cliente_id}", ['nombre' => 'X'])->assertNotFound();
        $this->withHeaders($h)->postJson("/api/suscripciones/{$beto->id}/salidas", ['tipo' => 'retiro', 'motivo_salida' => 'precio', 'motivo' => 'x'])->assertNotFound();
        $this->withHeaders($h)->postJson("/api/suscripciones/{$beto->id}/cambios-plan", ['plan' => 'x', 'motivo' => 'x'])->assertNotFound();
        $this->withHeaders($h)->postJson("/api/cambios-plan/{$sp}/cancelar", ['motivo' => 'x'])->assertNotFound();
        $this->withHeaders($h)->postJson("/api/salidas/{$ss}/cancelar", ['motivo' => 'x'])->assertNotFound();
        $this->withHeaders($h)->postJson("/api/prorrogas/".Prorroga::sole()->id.'/resolver', ['accion' => 'rechazar', 'email' => 'x', 'password' => 'x'])->assertNotFound();

        // Motivos de salida y bitácora no son para vendedores
        $this->withHeaders($h)->getJson('/api/motivos-salida')->assertForbidden();
        $this->withHeaders($h)->getJson('/api/auditoria')->assertForbidden();
        $this->withHeaders($h)->getJson('/api/correos')->assertForbidden();

        // Nada cambió en lo de Beto
        $this->assertSame('solicitada', SolicitudSalida::sole()->estado);
        $this->assertSame('solicitada', SolicitudPlan::sole()->estado);
    }

    public function test_demo_y_capacitacion_son_compartidos_y_prueba_no(): void
    {
        foreach (['demo-uno' => 'demo', 'cap-uno' => 'capacitacion', 'prueba-uno' => 'prueba'] as $slug => $tipo) {
            Cliente::create(['slug' => $slug, 'nombre' => $slug, 'estatus' => 'activo', 'tipo' => $tipo]);
        }

        $slugs = collect($this->withHeaders($this->como($this->ana))->getJson('/api/clientes')->assertOk()->json('data'))->pluck('slug')->sort()->values()->all();
        $this->assertSame(['cap-uno', 'cliente-ana', 'demo-uno'], $slugs);
    }
}
