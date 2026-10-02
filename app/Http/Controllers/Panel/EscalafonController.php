<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Auditoria;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\NivelAutorizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Escalafón de autorización de prórrogas (fase 3, 02-oct-2026): nivel,
 * puesto, persona y días máximos que autoriza. Sin escalafón capturado solo
 * el superadmin autoriza. Solo entran operadores con permiso de autorizar.
 */
class EscalafonController extends Controller
{
    private const CAMPOS = ['nivel', 'puesto', 'usuario_id', 'dias_max', 'activo'];

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => NivelAutorizacion::with('usuario:id,nombre,email')->orderBy('nivel')->get()->map(fn ($n) => $this->fila($n)),
            'max_dias' => config('kernia_acl.prorroga_max_dias'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $this->validar($request);
        if ($error = $this->validarUsuario((int) $datos['usuario_id'])) {
            return response()->json(['message' => $error], 422);
        }

        $n = NivelAutorizacion::create([...$datos, 'activo' => $datos['activo'] ?? true]);
        Auditoria::registrar('escalafon.alta', null, null, null, $n->only(self::CAMPOS));

        return response()->json(['data' => $this->fila($n->load('usuario'))], 201);
    }

    public function update(Request $request, NivelAutorizacion $nivel): JsonResponse
    {
        $datos = $this->validar($request);
        if ((int) $datos['usuario_id'] !== $nivel->usuario_id && ($error = $this->validarUsuario((int) $datos['usuario_id']))) {
            return response()->json(['message' => $error], 422);
        }

        $antes = $nivel->only(self::CAMPOS);
        $nivel->update($datos);
        Auditoria::registrar('escalafon.editado', null, null, $antes, $nivel->only(self::CAMPOS));

        return response()->json(['data' => $this->fila($nivel->fresh('usuario'))]);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'nivel' => ['required', 'integer', 'between:1,20'],
            'puesto' => ['required', 'string', 'max:120'],
            'usuario_id' => ['required', 'integer'],
            'dias_max' => ['required', 'integer', 'between:1,'.config('kernia_acl.prorroga_max_dias')],
            'activo' => ['sometimes', 'boolean'],
        ]);
    }

    private function validarUsuario(int $id): ?string
    {
        $u = LandlordAdmin::find($id);

        return match (true) {
            ! $u || ! $u->activo => 'El operador no existe o está inactivo.',
            ! $u->puede('prorrogas.autorizar') => 'Su rol no permite autorizar prórrogas (dirección o gerente).',
            NivelAutorizacion::where('usuario_id', $id)->exists() => 'Esa persona ya está en el escalafón; edita su nivel.',
            default => null,
        };
    }

    private function fila(NivelAutorizacion $n): array
    {
        return [
            'id' => $n->id,
            'nivel' => $n->nivel,
            'puesto' => $n->puesto,
            'usuario_id' => $n->usuario_id,
            'usuario' => $n->usuario?->nombre,
            'email' => $n->usuario?->email,
            'dias_max' => $n->dias_max,
            'activo' => $n->activo,
        ];
    }
}
