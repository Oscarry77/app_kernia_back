<?php
namespace App\Services\Landlord;

use App\Models\Landlord\EnlaceFormularioSalida;
use App\Models\Landlord\FormularioSalida;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\Suscripcion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Formulario de salida (08-oct-2026, aprobado por el dueño):
 *  - Asesor: el motivo es obligatorio al solicitar un retiro, un finiquito o
 *    una baja de plan; se guarda junto con la solicitud.
 *  - Cliente: opcional, desde un enlace de un solo uso (30 días) que va en la
 *    carta de agradecimiento o que el asesor le envía. Pide motivo,
 *    calificación 1 a 5, qué pudimos hacer mejor y si nos recomendaría.
 *  - Del token solo se guarda su huella SHA-256: un enlace que se pierde se
 *    vuelve a generar (y el anterior deja de servir).
 */
class FormularioSalidaService
{
    /** @return array<string, string> clave => nombre */
    public function motivos(bool $paraCliente = false): array
    {
        $motivos = config('kernia.motivos_salida');

        return $paraCliente ? array_diff_key($motivos, array_flip(config('kernia.motivos_salida_solo_asesor'))) : $motivos;
    }

    /**
     * Valida el motivo que captura el asesor. Lo llaman los servicios de
     * salida y de cambio de plan ANTES de crear su solicitud.
     */
    public function validarMotivoAsesor(?string $motivo, ?string $detalle): void
    {
        if (! $motivo || ! array_key_exists($motivo, $this->motivos())) {
            throw new RuntimeException('Elige el motivo de salida del cliente.');
        }
        if ($motivo === 'otro' && ! trim((string) $detalle)) {
            throw new RuntimeException('Con el motivo "Otro", describe el motivo.');
        }
    }

    public function registrarAsesor(Suscripcion $s, string $evento, string $motivo, ?string $detalle, LandlordAdmin $asesor, array $solicitud): FormularioSalida
    {
        return FormularioSalida::create([
            'cliente_id' => $s->cliente_id,
            'suscripcion_id' => $s->id,
            'origen' => FormularioSalida::ORIGEN_ASESOR,
            'evento' => $evento,
            'motivo' => $motivo,
            'detalle' => trim((string) $detalle) ?: null,
            'registrado_por' => $asesor->id,
            ...$solicitud, // ['solicitud_salida_id' => …] o ['solicitud_plan_id' => …]
        ]);
    }

    /**
     * Genera el enlace del cliente para esta suscripción. Los enlaces
     * anteriores sin usar dejan de servir. Devuelve la URL completa: es la
     * única vez que existe el token en claro.
     *
     * @return array{url: string, expira_en: string}
     */
    public function generarEnlace(Suscripcion $s, string $evento, ?LandlordAdmin $creador = null): array
    {
        $token = Str::random(48);
        $expira = now()->addDays((int) config('kernia.formulario_salida_dias'));

        DB::transaction(function () use ($s, $evento, $creador, $token, $expira) {
            EnlaceFormularioSalida::where('suscripcion_id', $s->id)->whereNull('usado_en')->where('expira_en', '>', now())
                ->update(['expira_en' => now()]);
            EnlaceFormularioSalida::create([
                'cliente_id' => $s->cliente_id, 'suscripcion_id' => $s->id, 'evento' => $evento,
                'token_hash' => hash('sha256', $token), 'expira_en' => $expira, 'creado_por' => $creador?->id,
            ]);
        });

        return [
            'url' => rtrim((string) config('kernia.url_panel'), '/').'/salida/'.$token,
            'expira_en' => $expira->toIso8601String(),
        ];
    }

    /** Enlace por su token, vigente o no; null si no existe. */
    public function buscarEnlace(string $token): ?EnlaceFormularioSalida
    {
        return EnlaceFormularioSalida::with(['cliente', 'suscripcion.producto'])->where('token_hash', hash('sha256', $token))->first();
    }

    /** Guarda la respuesta del cliente y quema el enlace (una sola vez, aun con dos envíos simultáneos). */
    public function registrarCliente(EnlaceFormularioSalida $enlace, array $datos): FormularioSalida
    {
        if (! array_key_exists($datos['motivo'], $this->motivos(paraCliente: true))) {
            throw new RuntimeException('Elige uno de los motivos de la lista.');
        }
        if ($datos['motivo'] === 'otro' && ! trim((string) ($datos['detalle'] ?? ''))) {
            throw new RuntimeException('Con el motivo "Otro", cuéntanos el motivo.');
        }

        return DB::transaction(function () use ($enlace, $datos) {
            $enlace = EnlaceFormularioSalida::whereKey($enlace->id)->lockForUpdate()->first();
            if (! $enlace->vigente()) {
                throw new RuntimeException('Este enlace ya fue usado o venció.');
            }

            $formulario = FormularioSalida::create([
                'cliente_id' => $enlace->cliente_id,
                'suscripcion_id' => $enlace->suscripcion_id,
                'origen' => FormularioSalida::ORIGEN_CLIENTE,
                'evento' => $enlace->evento,
                'motivo' => $datos['motivo'],
                'detalle' => trim((string) ($datos['detalle'] ?? '')) ?: null,
                'calificacion' => $datos['calificacion'] ?? null,
                'mejora' => trim((string) ($datos['mejora'] ?? '')) ?: null,
                'recomendaria' => $datos['recomendaria'] ?? null,
            ]);
            $enlace->update(['usado_en' => now(), 'formulario_salida_id' => $formulario->id]);

            return $formulario;
        });
    }

    /**
     * Resumen para Dirección sobre la consulta ya filtrada: cuántas salidas
     * por motivo (asesor y cliente por separado), calificación promedio y
     * porcentaje que nos recomendaría.
     */
    public function resumen(Builder $consulta): array
    {
        $filas = (clone $consulta)->get(['origen', 'motivo', 'calificacion', 'recomendaria']);
        $clientes = $filas->where('origen', FormularioSalida::ORIGEN_CLIENTE);
        $conRecomendacion = $clientes->whereNotNull('recomendaria');

        return [
            'total' => $filas->count(),
            'por_motivo' => collect($this->motivos())->map(fn ($nombre, $clave) => [
                'motivo' => $clave,
                'nombre' => $nombre,
                'asesor' => $filas->where('origen', FormularioSalida::ORIGEN_ASESOR)->where('motivo', $clave)->count(),
                'cliente' => $clientes->where('motivo', $clave)->count(),
            ])->values()->all(),
            'respuestas_cliente' => $clientes->count(),
            'calificacion_promedio' => $clientes->whereNotNull('calificacion')->avg('calificacion') !== null
                ? round($clientes->whereNotNull('calificacion')->avg('calificacion'), 1) : null,
            'recomendaria_pct' => $conRecomendacion->count()
                ? (int) round(100 * $conRecomendacion->where('recomendaria', true)->count() / $conRecomendacion->count()) : null,
        ];
    }
}
