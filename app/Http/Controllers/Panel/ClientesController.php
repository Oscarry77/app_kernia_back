<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Cliente;
use App\Services\Landlord\PanelPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Clientes (workspaces) del modelo v2 para el panel (02-oct-2026). El slug es
 * inmutable: no se edita aquí (estándar v2 §3).
 */
class ClientesController extends Controller
{
    public function __construct(private readonly PanelPresenter $presenter)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $clientes = Cliente::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('nombre', 'like', "%{$q}%")
                ->orWhere('slug', 'like', "%{$q}%")
                ->orWhere('rfc', 'like', "%{$q}%")))
            ->orderBy('nombre')
            ->get();

        return response()->json(['data' => $clientes->map(fn ($c) => $this->presenter->cliente($c))->all()]);
    }

    public function show(Cliente $cliente): JsonResponse
    {
        return response()->json(['data' => $this->presenter->cliente($cliente, detalle: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'slug' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', 'unique:clientes,slug'],
            'nombre' => ['required', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', 'max:20'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ], [
            'slug.regex' => 'El slug va en minúsculas, números y guiones (3 a 63 caracteres), sin empezar ni terminar en guion.',
            'slug.unique' => 'Ya existe un cliente con ese slug.',
        ]);

        $cliente = Cliente::create([...$datos, 'rfc' => isset($datos['rfc']) ? strtoupper($datos['rfc']) : null, 'estatus' => Cliente::ESTATUS_ACTIVO]);
        Auditoria::registrar('cliente.creado', $cliente, null, null, ['slug' => $cliente->slug, 'nombre' => $cliente->nombre]);

        return response()->json(['data' => $this->presenter->cliente($cliente, detalle: true)], 201);
    }

    public function update(Request $request, Cliente $cliente): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', 'max:20'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ]);

        $antes = $cliente->only(['nombre', 'rfc', 'notas']);
        $cliente->update([...$datos, 'rfc' => isset($datos['rfc']) ? strtoupper($datos['rfc']) : null]);
        Auditoria::registrar('cliente.editado', $cliente, null, $antes, $cliente->only(['nombre', 'rfc', 'notas']));

        return response()->json(['data' => $this->presenter->cliente($cliente->fresh(), detalle: true)]);
    }
}
