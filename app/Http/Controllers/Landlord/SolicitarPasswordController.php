<?php
namespace App\Http\Controllers\Landlord;

use App\Services\Boveda\CorreoKernia;
use App\Http\Controllers\Controller;
use App\Mail\NuevaPasswordOperadorMail;
use App\Models\Landlord\CorreoEnviado;
use App\Models\Landlord\LandlordAdmin;
use App\Services\Correo\CentroCorreo;
use App\Services\Seguridad\GeneradorPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/auth/password/solicitar (02-oct-2026) — "olvidé mi contraseña"
 * del panel kernia-admin. Genera una contraseña nueva de 18 caracteres y la
 * envía al correo del propio operador.
 *
 *  - Respuesta UNIFORME (202) exista o no la cuenta: no revela qué correos
 *    son de operadores.
 *  - Si el correo no está configurado (mailer `log`/`array`), responde 503
 *    SIN cambiar nada: con `log` la contraseña quedaría en claro en
 *    laravel.log.
 *  - Primero se envía el correo y DESPUÉS se guarda el hash: si el envío
 *    falla, la contraseña anterior sigue sirviendo.
 *  - Limitado por correo e IP (`throttle:solicitud-password`).
 *  - Nunca se registra la contraseña.
 */
class SolicitarPasswordController extends Controller
{
    private const MENSAJE = 'Si el correo corresponde a una cuenta activa de Kernia, recibirás tu nueva contraseña en unos minutos.';

    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate(['email' => ['required', 'email', 'max:100']]);

        if (! app(CorreoKernia::class)->disponible()) {
            Log::warning('landlord.password_solicitada_sin_correo', ['mailer' => config('mail.default')]);

            return response()->json([
                'message' => 'El envío de correos no está configurado en Kernia. Contacta al administrador.',
                'codigo' => 'CORREO_NO_CONFIGURADO',
            ], 503);
        }

        $admin = LandlordAdmin::where('email', strtolower($datos['email']))->where('activo', true)->first();

        if (! $admin) {
            Log::info('landlord.password_solicitada', ['resultado' => 'sin_cuenta_activa', 'ip' => $request->ip()]);

            return response()->json(['message' => self::MENSAJE], 202);
        }

        $password = GeneradorPassword::generar(18);

        // 07-oct-2026: por el centro de correo (queda en el registro, sin la contraseña).
        $envio = app(CentroCorreo::class)->enviar(new NuevaPasswordOperadorMail($admin->nombre, $password), $admin->email,
            ['operador_id' => null, 'referencia' => "operador:{$admin->id}"]);
        if ($envio->estado !== CorreoEnviado::ENVIADO) {
            Log::critical('landlord.password_solicitada_envio_fallido', ['operador_id' => $admin->id, 'estado' => $envio->estado]);

            return response()->json(['message' => self::MENSAJE], 202);
        }

        $admin->forceFill(['password' => $password])->save(); // cast 'hashed'

        Log::info('landlord.password_solicitada', ['resultado' => 'enviada', 'operador_id' => $admin->id, 'ip' => $request->ip()]);

        return response()->json(['message' => self::MENSAJE], 202);
    }
}
