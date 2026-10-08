<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Producto;
use App\Services\Landlord\CatalogoService;
use App\Services\Landlord\PanelPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Catálogo de productos (apps), planes y extras (02-oct-2026). Los datos
 * técnicos del producto (URL interna, token, modo de datos) no se exponen ni
 * se editan aquí.
 */
class CatalogoController extends Controller
{
    public function __construct(
        private readonly PanelPresenter $presenter,
        private readonly CatalogoService $catalogo,
    ) {
    }

    /** GET /api/catalogo/productos — `?completo=1` incluye planes y extras inactivos (pantalla de catálogo). */
    public function productos(Request $request): JsonResponse
    {
        $completo = $request->boolean('completo');

        return response()->json([
            'data' => Producto::orderBy('id')->get()->map(fn ($p) => $this->presenter->producto($p, $completo))->all(),
            'limites_disponibles' => CatalogoService::LIMITES,
        ]);
    }

    /** GET /api/catalogo/fiscal — listas del SAT para los datos del cliente. */
    public function fiscal(): JsonResponse
    {
        return response()->json(['data' => config('catalogos_fiscales')]);
    }

    public function actualizarProducto(Request $request, Producto $producto): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'nombre_corto' => ['required', 'string', 'max:20'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'permite_ws_cntpaq' => ['required', 'boolean'],
        ]);

        $antes = $producto->only(array_keys($datos));
        $producto->update([...$datos, 'nombre_corto' => strtoupper($datos['nombre_corto'])]);
        Auditoria::registrar('catalogo.producto_editado', null, null, ['producto' => $producto->slug, ...$antes], $producto->only(array_keys($datos)));

        return response()->json(['data' => $this->presenter->producto($producto->fresh(), true)]);
    }

    public function crearPlan(Request $request, Producto $producto): JsonResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'regex:/^[a-z0-9_]{2,40}$/', Rule::unique('producto_planes', 'codigo')->where('producto_id', $producto->id)],
            ...$this->reglasPlan(),
        ], ['codigo.regex' => 'El código va en minúsculas, números y guion bajo (2 a 40).', 'codigo.unique' => 'Ya existe un plan con ese código.']);

        return $this->guardarPlan($producto, null, $datos, 'catalogo.plan_creado');
    }

    public function actualizarPlan(Request $request, Producto $producto, string $codigo): JsonResponse
    {
        $plan = $producto->planes()->where('codigo', $codigo)->firstOrFail();
        $datos = $request->validate([...$this->reglasPlan(), 'activo' => ['required', 'boolean']]);

        return $this->guardarPlan($producto, $plan, $datos, 'catalogo.plan_editado');
    }

    public function crearExtra(Request $request, Producto $producto): JsonResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'regex:/^[a-z0-9_]{2,40}$/', Rule::unique('producto_extras', 'codigo')->where('producto_id', $producto->id)],
            ...$this->reglasExtra(),
        ], ['codigo.regex' => 'El código va en minúsculas, números y guion bajo (2 a 40).', 'codigo.unique' => 'Ya existe un extra con ese código.']);

        return $this->guardarExtra($producto, null, $datos, 'catalogo.extra_creado');
    }

    public function actualizarExtra(Request $request, Producto $producto, string $codigo): JsonResponse
    {
        $extra = $producto->extras()->where('codigo', $codigo)->firstOrFail();
        $datos = $request->validate([...$this->reglasExtra(), 'activo' => ['required', 'boolean']]);

        return $this->guardarExtra($producto, $extra, $datos, 'catalogo.extra_editado');
    }

    private function reglasPlan(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'modulos' => ['nullable', 'array'],
            'modulos.*' => ['string'],
            'limites' => ['nullable', 'array'],
            'orden' => ['nullable', 'integer', 'between:0,999'],
        ];
    }

    private function reglasExtra(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:120'],
            'limite' => ['required', Rule::in(CatalogoService::LIMITES)],
            'incremento' => ['required', 'integer', 'between:1,100000'],
        ];
    }

    private function guardarPlan(Producto $producto, $plan, array $datos, string $accion): JsonResponse
    {
        $antes = $plan?->only(['nombre', 'descripcion', 'modulos', 'limites', 'orden', 'activo']);

        try {
            $plan = $this->catalogo->guardarPlan($producto, $plan, $datos);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $afectados = $this->catalogo->suscripcionesConPlan($producto, $plan->codigo);
        Auditoria::registrar($accion, null, null, $antes ? ['producto' => $producto->slug, 'plan' => $plan->codigo, ...$antes] : null,
            ['producto' => $producto->slug, 'plan' => $plan->codigo, ...$plan->only(['nombre', 'descripcion', 'modulos', 'limites', 'orden', 'activo']), 'clientes_afectados' => $afectados]);

        return response()->json(['data' => $this->presenter->producto($producto->fresh(), true), 'clientes_afectados' => $afectados], $antes ? 200 : 201);
    }

    private function guardarExtra(Producto $producto, $extra, array $datos, string $accion): JsonResponse
    {
        $antes = $extra?->only(['nombre', 'limite', 'incremento', 'activo']);

        try {
            $extra = $this->catalogo->guardarExtra($producto, $extra, $datos);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Auditoria::registrar($accion, null, null, $antes ? ['producto' => $producto->slug, 'extra' => $extra->codigo, ...$antes] : null,
            ['producto' => $producto->slug, 'extra' => $extra->codigo, ...$extra->only(['nombre', 'limite', 'incremento', 'activo'])]);

        return response()->json(['data' => $this->presenter->producto($producto->fresh(), true)], $antes ? 200 : 201);
    }
}
