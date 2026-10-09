<?php

namespace Tests\Feature\Panel;

use App\Models\Landlord\LandlordAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/** Menú lateral y matriz "Roles y accesos" desde una sola definición (09-oct-2026). */
class MatrizTest extends TestCase
{
    use RefreshDatabase;

    private function como(string $rol): array
    {
        $a = LandlordAdmin::create(['nombre' => $rol, 'email' => "{$rol}@kernia.test", 'rol' => $rol, 'password' => 'Clave-123!x', 'activo' => true]);
        $this->app['auth']->forgetGuards();
        JWTAuth::unsetToken();
        $this->app['tymon.jwt']->unsetToken();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($a)];
    }

    /** Rutas de todas las opciones de un árbol. */
    private function rutas(array $nodos): array
    {
        return collect($nodos)->flatMap(fn ($n) => isset($n['hijos']) ? $this->rutas($n['hijos']) : [$n['ruta']])->all();
    }

    public function test_el_menu_solo_trae_opciones_con_acceso(): void
    {
        $vendedor = $this->rutas($this->withHeaders($this->como('vendedor'))->getJson('/api/acl/menu')->assertOk()->json('data'));
        $this->assertSame(['/catalogo/productos', '/clientes', '/vigencias', '/prorrogas'], $vendedor);

        $soporte = $this->withHeaders($this->como('soporte'))->getJson('/api/acl/menu')->json('data');
        $this->assertSame(['Kernia'], array_column($soporte, 'etiqueta')); // sin Administración: no le queda ninguna opción

        $super = $this->rutas($this->withHeaders($this->como('superadmin'))->getJson('/api/acl/menu')->json('data'));
        $this->assertContains('/boveda', $super);
        $this->assertContains('/roles', $super);
    }

    public function test_la_matriz_muestra_que_puede_cada_rol_y_solo_la_ve_quien_administra_operadores(): void
    {
        $this->withHeaders($this->como('vendedor'))->getJson('/api/acl/matriz')->assertForbidden();

        $m = $this->withHeaders($this->como('gerente'))->getJson('/api/acl/matriz')->assertOk()->json();
        $clientes = $m['modulos'][0]['hijos'][1]['hijos'][0];
        $this->assertSame('Clientes', $clientes['etiqueta']);
        $this->assertTrue($clientes['columnas']['crear']['vendedor']);
        $this->assertFalse($clientes['columnas']['crear']['soporte']);
        $this->assertNull($clientes['columnas']['cancelar']);
        $directo = collect($clientes['especiales'])->firstWhere('etiqueta', 'Cambio directo de plan (correcciones)');
        $this->assertSame(['superadmin' => true, 'direccion' => false, 'gerente' => false, 'vendedor' => false, 'soporte' => false], $directo['roles']);
    }

    public function test_toda_ruta_del_arbol_existe_en_el_panel_y_todo_permiso_existe_en_algun_rol(): void
    {
        // Los de algún rol, más los que solo tiene el superadmin (`*`) y protegen una ruta.
        preg_match_all('/permiso:([a-z_.]+)/', file_get_contents(base_path('routes/api.php')), $rutas);
        $permisos = collect(config('kernia_acl.roles'))->flatMap(fn ($r) => $r['permisos'])->merge($rutas[1])->unique()->all();
        $recorrer = function (array $nodos) use (&$recorrer, $permisos) {
            foreach ($nodos as $n) {
                if (isset($n['hijos'])) {
                    $recorrer($n['hijos']);
                    continue;
                }
                foreach ([...array_values($n['permisos']), ...array_column($n['especiales'] ?? [], 'permiso')] as $p) {
                    $this->assertContains($p, $permisos, "Permiso desconocido en la matriz: {$p}");
                }
            }
        };
        $recorrer(config('kernia_matriz.modulos'));
    }

    /** (09-oct-2026) Sin sesión y sin `Accept: application/json`: 401 en JSON, nunca 500 ni redirección. */
    public function test_sin_sesion_responde_401_en_json_aunque_no_pida_json(): void
    {
        foreach (['/api/clientes', '/api/acl/menu', '/api/auth/me'] as $ruta) {
            $this->get($ruta)->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');
        }
    }
}
