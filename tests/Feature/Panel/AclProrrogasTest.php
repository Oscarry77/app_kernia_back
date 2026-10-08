<?php

namespace Tests\Feature\Panel;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\NivelAutorizacion;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Prorroga;
use App\Models\Landlord\ProrrogaIntento;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\VigenciaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/** Fase 3 (02-oct-2026): roles, cartera, escalafón y prórrogas. */
class AclProrrogasTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Clave-de-prueba-123!';

    private LandlordAdmin $super;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*/estatus' => Http::response('', 204)]);
        $this->super = $this->operador('super', 'superadmin');
    }

    private function operador(string $nombre, string $rol): LandlordAdmin
    {
        return LandlordAdmin::create(['nombre' => ucfirst($nombre), 'email' => "{$nombre}@kernia.test", 'rol' => $rol, 'password' => self::PW, 'activo' => true]);
    }

    /** Cabecera del operador; limpia guardias y token en memoria para que cada petición se autentique sola. */
    private function como(LandlordAdmin $a): array
    {
        $this->app['auth']->forgetGuards();
        JWTAuth::unsetToken();
        $this->app['tymon.jwt']->unsetToken(); // el guardia usa su propia instancia de JWT

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($a)];
    }

    private function suscripcionSuspendidaPorVencimiento(string $slug = 'acme'): Suscripcion
    {
        $p = Producto::firstOrCreate(['slug' => 'comercializa'], ['nombre' => 'Comercializa', 'base_url_interna' => 'http://127.0.0.1:8100',
            'modo_datos' => Producto::MODO_COMPARTIDA, 'token_interno' => 't']);
        $c = Cliente::create(['slug' => $slug, 'nombre' => ucfirst($slug), 'estatus' => 'activo']);

        return Suscripcion::create(['cliente_id' => $c->id, 'producto_id' => $p->id, 'estatus' => 'suspendido',
            'suspension_motivo' => 'vencimiento', 'modalidad_pago' => 'mensual',
            'fecha_proximo_pago' => VigenciaService::hoy()->subDays(2)->toDateString()]);
    }

    public function test_permisos_por_rol(): void
    {
        $vendedor = $this->operador('vend', 'vendedor');
        $soporte = $this->operador('sop', 'soporte');

        $this->withHeaders($this->como($vendedor))->getJson('/api/operadores')->assertForbidden()->assertJsonPath('codigo', 'SIN_PERMISO');
        $this->withHeaders($this->como($vendedor))->getJson('/api/auditoria')->assertForbidden();
        $this->withHeaders($this->como($soporte))->postJson('/api/clientes', [])->assertForbidden();
        $this->withHeaders($this->como($this->super))->getJson('/api/auditoria')->assertOk();

        $this->withHeaders($this->como($vendedor))->getJson('/api/auth/me')
            ->assertJsonPath('rol', 'vendedor')->assertJsonPath('cartera', true)
            ->assertJsonPath('permisos', ['clientes.ver', 'clientes.crear', 'clientes.editar', 'prorrogas.solicitar', 'planes.solicitar']);

        // Desactivado: pierde el acceso aunque su token siga vigente
        $vendedor->update(['activo' => false]);
        $this->withHeaders($this->como($vendedor))->getJson('/api/clientes')->assertForbidden();
    }

    public function test_cartera_del_vendedor(): void
    {
        $vendedor = $this->operador('vend', 'vendedor');
        $suya = $this->suscripcionSuspendidaPorVencimiento('suya');
        $ajena = $this->suscripcionSuspendidaPorVencimiento('ajena');
        $vendedor->cartera()->attach($suya->cliente_id);

        $h = fn () => $this->withHeaders($this->como($vendedor));
        $h()->getJson('/api/clientes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'suya');
        $h()->getJson("/api/clientes/{$suya->cliente_id}")->assertOk();
        $h()->getJson("/api/clientes/{$ajena->cliente_id}")->assertNotFound();
        $h()->getJson("/api/suscripciones/{$ajena->id}/pagos")->assertNotFound();
        $h()->postJson("/api/suscripciones/{$ajena->id}/prorrogas", ['dias' => 2, 'motivo' => 'promesa_pago'])->assertNotFound();
        $this->assertCount(1, $h()->getJson('/api/vigencias')->json('data'));

        // El cliente que da de alta queda en su cartera
        $id = $h()->postJson('/api/clientes', ['slug' => 'nuevo', 'tipo_persona' => 'moral', 'rfc' => 'NUE010101AB1',
            'razon_social' => 'Nuevo', 'regimen_capital' => 'SA DE CV', 'regimen_fiscal' => '601', 'codigo_postal' => '01000',
            'entidad_federativa' => 'NUEVO LEÓN', 'correo' => 'n@nuevo.test'])->assertCreated()->json('data.id');
        $this->assertTrue($vendedor->cartera()->whereKey($id)->exists());

        // Los demás roles ven a todos
        $this->withHeaders($this->como($this->super))->getJson('/api/clientes')->assertJsonCount(3, 'data');
    }

    public function test_gestion_de_operadores_respeta_jerarquia(): void
    {
        $gerente = $this->operador('ger', 'gerente');

        $this->withHeaders($this->como($gerente))->postJson('/api/operadores', ['nombre' => 'X', 'email' => 'x@kernia.test', 'rol' => 'direccion'])
            ->assertStatus(422);
        $r = $this->withHeaders($this->como($gerente))->postJson('/api/operadores', ['nombre' => 'V', 'email' => 'v@kernia.test', 'rol' => 'vendedor'])
            ->assertCreated();
        $this->assertSame(18, strlen($r->json('password_temporal'))); // correo no configurado en pruebas: se muestra una vez
        $this->withHeaders($this->como($gerente))->putJson("/api/operadores/{$this->super->id}", ['nombre' => 'S', 'rol' => 'vendedor', 'activo' => true])
            ->assertForbidden();

        // Último superadmin y uno mismo
        $this->withHeaders($this->como($this->super))->putJson("/api/operadores/{$this->super->id}", ['nombre' => 'S', 'rol' => 'direccion', 'activo' => true])
            ->assertStatus(422);

        // Cartera solo para vendedores
        $c = Cliente::create(['slug' => 'c1', 'nombre' => 'C1', 'estatus' => 'activo']);
        $vid = $r->json('data.id');
        $this->withHeaders($this->como($gerente))->putJson("/api/operadores/{$vid}/cartera", ['clientes' => [$c->id]])->assertOk()->assertJsonCount(1, 'data');
        $this->withHeaders($this->como($gerente))->putJson("/api/operadores/{$gerente->id}/cartera", ['clientes' => [$c->id]])->assertStatus(422);
    }

    public function test_prorroga_reglas_de_autorizacion(): void
    {
        $vendedor = $this->operador('vend', 'vendedor');
        $gerente = $this->operador('ger', 'gerente');
        $director = $this->operador('dir', 'direccion');
        $s = $this->suscripcionSuspendidaPorVencimiento();
        $vendedor->cartera()->attach($s->cliente_id);

        // Solo suspendidas por vencimiento; máximo 6 días
        $this->withHeaders($this->como($vendedor))->postJson("/api/suscripciones/{$s->id}/prorrogas", ['dias' => 7, 'motivo' => 'promesa_pago'])->assertStatus(422);
        $pid = $this->withHeaders($this->como($vendedor))->postJson("/api/suscripciones/{$s->id}/prorrogas", ['dias' => 4, 'motivo' => 'promesa_pago'])
            ->assertCreated()->json('data.id');
        $this->withHeaders($this->como($vendedor))->postJson("/api/suscripciones/{$s->id}/prorrogas", ['dias' => 2, 'motivo' => 'promesa_pago'])->assertStatus(422);

        $resolver = fn (LandlordAdmin $sesion, string $email, string $pw = self::PW) => $this->withHeaders($this->como($sesion))
            ->postJson("/api/prorrogas/{$pid}/resolver", ['accion' => 'autorizar', 'email' => $email, 'password' => $pw]);

        // Sin escalafón: solo superadmin
        $resolver($vendedor, 'ger@kernia.test')->assertStatus(422)->assertJsonFragment(['message' => 'Aún no hay escalafón capturado: solo el superadministrador puede resolver.']);
        // Credenciales incorrectas
        $resolver($vendedor, 'ger@kernia.test', 'mala')->assertStatus(422);
        // Escalafón: gerente nivel 1 hasta 3 días, dirección nivel 2 hasta 6
        NivelAutorizacion::create(['nivel' => 1, 'puesto' => 'Gerente', 'usuario_id' => $gerente->id, 'dias_max' => 3]);
        NivelAutorizacion::create(['nivel' => 2, 'puesto' => 'Director', 'usuario_id' => $director->id, 'dias_max' => 6]);
        $resolver($vendedor, 'ger@kernia.test')->assertStatus(422); // 4 días > 3
        $r = $resolver($vendedor, 'dir@kernia.test')->assertOk()->assertJsonPath('data.estado', 'autorizada')->assertJsonPath('app_confirmo', true);

        $hoy = VigenciaService::hoy();
        $r->assertJsonPath('data.desde', $hoy->toDateString())->assertJsonPath('data.hasta', $hoy->addDays(3)->toDateString());
        $s->refresh();
        $this->assertSame('activo', $s->estatus);
        $this->assertSame($hoy->addDays(3)->toDateString(), $s->activa_hasta->toDateString());

        // Bitácora de intentos sin contraseñas
        $this->assertSame(['sin_nivel', 'credencial_invalida', 'nivel_insuficiente', 'autorizada'],
            ProrrogaIntento::orderBy('id')->pluck('resultado')->all());
        $this->assertStringNotContainsString(self::PW, ProrrogaIntento::all()->toJson());
    }

    public function test_no_se_autoriza_la_propia_y_la_segunda_requiere_nivel_mayor(): void
    {
        $gerente = $this->operador('ger', 'gerente');
        $director = $this->operador('dir', 'direccion');
        NivelAutorizacion::create(['nivel' => 1, 'puesto' => 'Gerente', 'usuario_id' => $gerente->id, 'dias_max' => 6]);
        NivelAutorizacion::create(['nivel' => 2, 'puesto' => 'Director', 'usuario_id' => $director->id, 'dias_max' => 6]);
        $s = $this->suscripcionSuspendidaPorVencimiento();

        $pid = $this->withHeaders($this->como($gerente))->postJson("/api/suscripciones/{$s->id}/prorrogas", ['dias' => 1, 'motivo' => 'negociacion'])->json('data.id');
        $this->withHeaders($this->como($gerente))->postJson("/api/prorrogas/{$pid}/resolver", ['accion' => 'autorizar', 'email' => 'ger@kernia.test', 'password' => self::PW])
            ->assertStatus(422);
        $this->withHeaders($this->como($director))->postJson("/api/prorrogas/{$pid}/resolver", ['accion' => 'autorizar', 'email' => 'ger@kernia.test', 'password' => self::PW])
            ->assertStatus(422); // sigue siendo la propia del gerente, aunque la sesión sea otra
        // El dueño de la sesión no importa: teclea el director
        $this->withHeaders($this->como($gerente))->postJson("/api/prorrogas/{$pid}/resolver", ['accion' => 'autorizar', 'email' => 'dir@kernia.test', 'password' => self::PW])
            ->assertOk();

        // Termina la prórroga sin pago: el corte la marca vencida y re-suspende
        $this->travelTo(CarbonImmutable::now(config('kernia.zona_horaria'))->addDays(2));
        $this->artisan('landlord:procesar-vencimientos')->assertSuccessful();
        $this->assertSame('vencida', Prorroga::find($pid)->estado);
        $this->assertSame('suspendido', $s->fresh()->estatus);

        // Segunda prórroga del mismo vencimiento: el gerente (nivel 1) ya no puede; el superadmin sí
        $pid2 = $this->withHeaders($this->como($director))->postJson("/api/suscripciones/{$s->id}/prorrogas", ['dias' => 1, 'motivo' => 'negociacion'])->json('data.id');
        $this->withHeaders($this->como($director))->postJson("/api/prorrogas/{$pid2}/resolver", ['accion' => 'autorizar', 'email' => 'ger@kernia.test', 'password' => self::PW])
            ->assertStatus(422);
        $this->withHeaders($this->como($director))->postJson("/api/prorrogas/{$pid2}/resolver", ['accion' => 'autorizar', 'email' => 'super@kernia.test', 'password' => self::PW])
            ->assertOk();

        // El pago cierra la prórroga
        $this->withHeaders($this->como($director))->postJson("/api/suscripciones/{$s->id}/pagos", ['referencia' => 'SPEI 1'])->assertCreated();
        $this->assertSame('cerrada_por_pago', Prorroga::find($pid2)->estado);
        $this->assertNull($s->fresh()->activa_hasta);
    }

    public function test_rechazo_exige_comentario_y_pendientes_respeta_cartera(): void
    {
        $vendedor = $this->operador('vend', 'vendedor');
        $s = $this->suscripcionSuspendidaPorVencimiento('suya');
        $otra = $this->suscripcionSuspendidaPorVencimiento('ajena');
        $vendedor->cartera()->attach($s->cliente_id);

        $pid = $this->withHeaders($this->como($vendedor))->postJson("/api/suscripciones/{$s->id}/prorrogas", ['dias' => 2, 'motivo' => 'pago_en_tramite'])->json('data.id');
        $this->withHeaders($this->como($this->super))->postJson("/api/suscripciones/{$otra->id}/prorrogas", ['dias' => 2, 'motivo' => 'otro', 'detalle' => 'x']);

        $this->withHeaders($this->como($vendedor))->getJson('/api/prorrogas/pendientes')->assertJsonCount(1, 'data');
        $this->withHeaders($this->como($this->super))->getJson('/api/prorrogas/pendientes')->assertJsonCount(2, 'data');

        $this->withHeaders($this->como($vendedor))->postJson("/api/prorrogas/{$pid}/resolver", ['accion' => 'rechazar', 'email' => 'super@kernia.test', 'password' => self::PW])
            ->assertStatus(422);
        $this->withHeaders($this->como($vendedor))->postJson("/api/prorrogas/{$pid}/resolver", ['accion' => 'rechazar', 'email' => 'super@kernia.test', 'password' => self::PW, 'comentario' => 'Sin compromiso de pago'])
            ->assertOk()->assertJsonPath('data.estado', 'rechazada');
        $this->assertSame('suspendido', $s->fresh()->estatus);
    }
}
