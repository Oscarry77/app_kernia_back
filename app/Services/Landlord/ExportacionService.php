<?php
namespace App\Services\Landlord;

use App\Mail\CartaFiniquitoMail;
use App\Mail\ClaveRespaldoMail;
use App\Mail\RecordatorioDescargaMail;
use App\Models\Landlord\Auditoria;
use App\Models\Landlord\BovedaSecreto;
use App\Models\Landlord\CorreoEnviado;
use App\Models\Landlord\Exportacion;
use App\Models\Landlord\FormularioSalida;
use App\Models\Landlord\LandlordAdmin;
use App\Models\Landlord\SolicitudSalida;
use App\Models\Landlord\Suscripcion;
use App\Services\Boveda\BovedaService;
use App\Services\Correo\AvisosCliente;
use App\Services\Correo\CentroCorreo;
use App\Services\Seguridad\GeneradorPassword;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orquestación de la exportación v2.3 del finiquito (09-oct-2026). Reglas en
 * REGISTRO_DECISIONES_KERNIA.md §3 (05-oct) y ESTANDAR_v2.3 §3.2, §4.2 y §7:
 *
 *  - Al aplicarse un finiquito, Kernia genera la contraseña del 7z
 *    (24 caracteres), la guarda en la bóveda (`respaldo.{id}`) y pide la
 *    exportación con alcance `cliente`. La contraseña solo viaja en ese POST.
 *  - Polling hasta `ready`. Si la app reporta `failed` (incluido
 *    `proceso_interrumpido`), reintenta con contraseña e Idempotency-Key nuevas,
 *    hasta MAX_INTENTOS; después queda `failed` y se alerta en la bitácora.
 *  - Con `ready`: carta de agradecimiento al administrador (copia al asesor y
 *    a Dirección, sin contraseña) y, en un correo aparte, solo la contraseña.
 *  - Recordatorios de descarga 7 y 2 días antes del plazo, si no ha descargado.
 *  - Al vencer el plazo (15 días): `finiquitado`, con retención de 90 días.
 *    La eliminación al terminar la retención es la siguiente pieza (4b).
 */
class ExportacionService
{
    public const MAX_INTENTOS = 3;

    private const RECORDATORIOS = [7, 2];

    public function __construct(
        private readonly ProductoAppClient $app,
        private readonly BovedaService $boveda,
        private readonly CentroCorreo $correo,
        private readonly SuscripcionEstatusService $estatus,
    ) {
    }

    /** Lo llama SalidaService al aplicar un finiquito (la suscripción ya está `en_finiquito`). */
    public function iniciarFiniquito(Suscripcion $s, SolicitudSalida $sol): Exportacion
    {
        $exp = Exportacion::create([
            'suscripcion_id' => $s->id, 'solicitud_salida_id' => $sol->id,
            'motivo' => Exportacion::MOTIVO_FINIQUITO, 'alcance' => 'cliente',
            'disponible_hasta' => $s->descarga_hasta, 'estado' => Exportacion::PENDIENTE, 'intento' => 1,
        ]);
        Auditoria::registrar('exportacion.solicitada', null, $s, null, ['exportacion_id' => $exp->id, 'motivo' => $exp->motivo]);

        return $this->pedir($exp);
    }

    /**
     * Avanza cada exportación un paso. Lo llama `landlord:orquestar-exportaciones`
     * cada 5 minutos; idempotente. Un error de red no cambia el estado.
     *
     * @return list<Exportacion>
     */
    public function avanzar(): array
    {
        return Exportacion::with('suscripcion.producto', 'suscripcion.cliente')
            ->whereIn('estado', [Exportacion::PENDIENTE, Exportacion::PROCESSING, Exportacion::READY])
            ->orderBy('id')->get()
            ->map(function (Exportacion $exp) {
                try {
                    return match ($exp->estado) {
                        Exportacion::PENDIENTE => $this->pedir($exp),
                        Exportacion::PROCESSING => $this->revisar($exp),
                        default => $this->enviarCorreos($exp),
                    };
                } catch (Throwable $e) {
                    $exp->update(['error' => mb_substr($e->getMessage(), 0, 300), 'revisada_en' => now()]);
                    Log::warning('exportacion.reintento', ['exportacion_id' => $exp->id, 'error' => $exp->error]);

                    return $exp->fresh();
                }
            })->all();
    }

