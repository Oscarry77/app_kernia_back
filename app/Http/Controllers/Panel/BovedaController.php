<?php
namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Mail\PruebaCorreoKerniaMail;
use App\Models\Landlord\Auditoria;
use App\Models\Landlord\BovedaAcceso;
use App\Models\Landlord\BovedaSecreto;
use App\Services\Boveda\BovedaService;
use App\Services\Boveda\CorreoKernia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Bóveda de Kernia en el panel (07-oct-2026). Solo el superadmin
 * (`boveda.gestionar`). Los secretos se escriben o reemplazan, NUNCA se leen:
 * ninguna respuesta incluye un valor de la bóveda. Escribir exige teclear de
 * nuevo la contraseña de quien tiene la sesión.
 */
class BovedaController extends Controller
{
    public function __construct(
        private readonly BovedaService $boveda,
        private readonly CorreoKernia $correo,
    ) {
    }

    /** Lista de secretos (solo metadatos) y estado del buzón. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => BovedaSecreto::with(['cliente:id,nombre,slug', 'actualizador:id,nombre'])->orderBy('tipo')->orderByDesc('updated_at')->get()
                ->map(fn (BovedaSecreto $s) => [
                    'id' => $s->id,
                    'clave' => $s->clave,
                    'tipo' => $s->tipo,
                    'descripcion' => $s->descripcion,
                    'cliente' => $s->cliente?->nombre,
                    'cliente_id' => $s->cliente_id,
                    'vigente' => $s->vigente(),
                    'expira_en' => $s->expira_en?->toDateString(),
                    'purgado_en' => $s->purgado_en?->toIso8601String(),
                    'actualizado_en' => $s->updated_at?->toIso8601String(),
                    'actualizado_por' => $s->actualizador?->nombre,
                ]),
            'correo' => $this->estadoCorreo(),
        ]);
    }

    public function accesos(BovedaSecreto $secreto): JsonResponse
    {
        return response()->json(['data' => BovedaAcceso::with('usuario:id,nombre')->where('clave', $secreto->clave)
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn (BovedaAcceso $a) => [
                'fecha' => $a->created_at?->toIso8601String(),
                'accion' => $a->accion,
                'usuario' => $a->usuario?->nombre,
                'motivo' => $a->motivo,
                'ip' => $a->ip,
            ])]);
    }

    /**
     * PUT /api/boveda/correo — credenciales del buzón. La contraseña del buzón
     * es obligatoria la primera vez; después, vacía conserva la guardada.
     */
    public function guardarCorreo(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => ['required', 'integer', 'in:25,465,587,2525'],
            'cifrado' => ['required', 'in:tls,ssl'],
            'usuario' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'remitente' => ['required', 'email', 'max:255'],
            'nombre_remitente' => ['required', 'string', 'max:120'],
            'password_operador' => ['required', 'string', 'max:200'],
        ], ['host.regex' => 'El servidor solo lleva letras, números, puntos y guiones (por ejemplo, smtp.gmail.com).']);

        if ($rechazo = $this->confirmarOperador($request, $datos['password_operador'])) {
            return $rechazo;
        }

        $password = $datos['password'] ?? '';
        if ($password === '') {
            if (! $this->correo->configurado()) {
                return response()->json(['message' => 'Escribe la contraseña del buzón: aún no hay una guardada.', 'errors' => ['password' => ['Obligatoria la primera vez.']]], 422);
            }
            $password = $this->correo->credenciales()['password'];
        }

        $antes = $this->correo->resumen();
        $this->correo->guardar([
            'host' => strtolower($datos['host']), 'port' => (int) $datos['port'], 'cifrado' => $datos['cifrado'],
            'usuario' => $datos['usuario'], 'password' => $password,
            'remitente' => strtolower($datos['remitente']), 'nombre_remitente' => $datos['nombre_remitente'],
        ], $request->user('api')->id);

        $despues = $this->correo->resumen();
        $limpiar = fn (?array $r) => $r ? array_intersect_key($r, array_flip(['host', 'port', 'cifrado', 'usuario', 'remitente', 'nombre_remitente'])) : null;
        Auditoria::registrar('boveda.correo_guardado', null, null, $limpiar($antes), [
            ...$limpiar($despues), 'password_cambiada' => ($datos['password'] ?? '') !== '',
        ]);

        return response()->json(['correo' => $this->estadoCorreo()]);
    }

    /** POST /api/boveda/correo/probar — envía un correo de prueba con las credenciales de la bóveda. */
    public function probarCorreo(Request $request): JsonResponse
    {
        $datos = $request->validate(['destinatario' => ['required', 'email', 'max:255']]);

        if (! $this->correo->configurado()) {
            return response()->json(['message' => 'Primero guarda las credenciales del buzón.'], 422);
        }

        $operador = $request->user('api');
        $secreto = BovedaSecreto::where('clave', CorreoKernia::CLAVE)->first();

        try {
            // Siempre con el mailer de la bóveda, aunque MAIL_MAILER aún apunte a otro.
            Mail::mailer(CorreoKernia::MAILER)->to($datos['destinatario'])->send(new PruebaCorreoKerniaMail($operador->nombre));
        } catch (Throwable $e) {
            $this->boveda->registrar($secreto, 'prueba', $operador->id, 'Prueba fallida: '.$e::class);
            Log::warning('boveda.correo_prueba_fallida', ['error' => $e::class]);

            return response()->json(['message' => 'El servidor de correo rechazó el envío: '.$this->explicar($e)], 422);
        }

        $this->boveda->registrar($secreto, 'prueba', $operador->id, 'Correo de prueba enviado a '.$datos['destinatario']);

        return response()->json(['message' => "Correo de prueba enviado a {$datos['destinatario']}."]);
    }

    private function estadoCorreo(): array
    {
        return [
            'configurado' => $this->correo->configurado(),
            'disponible' => $this->correo->disponible(),
            'mailer' => config('mail.default'),
            'resumen' => $this->correo->resumen(),
        ];
    }

    /** Escribir en la bóveda exige teclear de nuevo la contraseña de la sesión. */
    private function confirmarOperador(Request $request, string $password): ?JsonResponse
    {
        $operador = $request->user('api');
        if (Hash::check($password, $operador->password)) {
            return null;
        }

        Auditoria::registrar('boveda.confirmacion_fallida', null, null, null, ['ruta' => $request->path()]);

        return response()->json(['message' => 'Tu contraseña no es correcta.', 'errors' => ['password_operador' => ['Tu contraseña no es correcta.']]], 422);
    }

    /** Mensaje útil sin exponer credenciales ni la traza. */
    private function explicar(Throwable $e): string
    {
        $m = $e->getMessage();

        return match (true) {
            str_contains($m, '535') || str_contains($m, 'authenticate') => 'usuario o contraseña del buzón incorrectos (en Gmail se usa una contraseña de aplicación).',
            str_contains($m, 'Connection') || str_contains($m, 'connect') => 'no se pudo conectar con el servidor; revisa el servidor, el puerto y el cifrado.',
            str_contains($m, 'TLS') || str_contains($m, 'SSL') => 'falló la conexión segura; revisa el puerto y el cifrado (587 con TLS o 465 con SSL).',
            default => 'error desconocido; revisa los datos e intenta de nuevo.',
        };
    }
}
