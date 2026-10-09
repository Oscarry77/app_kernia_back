<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Mail\AvisoCambioPlanMail;
use App\Mail\AvisoVencimientoMail;
use App\Mail\PlanAjustadoMail;
use App\Mail\RespaldoListoMail;
use App\Mail\CartaFiniquitoMail;
use App\Mail\ClaveRespaldoMail;
use App\Mail\MensajeKernia;
use App\Mail\NuevaPasswordOperadorMail;
use App\Mail\PruebaCorreoKerniaMail;
use App\Mail\RecordatorioDescargaMail;
use App\Models\Landlord\Cliente;
use App\Models\Landlord\CorreoEnviado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Centro de correo en el panel (07-oct-2026): el registro de lo que Kernia
 * envió y la vista previa de cada plantilla con datos ficticios. Nunca se
 * muestra el cuerpo de un correo enviado (no se guarda) ni un secreto.
 */
class CorreosController extends Controller
{
    /** @var list<class-string<MensajeKernia>> */
    private const PLANTILLAS = [
        AvisoVencimientoMail::class,
        AvisoCambioPlanMail::class,
        PlanAjustadoMail::class,
        CartaFiniquitoMail::class,
        RespaldoListoMail::class,
        ClaveRespaldoMail::class,
        RecordatorioDescargaMail::class,
        NuevaPasswordOperadorMail::class,
        PruebaCorreoKerniaMail::class,
    ];

    /** GET /api/correos?cliente_id=&plantilla=&estado=&pagina= */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'cliente_id' => ['nullable', 'integer'],
            'plantilla' => ['nullable', 'string', 'max:60'],
            'estado' => ['nullable', 'in:enviado,fallido,omitido'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ]);

        // Los correos de un cliente se ven solo si el operador ve a ese cliente; los internos (sin cliente), siempre.
        $visibles = Cliente::visiblesPara($request->user('api'))->select('id');

        $pagina = CorreoEnviado::with(['cliente:id,nombre', 'operador:id,nombre'])
            ->where(fn ($q) => $q->whereNull('cliente_id')->orWhereIn('cliente_id', $visibles))
            ->when($f['cliente_id'] ?? null, fn ($q, $v) => $q->where('cliente_id', $v))
            ->when($f['plantilla'] ?? null, fn ($q, $v) => $q->where('plantilla', $v))
            ->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->orderByDesc('id')
            ->paginate(50, ['*'], 'pagina', $f['pagina'] ?? 1);

        $nombres = collect(self::PLANTILLAS)->mapWithKeys(fn ($c) => [$c::plantilla() => $c::nombre()]);

        return response()->json([
            'data' => collect($pagina->items())->map(fn (CorreoEnviado $c) => [
                'id' => $c->id,
                'fecha' => $c->created_at?->toIso8601String(),
                'plantilla' => $c->plantilla,
                'plantilla_nombre' => $nombres[$c->plantilla] ?? $c->plantilla,
                'destinatario' => $c->destinatario,
                'asunto' => $c->asunto,
                'estado' => $c->estado,
                'error' => $c->error,
                'contiene_secreto' => $c->contiene_secreto,
                'cliente_id' => $c->cliente_id,
                'cliente' => $c->cliente?->nombre,
                'operador' => $c->operador?->nombre,
                'referencia' => $c->referencia,
            ]),
            'pagina' => $pagina->currentPage(),
            'paginas' => $pagina->lastPage(),
            'total' => $pagina->total(),
        ]);
    }

    /** GET /api/correos/plantillas */
    public function plantillas(): JsonResponse
    {
        return response()->json(['data' => collect(self::PLANTILLAS)->map(fn ($c) => [
            'clave' => $c::plantilla(),
            'nombre' => $c::nombre(),
            'contiene_secreto' => $c::contieneSecreto(),
            'asunto_ejemplo' => $c::ejemplo()->asuntoCompleto(),
        ])]);
    }

    /** GET /api/correos/plantillas/{clave}/vista-previa — HTML con datos ficticios. */
    public function vistaPrevia(string $clave): Response
    {
        $clase = collect(self::PLANTILLAS)->first(fn ($c) => $c::plantilla() === $clave);
        abort_if($clase === null, 404);

        return response($clase::ejemplo()->render(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
