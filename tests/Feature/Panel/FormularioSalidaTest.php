<?php

namespace Tests\Feature\Panel;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\EnlaceFormularioSalida;
use App\Models\Landlord\FormularioSalida;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\VigenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/** Formulario de salida: asesor obligatorio, cliente por enlace de un solo uso, vista de Dirección (08-oct-2026). */
class FormularioSalidaTest extends TestCase
{
    use RefreshDatabase;

    private LandlordAdmin $direccion;
    private LandlordAdmin $vendedor;
    private Producto $svi;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response('', 204)]);

        $this->direccion = $this->operador('dir', 'direccion');
        $this->vendedor = $this->operador('vend', 'vendedor');
        $this->svi = Producto::create(['slug' => 'svi', 'nombre' => 'Bridge SVI', 'base_url_interna' => 'http://svi.test',
            'modo_datos' => Producto::MODO_DEDICADA, 'token_interno' => 't', 'estatus_salida' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function operador(string $nombre, string $rol): LandlordAdmin
    {
        return LandlordAdmin::create(['nombre' => ucfirst($nombre), 'email' => "{$nombre}@kernia.test", 'rol' => $rol, 'password' => 'Clave-de-prueba-123!', 'activo' => true]);
    }

    private function como(LandlordAdmin $a): array
    {
        $this->app['auth']->forgetGuards();
        JWTAuth::unsetToken();
        $this->app['tymon.jwt']->unsetToken();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($a)];
    }

    private function suscripcion(string $estatus = 'activo'): Suscripcion
    {
        $c = Cliente::create(['slug' => 'acme', 'nombre' => 'ACME', 'estatus' => 'activo']);
        $c->operadores()->attach($this->vendedor->id);

        return Suscripcion::create(['cliente_id' => $c->id, 'producto_id' => $this->svi->id, 'estatus' => $estatus,
            'modalidad_pago' => 'anual', 'fecha_proximo_pago' => VigenciaService::hoy()->addDays(40)->toDateString()]);
    }

    /** Token del enlace a partir de la URL que devuelve el panel. */
    private function token(string $url): string
    {
        return substr($url, strrpos($url, '/') + 1);
    }

    public function test_el_asesor_debe_elegir_motivo_y_se_guarda_con_la_solicitud(): void
    {
        $s = $this->suscripcion();
        $h = $this->como($this->vendedor);

        $this->withHeaders($h)->postJson("/api/suscripciones/{$s->id}/salidas", ['tipo' => 'retiro', 'motivo' => 'x'])
            ->assertStatus(422)->assertJsonPath('message', 'Elige el motivo de salida del cliente.');
        $this->withHeaders($h)->postJson("/api/suscripciones/{$s->id}/salidas", ['tipo' => 'retiro', 'motivo_salida' => 'inventado', 'motivo' => 'x'])
            ->assertStatus(422);
        $this->assertSame(0, FormularioSalida::count());

        $id = $this->withHeaders($h)->postJson("/api/suscripciones/{$s->id}/salidas", ['tipo' => 'retiro', 'motivo_salida' => 'precio', 'motivo' => 'Le subió el presupuesto'])
            ->assertCreated()->json('data.id');

        $f = FormularioSalida::sole();
        $this->assertSame(['asesor', 'retiro', 'precio', 'Le subió el presupuesto', $id, $this->vendedor->id],
            [$f->origen, $f->evento, $f->motivo, $f->detalle, $f->solicitud_salida_id, $f->registrado_por]);

        $this->withHeaders($h)->getJson('/api/catalogo/motivos-salida')->assertOk()->assertJsonCount(8, 'data');
    }

    public function test_el_cliente_contesta_una_sola_vez_desde_su_enlace(): void
    {
        $s = $this->suscripcion();
        // Solo para una app que ya salió
        $this->withHeaders($this->como($this->vendedor))->postJson("/api/suscripciones/{$s->id}/formulario-salida/enlace")->assertStatus(422);

        $s->update(['estatus' => 'retirado']);
        $primero = $this->withHeaders($this->como($this->vendedor))->postJson("/api/suscripciones/{$s->id}/formulario-salida/enlace")
            ->assertOk()->json('data.url');
        $url = $this->withHeaders($this->como($this->vendedor))->postJson("/api/suscripciones/{$s->id}/formulario-salida/enlace")
            ->assertOk()->json('data.url');
        $this->assertStringStartsWith(rtrim(config('kernia.url_panel'), '/').'/salida/', $url);

        // El token nunca se guarda en claro; generar uno nuevo invalida el anterior
        $this->assertFalse(EnlaceFormularioSalida::where('token_hash', $this->token($url))->exists());
        $this->getJson('/api/publico/salida/'.$this->token($primero))->assertStatus(410);
        $this->getJson('/api/publico/salida/no-existe')->assertNotFound();

        // El cliente ve su nombre, la app y los motivos, sin "Falta de pago"
        $motivos = $this->getJson('/api/publico/salida/'.$this->token($url))->assertOk()
            ->assertJsonPath('data.cliente', 'ACME')->assertJsonPath('data.app', 'Bridge SVI')->json('data.motivos.*.clave');
        $this->assertNotContains('falta_pago', $motivos);

        $publico = '/api/publico/salida/'.$this->token($url);
        $this->postJson($publico, ['motivo' => 'falta_pago'])->assertStatus(422);
        $this->postJson($publico, ['motivo' => 'otro'])->assertStatus(422); // "Otro" sin detalle
        $this->postJson($publico, ['motivo' => 'precio', 'calificacion' => 6])->assertStatus(422);

        $this->postJson($publico, ['motivo' => 'faltan_funciones', 'detalle' => 'Necesitábamos timbrar nómina', 'calificacion' => 4,
            'mejora' => 'Más reportes', 'recomendaria' => true])->assertCreated();
        $this->postJson($publico, ['motivo' => 'precio'])->assertStatus(410);
        $this->getJson($publico)->assertStatus(410)->assertJsonPath('message', 'Ya recibimos tus respuestas. ¡Gracias!');

        $f = FormularioSalida::where('origen', 'cliente')->sole();
        $this->assertSame(['retiro', 'faltan_funciones', 4, true], [$f->evento, $f->motivo, $f->calificacion, $f->recomendaria]);
    }

    public function test_el_enlace_vence_a_los_30_dias(): void
    {
        $s = $this->suscripcion('en_finiquito');
        $url = $this->withHeaders($this->como($this->vendedor))->postJson("/api/suscripciones/{$s->id}/formulario-salida/enlace")->json('data.url');

        Carbon::setTestNow(now()->addDays(31));
        $this->getJson('/api/publico/salida/'.$this->token($url))->assertStatus(410)->assertJsonPath('message', 'Este enlace venció.');
    }

    public function test_direccion_ve_los_motivos_con_resumen_y_el_vendedor_no(): void
    {
        $s = $this->suscripcion();
        $base = ['cliente_id' => $s->cliente_id, 'suscripcion_id' => $s->id, 'evento' => 'retiro'];
        FormularioSalida::create([...$base, 'origen' => 'asesor', 'motivo' => 'precio']);
        FormularioSalida::create([...$base, 'origen' => 'cliente', 'motivo' => 'precio', 'calificacion' => 4, 'recomendaria' => true]);
        FormularioSalida::create([...$base, 'origen' => 'cliente', 'motivo' => 'servicio', 'calificacion' => 2, 'recomendaria' => false]);

        $this->withHeaders($this->como($this->vendedor))->getJson('/api/motivos-salida')->assertForbidden();

        $r = $this->withHeaders($this->como($this->direccion))->getJson('/api/motivos-salida')->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('resumen.total', 3)
            ->assertJsonPath('resumen.respuestas_cliente', 2)
            ->assertJsonPath('resumen.calificacion_promedio', 3)
            ->assertJsonPath('resumen.recomendaria_pct', 50);
        $precio = collect($r->json('resumen.por_motivo'))->firstWhere('motivo', 'precio');
        $this->assertSame([1, 1], [$precio['asesor'], $precio['cliente']]);

        $this->withHeaders($this->como($this->direccion))->getJson('/api/motivos-salida?origen=cliente')->assertJsonCount(2, 'data');
    }
}
