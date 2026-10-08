<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Mail\NuevaPasswordOperadorMail;
use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\CorreoEnviado;
use App\Models\Landlord\LandlordAdmin;
use App\Services\Correo\CentroCorreo;
use App\Services\Seguridad\GeneradorPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Operadores de Kernia, su rol y su cartera (fase 3, 02-oct-2026).
 *
 *  - Nadie asigna un rol por encima del suyo (`kernia_acl.roles_asignables`)
 *    ni edita a un operador de rol superior.
 *  - Nadie cambia su propio rol ni se desactiva a sí mismo.
 *  - Siempre queda al menos un superadmin activo.
 *  - La contraseña inicial (18 caracteres) se envía al correo del operador;
 *    si el correo no está configurado se devuelve UNA vez para mostrarla en
 *    pantalla. Nunca se registra.
 */
class OperadoresController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => LandlordAdmin::withCount('cartera')->with('nivelAutorizacion')->orderBy('nombre')->get()
                ->map(fn ($o) => $this->fila($o)),
            'roles' => collect(config('kernia_acl.roles'))->map(fn ($r, $clave) => ['clave' => $clave, 'nombre' => $r['nombre']])->values(),
            'asignables' => $this->asignables($request->user('api')),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $operador = $request->user('api');
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:100', Rule::unique('landlord_admins', 'email')],
            'rol' => ['required', Rule::in($this->asignables($operador))],
            'puesto' => ['nullable', 'string', 'max:120'],
        ]);

        $password = GeneradorPassword::generar(18);
        $nuevo = LandlordAdmin::create([...$datos, 'email' => strtolower($datos['email']), 'password' => $password, 'activo' => true]);

        // 07-oct-2026: por el centro de correo. Si no se pudo enviar, la contraseña se muestra una vez.
        $enviada = app(CentroCorreo::class)->enviar(new NuevaPasswordOperadorMail($nuevo->nombre, $password), $nuevo->email,
            ['referencia' => "operador:{$nuevo->id}"])->estado === CorreoEnviado::ENVIADO;

        Auditoria::registrar('operador.alta', null, null, null, [...$nuevo->only(['id', 'nombre', 'email', 'rol', 'puesto']), 'password_por_correo' => $enviada]);

        return response()->json([
            'data' => $this->fila($nuevo->loadCount('cartera')),
            'password_enviada' => $enviada,
            'password_temporal' => $enviada ? null : $password,
        ], 201);
    }

    public function update(Request $request, LandlordAdmin $operador): JsonResponse
    {
        $sesion = $request->user('api');
        $asignables = $this->asignables($sesion);

        if (! $sesion->esSuperadmin() && ! in_array($operador->rol, $asignables, true)) {
            return response()->json(['message' => 'No puedes modificar a un operador de rol superior al tuyo.'], 403);
        }

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'rol' => ['required', Rule::in($asignables)],
            'puesto' => ['nullable', 'string', 'max:120'],
            'activo' => ['required', 'boolean'],
        ]);

        if ($operador->id === $sesion->id && ($datos['rol'] !== $operador->rol || ! $datos['activo'])) {
            return response()->json(['message' => 'No puedes cambiar tu propio rol ni desactivarte.'], 422);
        }

        $dejaSuperadmin = $operador->esSuperadmin() && $operador->activo && ($datos['rol'] !== 'superadmin' || ! $datos['activo']);
        if ($dejaSuperadmin && LandlordAdmin::where('rol', 'superadmin')->where('activo', true)->count() <= 1) {
            return response()->json(['message' => 'Debe quedar al menos un superadministrador activo.'], 422);
        }

        $campos = ['nombre', 'rol', 'puesto', 'activo'];
        $antes = $operador->only($campos);
        $operador->update($datos);

        // Quien pierde el permiso de autorizar sale del escalafón (queda inactivo).
        if (! $operador->puede('prorrogas.autorizar') || ! $operador->activo) {
            $operador->nivelAutorizacion()->update(['activo' => false]);
        }

        Auditoria::registrar('operador.editado', null, null, ['id' => $operador->id, ...$antes], ['id' => $operador->id, ...$operador->only($campos)]);

        return response()->json(['data' => $this->fila($operador->fresh()->loadCount('cartera'))]);
    }

    /** PUT /operadores/{id}/cartera  {clientes: [ids]} — reemplaza la cartera completa. */
    public function cartera(Request $request, LandlordAdmin $operador): JsonResponse
    {
        if ($request->isMethod('get')) {
            return response()->json(['data' => $operador->cartera()->orderBy('nombre')->get(['clientes.id', 'clientes.nombre', 'clientes.slug'])
                ->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'slug' => $c->slug])]);
        }

        $datos = $request->validate([
            'clientes' => ['present', 'array'],
            'clientes.*' => ['integer', Rule::exists('clientes', 'id')],
        ]);

        if (! $operador->tieneCartera()) {
            return response()->json(['message' => 'Este operador ve a todos los clientes; la cartera solo aplica a vendedores.'], 422);
        }

        $antes = $operador->cartera()->pluck('clientes.id')->sort()->values()->all();
        $operador->cartera()->syncWithPivotValues(array_unique($datos['clientes']), ['asignado_por' => $request->user('api')->id]);
        $despues = $operador->cartera()->pluck('clientes.id')->sort()->values()->all();

        Auditoria::registrar('operador.cartera', null, null, ['operador_id' => $operador->id, 'clientes' => $antes], ['operador_id' => $operador->id, 'clientes' => $despues]);

        return response()->json(['data' => Cliente::whereIn('id', $despues)->orderBy('nombre')->get(['id', 'nombre', 'slug'])]);
    }

    private function asignables(LandlordAdmin $operador): array
    {
        return config("kernia_acl.roles_asignables.{$operador->rol}", []);
    }

    private function fila(LandlordAdmin $o): array
    {
        return [
            'id' => $o->id,
            'nombre' => $o->nombre,
            'email' => $o->email,
            'rol' => $o->rol,
            'rol_nombre' => config("kernia_acl.roles.{$o->rol}.nombre", $o->rol),
            'puesto' => $o->puesto,
            'activo' => $o->activo,
            'ultimo_acceso' => $o->ultimo_acceso?->toIso8601String(),
            'cartera' => $o->tieneCartera(),
            'clientes_en_cartera' => $o->cartera_count ?? null,
            'nivel_autorizacion' => $o->nivelAutorizacion?->activo ? $o->nivelAutorizacion->nivel : null,
        ];
    }
}
