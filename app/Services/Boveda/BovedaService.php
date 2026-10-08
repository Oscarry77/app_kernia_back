<?php
namespace App\Services\Boveda;

use App\Models\Landlord\BovedaAcceso;
use App\Models\Landlord\BovedaSecreto;
use App\Services\Landlord\VigenciaService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bóveda de Kernia (07-oct-2026; decisiones del 05-oct en
 * REGISTRO_DECISIONES_KERNIA.md §6):
 *
 *  - Guarda secretos cifrados con APP_KEY. Ninguna respuesta de la API ni
 *    pantalla del panel los muestra: se pueden escribir o reemplazar, nunca leer.
 *  - Solo los procesos del sistema los leen (enviar un correo, reenviar una
 *    contraseña de respaldo autorizada) y cada lectura de un secreto de
 *    cliente queda en boveda_accesos.
 *  - Un secreto con `expira_en` se purga al pasar esa fecha: se borra el valor
 *    y se conserva el registro para la auditoría.
 *
 * @phpstan-type Contexto array{tipo: string, descripcion?: ?string, resumen?: ?array, cliente_id?: ?int, suscripcion_id?: ?int, expira_en?: CarbonInterface|string|null}
 */
class BovedaService
{
    /**
     * Guarda o reemplaza un secreto. `$valor` puede ser texto o un arreglo
     * (se guarda como JSON cifrado).
     *
     * @param Contexto $contexto
     */
    public function guardar(string $clave, string|array $valor, array $contexto, ?int $operadorId = null, ?string $motivo = null): BovedaSecreto
    {
        $plano = is_array($valor) ? json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $valor;
        if ($plano === '') {
            throw new RuntimeException('La bóveda no guarda valores vacíos.');
        }

        return DB::transaction(function () use ($clave, $plano, $contexto, $operadorId, $motivo) {
            $secreto = BovedaSecreto::where('clave', $clave)->lockForUpdate()->first();
            $existia = $secreto !== null && $secreto->vigente();

            $secreto ??= new BovedaSecreto(['clave' => $clave]);
            $secreto->fill([
                'tipo' => $contexto['tipo'],
                'descripcion' => $contexto['descripcion'] ?? $secreto->descripcion,
                'resumen' => array_key_exists('resumen', $contexto) ? $contexto['resumen'] : $secreto->resumen,
                'cliente_id' => $contexto['cliente_id'] ?? $secreto->cliente_id,
                'suscripcion_id' => $contexto['suscripcion_id'] ?? $secreto->suscripcion_id,
                'expira_en' => $contexto['expira_en'] ?? $secreto->expira_en,
                'valor' => $plano,
                'purgado_en' => null,
                'actualizado_por' => $operadorId,
            ])->save();

            $this->registrar($secreto, $existia ? 'reemplazado' : 'guardado', $operadorId, $motivo);

            return $secreto;
        });
    }

    /**
     * Lee un secreto para un proceso del sistema. Devuelve null si no existe o
     * ya se purgó. Con `$motivo`, deja constancia de la lectura (obligatorio
     * para secretos de clientes).
     */
    public function leer(string $clave, ?string $motivo = null, ?int $operadorId = null, ?int $autorizadoPor = null): ?string
    {
        $secreto = BovedaSecreto::where('clave', $clave)->first();
        if (! $secreto || ! $secreto->vigente()) {
            return null;
        }

        if ($motivo !== null) {
            $this->registrar($secreto, 'usado', $operadorId, $motivo, $autorizadoPor);
        }

        return $secreto->valor;
    }

    /** @return array<string,mixed>|null */
    public function leerArreglo(string $clave, ?string $motivo = null): ?array
    {
        $valor = $this->leer($clave, $motivo);

        return $valor === null ? null : json_decode($valor, true, 512, JSON_THROW_ON_ERROR);
    }

    public function existe(string $clave): bool
    {
        return (bool) BovedaSecreto::where('clave', $clave)->first()?->vigente();
    }

    public function registrar(BovedaSecreto $secreto, string $accion, ?int $usuarioId = null, ?string $motivo = null, ?int $autorizadoPor = null): BovedaAcceso
    {
        return BovedaAcceso::create([
            'secreto_id' => $secreto->id,
            'clave' => $secreto->clave,
            'accion' => $accion,
            'usuario_id' => $usuarioId,
            'autorizado_por' => $autorizadoPor,
            'motivo' => $motivo ? mb_substr($motivo, 0, 255) : null,
            'ip' => app()->runningInConsole() ? null : request()?->ip(),
        ]);
    }

    /**
     * Purga los secretos vencidos: borra el valor y conserva el registro.
     * Idempotente; lo corre el programador cada día.
     */
    public function purgarVencidos(): int
    {
        $vencidos = BovedaSecreto::whereNull('purgado_en')->whereNotNull('expira_en')
            ->whereDate('expira_en', '<', VigenciaService::hoy()->toDateString())->get();

        foreach ($vencidos as $secreto) {
            $secreto->forceFill(['valor' => null, 'purgado_en' => now()])->saveQuietly();
            $this->registrar($secreto, 'purgado', null, 'Venció su retención');
        }

        return $vencidos->count();
    }
}