    /**
     * Diario (08:00): actualiza si ya se descargó, manda los recordatorios a 7
     * y 2 días y pasa a `finiquitado` lo que venció su plazo de descarga.
     *
     * @return array{recordatorios: int, finiquitadas: int}
     */
    public function tareasDiarias(): array
    {
        $hoy = VigenciaService::hoy();
        $resultado = ['recordatorios' => 0, 'finiquitadas' => 0];

        $listas = Exportacion::with('suscripcion.producto', 'suscripcion.cliente.operadores')
            ->where('estado', Exportacion::READY)->where('motivo', Exportacion::MOTIVO_FINIQUITO)
            ->whereHas('suscripcion', fn ($q) => $q->where('estatus', Suscripcion::ESTATUS_EN_FINIQUITO))->get();

        foreach ($listas as $exp) {
            $this->refrescarDescarga($exp);
            $dias = (int) $hoy->diffInDays($exp->disponible_hasta->toDateString(), false);

            if ($dias < 0) {
                $this->finiquitar($exp);
                $resultado['finiquitadas']++;
                continue;
            }
            $enviados = $exp->recordatorios ?? [];
            $tramo = collect(self::RECORDATORIOS)->filter(fn ($d) => $d >= $dias)->min();
            if (! $exp->descargada_en && $tramo !== null && ! in_array($tramo, $enviados, true) && $exp->clave_enviada_en) {
                $s = $exp->suscripcion;
                $r = $this->correo->enviar(new RecordatorioDescargaMail($s->cliente->nombre, $s->producto->nombre,
                    $exp->disponible_hasta->format('d/m/Y'), $dias, app(AvisosCliente::class)->asesor($s)),
                    $s->admin_email, $this->contexto($exp));
                if ($r->estado === CorreoEnviado::ENVIADO) {
                    $exp->update(['recordatorios' => [...$enviados, $tramo]]);
                    $resultado['recordatorios']++;
                }
            }
        }

        return $resultado;
    }

    // ── Pasos ──

    private function pedir(Exportacion $exp): Exportacion
    {
        $s = $exp->suscripcion;
        // La misma contraseña mientras sea el mismo intento (la app compara el cuerpo sin ella).
        $clave = $this->boveda->leer($exp->claveBoveda());
        if ($clave === null) {
            $clave = GeneradorPassword::generar(24);
            $this->boveda->guardar($exp->claveBoveda(), $clave, [
                'tipo' => BovedaSecreto::TIPO_RESPALDO,
                'descripcion' => "Contraseña del respaldo ({$exp->motivo}) de {$s->cliente->nombre} en {$s->producto->nombre}",
                'cliente_id' => $s->cliente_id, 'suscripcion_id' => $s->id,
                // Se purga al terminar la retención (90 días después del plazo de descarga).
                'expira_en' => $exp->disponible_hasta->copy()->addDays((int) config('kernia.retencion_dias')),
            ], null, "Exportación {$exp->id}, intento {$exp->intento}");
        }

        $r = $this->app->exportar($s, [
            'solicitud_id' => $exp->id,
            'alcance' => $exp->alcance,
            ...($exp->alcance === 'empresa' ? ['empresas' => $exp->empresas] : []),
            'motivo' => $exp->motivo,
            'clave_respaldo' => $clave,
            'disponible_hasta' => $exp->disponible_hasta->toDateString(),
        ], $exp->idempotencyKey());
        unset($clave);

        if (in_array($r['status'], [200, 202], true) && ! empty($r['body']['exportacion_id'])) {
            $exp->update(['estado' => Exportacion::PROCESSING, 'exportacion_app' => (string) $r['body']['exportacion_id'],
                'error' => null, 'revisada_en' => now()]);

            return $exp->fresh();
        }

        $exp->update(['error' => "La app no aceptó la exportación ({$r['status']}).", 'revisada_en' => now()]);

        return $exp->fresh();
    }

