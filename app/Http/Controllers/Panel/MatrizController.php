<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * (09-oct-2026) Menú lateral y matriz "Roles y accesos" del panel, desde una
 * sola definición (config/kernia_matriz.php; ESTANDAR_ACL_Y_MENU_APPS_KERNIA.md).
 *  - `menu`: el árbol que ve el operador en sesión (solo opciones con Acceso).
 *  - `matriz`: el árbol completo con lo que puede cada rol fijo (consulta).
 */
class MatrizController extends Controller
{
    /** GET /api/acl/menu */
    public function menu(Request $request): JsonResponse
    {
        /** @var LandlordAdmin $op */
        $op = $request->user('api');

        return response()->json(['data' => $this->filtrar(config('kernia_matriz.modulos'), fn (string $p) => $op->puede($p))]);
    }

    /** GET /api/acl/matriz */
    public function matriz(): JsonResponse
    {
        $roles = collect(config('kernia_acl.roles'))->map(fn ($r, $clave) => ['clave' => $clave, 'nombre' => $r['nombre']])->values();
        $tiene = fn (string $rol, ?string $permiso) => $permiso === null ? null
            : (in_array('*', config("kernia_acl.roles.{$rol}.permisos"), true) || in_array($permiso, config("kernia_acl.roles.{$rol}.permisos"), true));

        $conRoles = function (array $nodos) use (&$conRoles, $roles, $tiene): array {
            return array_map(function (array $n) use (&$conRoles, $roles, $tiene) {
                if (isset($n['hijos'])) {
                    return [...$n, 'hijos' => $conRoles($n['hijos'])];
                }
                $columnas = [];
                foreach (array_keys(config('kernia_matriz.columnas')) as $col) {
                    $permiso = $n['permisos'][$col] ?? null;
                    $columnas[$col] = $permiso === null ? null
                        : $roles->mapWithKeys(fn ($r) => [$r['clave'] => $tiene($r['clave'], $permiso)])->all();
                }
                $especiales = array_map(fn ($e) => [
                    'etiqueta' => $e['etiqueta'],
                    'roles' => $roles->mapWithKeys(fn ($r) => [$r['clave'] => $tiene($r['clave'], $e['permiso'])])->all(),
                ], $n['especiales'] ?? []);

                return ['etiqueta' => $n['etiqueta'], 'icono' => $n['icono'], 'ruta' => $n['ruta'] ?? null, 'nota' => $n['nota'] ?? null,
                    'columnas' => $columnas, 'especiales' => $especiales];
            }, $nodos);
        };

        return response()->json([
            'columnas' => config('kernia_matriz.columnas'),
            'roles' => $roles,
            'modulos' => $conRoles(config('kernia_matriz.modulos')),
        ]);
    }

    /** Deja solo las opciones con Acceso y los agrupadores que conservan alguna. */
    private function filtrar(array $nodos, callable $puede): array
    {
        $salida = [];
        foreach ($nodos as $n) {
            if (isset($n['hijos'])) {
                $hijos = $this->filtrar($n['hijos'], $puede);
                if ($hijos) {
                    $salida[] = ['etiqueta' => $n['etiqueta'], 'icono' => $n['icono'], 'hijos' => $hijos];
                }
            } elseif ($puede($n['permisos']['acceso'])) {
                $salida[] = ['etiqueta' => $n['etiqueta'], 'icono' => $n['icono'], 'ruta' => $n['ruta']];
            }
        }

        return $salida;
    }
}
