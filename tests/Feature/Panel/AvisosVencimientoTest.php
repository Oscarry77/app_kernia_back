<?php

namespace Tests\Feature\Panel;

use App\Models\Landlord\AvisoVencimiento;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\CorreoEnviado;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\VigenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Avisos de vencimiento por correo (09-oct-2026, fase 4). */
class AvisosVencimientoTest extends TestCase
{
    use RefreshDatabase;

    private Suscripcion $s;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp']);
        Mail::fake();
        Http::fake(['*' => Http::response('', 204)]);

        $vendedor = LandlordAdmin::create(['nombre' => 'Vend', 'email' => 'vend@kernia.test', 'rol' => 'vendedor', 'password' => 'x-Clave-123!', 'activo' => true]);
        LandlordAdmin::create(['nombre' => 'Dir', 'email' => 'dir@kernia.test', 'rol' => 'direccion', 'password' => 'x-Clave-123!', 'activo' => true]);
        $p = Producto::create(['slug' => 'svi', 'nombre' => 'Bridge SVI', 'base_url_interna' => 'http://svi.test', 'modo_datos' => Producto::MODO_DEDICADA, 'token_interno' => 't']);
        $c = Cliente::create(['slug' => 'acme', 'nombre' => 'ACME', 'estatus' => 'activo', 'correo' => 'pagos@acme.test']);
        $c->operadores()->attach($vendedor->id);
        $this->s = Suscripcion::create(['cliente_id' => $c->id, 'producto_id' => $p->id, 'estatus' => 'activo', 'admin_email' => 'admin@acme.test',
            'modalidad_pago' => 'anual', 'suspension_automatica' => true, 'fecha_proximo_pago' => VigenciaService::hoy()->addDays(31)->toDateString()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Avanza N días y corre el corte de las 00:00 y los avisos de las 08:00. */
    private function dia(int $n = 1): void
    {
        Carbon::setTestNow(now()->addDays($n));
        Artisan::call('landlord:procesar-vencimientos');
        Artisan::call('landlord:avisos-vencimiento');
    }

    private function hitos(): array
    {
        return AvisoVencimiento::orderBy('id')->pluck('hito')->all();
    }

    public function test_un_aviso_por_tramo_hasta_la_suspension(): void
    {
        $this->dia(0);                    // 31 días: aún nada
        $this->assertSame([], $this->hitos());

        for ($i = 0; $i < 31; $i++) {     // día a día hasta el vencimiento
            $this->dia();
        }
        $this->dia();                     // el día siguiente ya está suspendida (corte de las 00:00 del día de pago)
        $this->assertSame(['30', '15', '7', '3', '1', 'suspendida'], $this->hitos());

        // El cliente recibe en su correo de administrador y en el fiscal; el asesor, copia de todo; Dirección, solo lo urgente.
        $this->assertSame(6, CorreoEnviado::where('destinatario', 'admin@acme.test')->count());
        $this->assertSame(6, CorreoEnviado::where('destinatario', 'pagos@acme.test')->count());
        $this->assertSame(6, CorreoEnviado::where('destinatario', 'vend@kernia.test')->count());
        $this->assertSame(3, CorreoEnviado::where('destinatario', 'dir@kernia.test')->count()); // 3, 1 y suspendida
        $this->assertTrue(CorreoEnviado::where('plantilla', 'aviso_vencimiento')->where('asunto', 'like', '%está suspendido%')->exists());
    }

    public function test_si_el_servidor_estuvo_abajo_solo_va_el_mas_urgente(): void
    {
        $this->dia(28);                   // 3 días antes: brincó 30, 15 y 7
        $this->assertSame(['3'], $this->hitos());
        $this->dia();                     // 2 días: sigue en el tramo de 3, nada nuevo
        $this->assertSame(['3'], $this->hitos());
    }

    public function test_sin_correo_disponible_se_reintenta_sin_copias(): void
    {
        config(['mail.default' => 'kernia']); // sin bóveda: el correo de Kernia no está disponible
        $this->dia(1);
        $this->assertSame([], $this->hitos());
        $this->assertTrue(CorreoEnviado::where('estado', 'omitido')->where('destinatario', 'admin@acme.test')->exists());
        $this->assertFalse(CorreoEnviado::where('destinatario', 'vend@kernia.test')->exists());

        config(['mail.default' => 'smtp']);
        $this->dia(0);
        $this->assertSame(['30'], $this->hitos());
    }

    public function test_un_pago_empieza_otro_ciclo_y_no_comerciales_ni_prorroga_no_reciben(): void
    {
        $this->dia(1);
        $this->assertSame(['30'], $this->hitos());

        // Pago: la fecha se recorre un año; el ciclo nuevo no tiene avisos
        app(VigenciaService::class)->registrarPago($this->s->fresh(), ['referencia' => 'X1'], null);
        $this->dia(0);
        $this->assertSame(1, AvisoVencimiento::count());

        // Un cliente de demo nunca recibe
        $this->s->cliente->update(['tipo' => 'demo']);
        $this->s->update(['fecha_proximo_pago' => VigenciaService::hoy()->addDays(2)->toDateString()]);
        $this->dia(0);
        $this->assertSame(1, AvisoVencimiento::count());
    }

    public function test_el_panel_muestra_el_ultimo_aviso(): void
    {
        $this->dia(1);
        $p = app(\App\Services\Landlord\PanelPresenter::class)->suscripcion($this->s->fresh());
        $this->assertSame('30', $p['ultimo_aviso_correo']['hito']);
    }
}