    private function revisar(Exportacion $exp): Exportacion
    {
        $e = $this->app->estadoExportacion($exp->suscripcion, $exp->exportacion_app);

        return match ($e['status'] ?? null) {
            'ready' => $this->listo($exp, $e),
            'failed' => $this->reintentar($exp, (string) ($e['error'] ?? 'sin detalle')),
            default => tap($exp)->update(['revisada_en' => now()]),
        };
    }

    private function listo(Exportacion $exp, array $e): Exportacion
    {
        $exp->update([
            'estado' => Exportacion::READY, 'error' => null, 'revisada_en' => now(),
            'tamano_bytes' => $e['tamano_bytes'] ?? null, 'sha256' => $e['sha256_7z'] ?? null, 'conteos' => $e['conteos'] ?? [],
            'descargada_en' => $e['descargada_en'] ?? null,
        ]);
        Auditoria::registrar('exportacion.lista', null, $exp->suscripcion, null, [
            'exportacion_id' => $exp->id, 'intento' => $exp->intento, 'tamano_bytes' => $exp->tamano_bytes, 'sha256' => $exp->sha256,
        ]);

        return $this->enviarCorreos($exp->fresh());
    }

    /** Falla de la app: contraseña e Idempotency-Key nuevas, hasta MAX_INTENTOS. */
    private function reintentar(Exportacion $exp, string $error): Exportacion
    {
        if ($exp->intento >= self::MAX_INTENTOS) {
            $exp->update(['estado' => Exportacion::FAILED, 'error' => mb_substr("Agotó {$exp->intento} intentos. Último error: {$error}", 0, 300)]);
            Auditoria::registrar('exportacion.fallida', null, $exp->suscripcion, null, ['exportacion_id' => $exp->id, 'error' => $exp->error]);

            return $exp->fresh();
        }

        // La contraseña anterior ya no sirve: se reemplaza por una nueva en el siguiente pedido.
        $this->boveda->guardar($exp->claveBoveda(), GeneradorPassword::generar(24), ['tipo' => BovedaSecreto::TIPO_RESPALDO],
            null, "Exportación {$exp->id}: reintento tras '{$error}'");
        $exp->update(['intento' => $exp->intento + 1, 'estado' => Exportacion::PENDIENTE, 'exportacion_app' => null, 'error' => mb_substr($error, 0, 300)]);
        Auditoria::registrar('exportacion.reintento', null, $exp->suscripcion, null, ['exportacion_id' => $exp->id, 'intento' => $exp->intento, 'error' => $error]);

        return $this->pedir($exp->fresh());
    }

    /** Carta (con copias) y, aparte, la contraseña. Cada una se reintenta hasta que salga. */
    private function enviarCorreos(Exportacion $exp): Exportacion
    {
        if ($exp->motivo !== Exportacion::MOTIVO_FINIQUITO || ($exp->carta_enviada_en && $exp->clave_enviada_en)) {
            return $exp;
        }
        $s = $exp->suscripcion->loadMissing(['cliente.operadores', 'producto']);
        $avisos = app(AvisosCliente::class);

        if (! $exp->carta_enviada_en) {
            $carta = new CartaFiniquitoMail(
                $s->cliente->nombre, $s->producto->nombre, $exp->alcance, $this->empresas($s), $this->contenido($exp),
                $exp->disponible_hasta->format('d/m/Y'), (string) $exp->sha256,
                $exp->disponible_hasta->copy()->addDays((int) config('kernia.retencion_dias'))->format('d/m/Y'),
                $avisos->asesor($s),
                app(FormularioSalidaService::class)->generarEnlace($s, FormularioSalida::EVENTO_FINIQUITO)['url'],
            );
            $r = $this->correo->enviar($carta, $s->admin_email, $this->contexto($exp));
            if ($r->estado === CorreoEnviado::ENVIADO) {
                $copias = [...$s->cliente->operadores()->where('activo', true)->pluck('email')->all(),
                    ...LandlordAdmin::where('rol', 'direccion')->where('activo', true)->pluck('email')->all()];
                $this->correo->enviarA($carta, array_diff($copias, [$s->admin_email]), $this->contexto($exp));
                $exp->update(['carta_enviada_en' => now()]);
            }
        }

        // La contraseña va DESPUÉS de la carta y en su propio correo; solo al administrador.
        if ($exp->fresh()->carta_enviada_en && ! $exp->clave_enviada_en) {
            $clave = $this->boveda->leer($exp->claveBoveda(), "Envío de la contraseña del respaldo al administrador (exportación {$exp->id})");
            if ($clave !== null) {
                $r = $this->correo->enviar(new ClaveRespaldoMail($s->cliente->nombre, $s->producto->nombre, $clave, $exp->disponible_hasta->format('d/m/Y')),
                    $s->admin_email, $this->contexto($exp));
                unset($clave);
                if ($r->estado === CorreoEnviado::ENVIADO) {
                    $exp->update(['clave_enviada_en' => now()]);
                }
            }
        }

        return $exp->fresh();
    }

