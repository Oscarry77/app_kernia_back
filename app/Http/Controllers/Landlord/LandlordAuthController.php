<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;

/**
 * Auth de Kernia (movido de bridge-com-api el 13-sep-2026). Aquí ya no hay
 * ambigüedad de guard -- 'api' es el único guard de esta app -- pero se
 * mantiene el uso explícito de auth('api') por consistencia con el resto del
 * código heredado.
 */
class LandlordAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
        ]);

        $admin = LandlordAdmin::where('email', strtolower((string) $request->email))->first();

        if (! $admin || ! Hash::check($request->password, $admin->password)) {
            return response()->json([
                'message' => 'Usuario o contraseña incorrectos.',
                'errors'  => ['email' => ['Las credenciales no son válidas.']],
            ], 401);
        }

        if (! $admin->activo) {
            return response()->json([
                'message' => 'Esta cuenta de administrador está inactiva.',
            ], 403);
        }

        try {
            $token = auth('api')->login($admin);
        } catch (JWTException $e) {
            return response()->json(['message' => 'No se pudo generar el token.'], 500);
        }

        $admin->update(['ultimo_acceso' => now()]);

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => config('jwt.ttl') * 60,
            'user'         => $this->formatAdmin($admin),
        ]);
    }

    public function logout(): JsonResponse
    {
        try {
            auth('api')->logout();
        } catch (JWTException) {
        }

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    public function refresh(): JsonResponse
    {
        try {
            $newToken = auth('api')->refresh();
        } catch (TokenExpiredException) {
            return response()->json([
                'message' => 'El token ha expirado y no puede renovarse. Inicia sesión de nuevo.',
            ], 401);
        } catch (JWTException) {
            return response()->json(['message' => 'Token inválido.'], 401);
        }

        return response()->json([
            'access_token' => $newToken,
            'token_type'   => 'bearer',
            'expires_in'   => config('jwt.ttl') * 60,
        ]);
    }

    public function me(): JsonResponse
    {
        $admin = auth('api')->user();

        if (! $admin) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        return response()->json($this->formatAdmin($admin));
    }

    private function formatAdmin(LandlordAdmin $admin): array
    {
        return [
            'id'            => $admin->id,
            'nombre'        => $admin->nombre,
            'email'         => $admin->email,
            'ultimo_acceso' => $admin->ultimo_acceso?->toIso8601String(),
        ];
    }
}
