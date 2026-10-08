<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\DatosFiscalesClienteRequest;
use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Cliente;
use App\Services\Landlord\PanelPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Clientes (expedientes) del modelo v2 para el panel (02-oct-2026). Los datos
 * del cliente siguen la Constancia de Situación Fiscal (moral o física). El
 * slug es inmutable: no se edita aquí (estándar v2 §3).
 */
class ClientesController extends Controller
{
    public function __construct(private readonly PanelPresenter $presenter)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $clientes = Cliente::visiblesPara($request->user('api'))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('nombre', 'like', "%{$q}%")
                ->orWhere('slug', 'like', "%{$q}%")
                ->orWhere('rfc', 'like', "%{$q}%")
                ->orWhere('nombre_comercial', 'like', "%{$q}%")))
            ->orderBy('nombre')
            ->get();

        return response()->json(['data' => $clientes->map(fn ($c) => $this->presenter->cliente($c))->all()]);
    }

    public function show(Cliente $cliente): JsonResponse
    {
        return response()->json(['data' => $this->presenter->cliente($cliente, detalle: true)]);
    }

    public function store(DatosFiscalesClienteRequest $request): JsonResponse
    {
        $cliente = new Cliente([...$request->datosCliente(), 'slug' => $request->validated('slug'), 'estatus' => Cliente::ESTATUS_ACTIVO,
            'tipo' => $request->tipoCliente()]);
        $cliente->nombre = $cliente->nombreParaMostrar();
        $cliente->save();

        // Fase 3: el cliente que da de alta un vendedor queda en su cartera.
        $operador = $request->user('api');
        if ($operador->tieneCartera()) {
            $cliente->operadores()->attach($operador->id, ['asignado_por' => $operador->id]);
        }

        Auditoria::registrar('cliente.creado', $cliente, null, null, $cliente->only(['slug', 'nombre', 'tipo', 'tipo_persona', 'rfc']));

        return response()->json(['data' => $this->presenter->cliente($cliente, detalle: true)], 201);
    }

    public function update(DatosFiscalesClienteRequest $request, Cliente $cliente): JsonResponse
    {
        $antes = $cliente->only([...Cliente::CAMPOS_FISCALES, 'nombre', 'notas', 'tipo']);

        $cliente->fill($request->datosCliente());
        if ($tipo = $request->tipoCliente()) {
            $cliente->tipo = $tipo;
        }
        $cliente->nombre = $cliente->nombreParaMostrar();
        $cliente->save();

        $despues = $cliente->only([...Cliente::CAMPOS_FISCALES, 'nombre', 'notas', 'tipo']);
        $cambios = array_keys(array_diff_assoc(array_map('strval', array_filter($despues, 'is_scalar')), array_map('strval', array_filter($antes, 'is_scalar'))));
        Auditoria::registrar('cliente.editado', $cliente, null,
            array_intersect_key($antes, array_flip($cambios)),
            array_intersect_key($despues, array_flip($cambios)));

        return response()->json(['data' => $this->presenter->cliente($cliente->fresh(), detalle: true)]);
    }
}