    private function refrescarDescarga(Exportacion $exp): void
    {
        if ($exp->descargada_en) {
            return;
        }
        try {
            $e = $this->app->estadoExportacion($exp->suscripcion, $exp->exportacion_app);
            if (! empty($e['descargada_en'])) {
                $exp->update(['descargada_en' => $e['descargada_en']]);
                Auditoria::registrar('exportacion.descargada', null, $exp->suscripcion, null, ['exportacion_id' => $exp->id, 'descargada_en' => $e['descargada_en']]);
            }
        } catch (Throwable $e) {
            Log::warning('exportacion.descarga_no_consultada', ['exportacion_id' => $exp->id, 'error' => $e->getMessage()]);
        }
    }

    /** Venció el plazo de descarga: sin acceso; el respaldo queda en retención 90 días. */
    private function finiquitar(Exportacion $exp): void
    {
        $s = $exp->suscripcion;
        $exp->update(['retencion_hasta' => $exp->disponible_hasta->copy()->addDays((int) config('kernia.retencion_dias'))]);
        $this->estatus->cambiarEstatus($s, Suscripcion::ESTATUS_FINIQUITADO, 'Terminó el plazo de descarga del respaldo');
        Auditoria::registrar('suscripcion.finiquitada', null, $s->fresh(), ['estatus' => Suscripcion::ESTATUS_EN_FINIQUITO], [
            'estatus' => Suscripcion::ESTATUS_FINIQUITADO, 'exportacion_id' => $exp->id, 'descargada' => (bool) $exp->descargada_en,
            'retencion_hasta' => $exp->retencion_hasta->toDateString(),
        ]);
    }

    /** Empresas que menciona la carta: las que entrega la app v2.2 (sin archivadas); vacío si no maneja empresas. */
    private function empresas(Suscripcion $s): array
    {
        try {
            return collect(app(BajaPlanService::class)->usaV22($s) ? app(BajaPlanService::class)->empresas($s)['data'] ?? [] : [])
                ->reject(fn ($e) => $e['estado'] === 'archivada')
                ->map(fn ($e) => ['rfc' => $e['rfc'] ?? '', 'nombre' => $e['nombre']])->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** "datos/facturas_partidas.csv" → "Facturas partidas", con sus registros. */
    private function contenido(Exportacion $exp): array
    {
        return collect($exp->conteos ?? [])
            ->map(fn ($n, $ruta) => ['archivo' => ucfirst(str_replace('_', ' ', pathinfo((string) $ruta, PATHINFO_FILENAME))), 'registros' => (int) $n])
            ->values()->all();
    }

    private function contexto(Exportacion $exp): array
    {
        return ['cliente_id' => $exp->suscripcion->cliente_id, 'suscripcion_id' => $exp->suscripcion_id,
            'referencia' => "exportacion:{$exp->id}", 'operador_id' => null];
    }
}
