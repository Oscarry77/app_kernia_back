<?php

namespace Tests\Feature\Landlord;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\DiaInhabil;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\SuscripcionEstatusService;
use App\Services\Landlord\VigenciaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Vigencias, corte de vencimientos, pagos y aviso (fase 2, 02-oct-2026). */
class VigenciaServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*/estatus' => Http::response('', 204)]);
    }

    private function suscripcion(array $extra = []): Suscripcion
    {
        $p = Producto::firstOrCreate(['slug' => 'svi'], ['nombre' => 'SVI', 'base_url_interna' => 'http://127.0.0.1:8400',
            'modo_datos' => Producto::MODO_DEDICADA, 'token_interno' => 't']);
        $c = Cliente::create(['slug' => 'c'.uniqid(), 'nombre' => 'C', 'estatus' => 'activo']);

        return Suscripcion::create(['cliente_id' => $c->id, 'producto_id' => $p->id, 'estatus' => 'activo',
            'modalidad_pago' => 'mensual', 'suspension_automatica' => true, ...$extra]);
    }

    /** "Ahora" en hora de México. */
    private function enMexico(string $fechaHora): void
    {
        $this->travelTo(CarbonImmutable::parse($fechaHora, 'America/Mexico_City'));
    }

    private function servicio(): VigenciaService
    {
        return app(VigenciaService::class);
    }

    public function test_sin_fecha_de_proximo_pago_nunca_se_suspende(): void
    {
        $s = $this->suscripcion(['fecha_proximo_pago' => null]);
        $this->enMexico('2030-01-01 12:00');

        $this->assertSame([], $this->servicio()->procesarVencimientos());
        $this->assertSame('activo', $s->fresh()->estatus);
    }

    public function test_corte_a_las_00_00_hora_de_mexico_y_es_idempotente(): void
    {
        $s = $this->suscripcion(['fecha_proximo_pago' => '2026-10-15']);

        $this->enMexico('2026-10-14 23:59');
        $this->assertSame([], $this->servicio()->procesarVencimientos());
        $this->assertSame(1, $this->servicio()->diasRestantes($s->fresh()));

        $this->enMexico('2026-10-15 00:00');
        $this->assertCount(1, $this->servicio()->procesarVencimientos());
        $this->assertSame('suspendido', $s->fresh()->estatus);
        $this->assertSame(SuscripcionEstatusService::CAUSA_VENCIMIENTO, $s->fresh()->suspension_motivo);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['estatus'] === 'suspendido');

        $this->assertSame([], $this->servicio()->procesarVencimientos(), 'Una segunda corrida no hace nada.');
    }

    public function test_gracia_prorroga_y_suspension_automatica_apagada(): void
    {
        $gracia = $this->suscripcion(['fecha_proximo_pago' => '2026-10-15', 'dias_gracia' => 2]);
        $prorroga = $this->suscripcion(['fecha_proximo_pago' => '2026-10-15', 'activa_hasta' => '2026-10-18']);
        $manual = $this->suscripcion(['fecha_proximo_pago' => '2026-10-15', 'suspension_automatica' => false]);

        $this->enMexico('2026-10-16 08:00');
        $this->servicio()->procesarVencimientos();
        $this->assertSame('activo', $gracia->fresh()->estatus);
        $this->assertSame('activo', $prorroga->fresh()->estatus);
        $this->assertSame('activo', $manual->fresh()->estatus);

        $this->enMexico('2026-10-17 00:00');
        $this->servicio()->procesarVencimientos();
        $this->assertSame('suspendido', $gracia->fresh()->estatus);
        $this->assertSame('activo', $prorroga->fresh()->estatus);

        $this->enMexico('2026-10-19 00:00');
        $this->servicio()->procesarVencimientos();
        $this->assertSame('suspendido', $prorroga->fresh()->estatus, 'Al vencer la prórroga, se suspende.');
        $this->assertSame('activo', $manual->fresh()->estatus);
    }

    public function test_pago_recorre_la_fecha_segun_modalidad_y_reactiva_solo_si_fue_por_vencimiento(): void
    {
        $s = $this->suscripcion(['fecha_proximo_pago' => '2026-01-31', 'modalidad_pago' => 'mensual']);
        $this->enMexico('2026-01-31 01:00');
        $this->servicio()->procesarVencimientos();
        $this->assertSame('suspendido', $s->fresh()->estatus);

        $r = $this->servicio()->registrarPago($s->fresh(), ['referencia' => 'TRF-1', 'monto' => 1500], null);
        $this->assertTrue($r['reactivada']);
        $this->assertSame('2026-02-28', $s->fresh()->fecha_proximo_pago->toDateString(), 'Sin desbordar a marzo.');
        $this->assertSame('activo', $s->fresh()->estatus);
        $this->assertSame('2026-01-31', $r['pago']->periodo_desde->toDateString());

        // Una suspensión manual no se levanta con un pago
        $anual = $this->suscripcion(['fecha_proximo_pago' => '2026-03-01', 'modalidad_pago' => 'anual']);
        app(SuscripcionEstatusService::class)->cambiarEstatus($anual, 'suspendido', 'incumplimiento');
        $r = $this->servicio()->registrarPago($anual->fresh(), ['referencia' => 'TRF-2'], null);
        $this->assertFalse($r['reactivada']);
        $this->assertSame('suspendido', $anual->fresh()->estatus);
        $this->assertSame('2027-03-01', $anual->fresh()->fecha_proximo_pago->toDateString());
    }

    public function test_pago_sin_vigencia_definida_se_rechaza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->servicio()->registrarPago($this->suscripcion(['fecha_proximo_pago' => null]), ['referencia' => 'X'], null);
    }

    public function test_niveles_del_aviso_con_dias_habiles_y_dias_inhabiles(): void
    {
        // Lunes 5-oct-2026
        $this->enMexico('2026-10-05 10:00');
        $lejos = $this->suscripcion(['fecha_proximo_pago' => '2026-11-20']);
        $info = $this->suscripcion(['fecha_proximo_pago' => '2026-10-25']);
        // Del martes 6 al lunes 12 hay 5 días hábiles (sin sábado ni domingo)
        $adv = $this->suscripcion(['fecha_proximo_pago' => '2026-10-12']);
        // Del martes 6 al martes 13 hay 6 hábiles... salvo que el 13 sea inhábil
        $inhabil = $this->suscripcion(['fecha_proximo_pago' => '2026-10-13']);
        $prorroga = $this->suscripcion(['fecha_proximo_pago' => '2026-10-01', 'activa_hasta' => '2026-10-07']);

        $this->assertNull($this->servicio()->aviso($lejos));
        $this->assertSame('info', $this->servicio()->aviso($info)['nivel']);
        $this->assertSame('advertencia', $this->servicio()->aviso($adv)['nivel']);
        $this->assertSame('info', $this->servicio()->aviso($inhabil)['nivel']);
        DiaInhabil::create(['fecha' => '2026-10-07', 'descripcion' => 'Prueba']);
        $this->assertSame('advertencia', $this->servicio()->aviso($inhabil)['nivel']);
        $this->assertSame('critico', $this->servicio()->aviso($prorroga)['nivel']);
    }
}
