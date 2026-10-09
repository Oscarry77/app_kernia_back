<?php

namespace Tests\Feature\Landlord;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use App\Models\Landlord\RespaldoBase;
use App\Models\Landlord\Suscripcion;
use App\Services\Boveda\BovedaService;
use App\Services\Landlord\RespaldoBaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Respaldo cifrado de la base del patrón A (09-oct-2026). Sin MySQL: un "mysqldump" falso escribe un SQL conocido. */
class RespaldoBaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private Suscripcion $s;
    private string $sql;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        foreach (['TENANT_PROVISION_DB_ADMIN_USERNAME' => 'kernia_provisioner', 'TENANT_PROVISION_DB_ADMIN_PASSWORD' => 'secreto-de-prueba'] as $k => $v) {
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
        $p = Producto::create(['slug' => 'comercializa', 'nombre' => 'Comercializa', 'base_url_interna' => 'http://x', 'modo_datos' => Producto::MODO_DEDICADA,
            'token_interno' => 't', 'prefijo_db' => 'com']);
        $c = Cliente::create(['slug' => 'acme', 'nombre' => 'ACME', 'estatus' => 'activo']);
        $this->s = Suscripcion::create(['cliente_id' => $c->id, 'producto_id' => $p->id, 'estatus' => 'activo',
            'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_database' => 'com_acme']);
        // ~2.5 MiB: obliga a cifrar en varios bloques de 1 MiB
        $this->sql = "-- volcado\n".str_repeat("INSERT INTO t VALUES ('dato confidencial');\n", 60000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (['TENANT_PROVISION_DB_ADMIN_USERNAME', 'TENANT_PROVISION_DB_ADMIN_PASSWORD'] as $k) {
            $_ENV[$k] = $_SERVER[$k] = '';
        }
        parent::tearDown();
    }

    /** El servicio con un "mysqldump" que imprime el SQL de la prueba y verifica que la contraseña le llegó por el entorno. */
    private function servicio(int $codigo = 0): RespaldoBaseService
    {
        $archivo = tempnam(sys_get_temp_dir(), 'sql');
        file_put_contents($archivo, $this->sql);
        $script = 'if (getenv("MYSQL_PWD") !== "secreto-de-prueba") { fwrite(STDERR, "sin clave"); exit(9); } readfile('.var_export($archivo, true).'); exit('.$codigo.');';

        return new class(app(BovedaService::class), $script) extends RespaldoBaseService {
            public function __construct(BovedaService $b, private string $script)
            {
                parent::__construct($b);
            }

            protected function comando(Suscripcion $s, string $admin): array
            {
                return [PHP_BINARY, '-r', $this->script];
            }
        };
    }

    public function test_respalda_cifrado_por_bloques_y_se_descifra_igual(): void
    {
        $r = $this->servicio()->crear($this->s, 'ajuste_plan');

        $this->assertSame(RespaldoBase::LISTO, $r->estado);
        $this->assertSame("respaldos_base/{$this->s->cliente_id}/{$r->id}.krnb", $r->ruta);
        $cifrado = Storage::disk('local')->get($r->ruta);
        $this->assertStringStartsWith('KRNB1', $cifrado);
        $this->assertStringNotContainsString('dato confidencial', $cifrado); // nunca en claro
        $this->assertSame(hash('sha256', $cifrado), $r->sha256);
        $this->assertTrue(app(BovedaService::class)->existe($r->claveBoveda()));

        $destino = tempnam(sys_get_temp_dir(), 'des');
        $this->servicio()->descifrar($r, $destino, 'prueba');
        $this->assertSame($this->sql, file_get_contents($destino));
        unlink($destino);
    }

    public function test_un_archivo_alterado_no_se_descifra(): void
    {
        $r = $this->servicio()->crear($this->s, 'manual');
        $bytes = Storage::disk('local')->get($r->ruta);
        $bytes[200] = chr(ord($bytes[200]) ^ 1);
        Storage::disk('local')->put($r->ruta, $bytes);

        $this->expectExceptionMessage('dañado o fue alterado');
        $this->servicio()->descifrar($r, tempnam(sys_get_temp_dir(), 'des'), 'prueba');
    }

    public function test_si_mysqldump_falla_no_queda_archivo_ni_llave(): void
    {
        try {
            $this->servicio(2)->crear($this->s, 'ajuste_plan');
            $this->fail('Debió fallar');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('código 2', $e->getMessage());
        }
        $r = RespaldoBase::sole();
        $this->assertSame(RespaldoBase::FALLIDO, $r->estado);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertFalse(app(BovedaService::class)->existe($r->claveBoveda()));
    }

    public function test_al_vencer_se_borra_el_archivo_y_la_llave(): void
    {
        $r = $this->servicio()->crear($this->s, 'manual');
        Carbon::setTestNow(now()->addDays(91));
        $this->assertSame(1, app(RespaldoBaseService::class)->eliminarVencidos());
        $this->assertSame([RespaldoBase::ELIMINADO, false], [$r->fresh()->estado, Storage::disk('local')->exists($r->ruta)]);
        $this->assertFalse(app(BovedaService::class)->existe($r->claveBoveda()));
    }

    public function test_solo_respalda_bases_del_patron_a(): void
    {
        $this->s->producto->update(['modo_datos' => Producto::MODO_COMPARTIDA]);
        $this->expectExceptionMessage('patrón A');
        $this->servicio()->crear($this->s->fresh(), 'manual');
    }
}
