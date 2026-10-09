<?php
namespace App\Services\Landlord;

use App\Mail\CartaFiniquitoMail;
use App\Mail\ClaveRespaldoMail;
use App\Mail\RecordatorioDescargaMail;
use App\Mail\RespaldoListoMail;
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
use RuntimeException;
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
 *  - (4b) Al terminar la retención: la app borra la exportación (§4.4) y luego
 *    todo lo del cliente (§4.6); en el patrón A, Kernia borra después la base y
 *    su usuario (EliminadorBases). La suscripción queda `eliminado`, se purga
 *    la contraseña de la bóveda y queda la constancia en la bitácora.
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
     * (4c) Copia a petición (v2.3 caso C): una por trimestre calendario incluida
     * (criterio del orquestador: una segunda en el mismo trimestre se niega; su
     * costo lo define el dueño). Plazo de 15 días; al vencer se borra.
     */
    public function iniciarCopia(Suscripcion $s, LandlordAdmin $operador): Exportacion
    {
        if ($s->estatus !== Suscripcion::ESTATUS_ACTIVO) {
            throw new RuntimeException('La copia se pide para una suscripción activa.');
        }
        if (! $s->producto->exportacion_v23) {
            throw new RuntimeException("{$s->producto->nombre} todavía no exporta ni tiene la sección Descargas (estándar v2.3).");
        }
        $estado = $this->estadoCopia($s);
        if ($estado['en_curso']) {
            throw new RuntimeException('Ya hay una copia en curso para esta app.');
        }
        if ($estado['usada']) {
            throw new RuntimeException("Ya se usó la copia incluida de este trimestre; la siguiente está disponible desde el {$estado['siguiente']}.");
        }

        $exp = Exportacion::create([
            'suscripcion_id' => $s->id, 'motivo' => Exportacion::MOTIVO_COPIA, 'alcance' => 'cliente', 'intento' => 1,
            'disponible_hasta' => VigenciaService::hoy()->addDays((int) config('kernia.finiquito_descarga_dias'))->toDateString(),
            'estado' => Exportacion::PENDIENTE,
        ]);
        Auditoria::registrar('exportacion.solicitada', null, $s, null, ['exportacion_id' => $exp->id, 'motivo' => $exp->motivo, 'operador' => $operador->email]);

        return $this->pedir($exp);
    }

    /** ¿Ya se usó la copia de este trimestre? ¿Hay una en curso? */
    public function estadoCopia(Suscripcion $s): array
    {
        $hoy = VigenciaService::hoy();
        $inicio = $hoy->firstOfQuarter();
        $copias = Exportacion::where('suscripcion_id', $s->id)->where('motivo', Exportacion::MOTIVO_COPIA)->where('estado', '!=', Exportacion::FAILED);

        return [
            'usada' => (clone $copias)->where('created_at', '>=', $inicio->setTimezone(config('app.timezone')))->exists(),
            'en_curso' => (clone $copias)->whereNull('eliminacion')->whereIn('estado', [Exportacion::PENDIENTE, Exportacion::PROCESSING])->exists(),
            'siguiente' => $inicio->addMonths(3)->format('d/m/Y'),
        ];
    }

    /** (4c) Archivo de una empresa (v2.3 caso A): exporta solo esa empresa. Lo llama SalidaService a las 00:00. */
    public function iniciarArchivo(Suscripcion $s, SolicitudSalida $sol): Exportacion
    {
        $exp = Exportacion::create([
            'suscripcion_id' => $s->id, 'solicitud_salida_id' => $sol->id, 'motivo' => Exportacion::MOTIVO_ARCHIVO,
            'alcance' => 'empresa', 'empresas' => [(int) $sol->empresa_id], 'intento' => 1,
            'disponible_hasta' => VigenciaService::hoy()->addDays((int) config('kernia.finiquito_descarga_dias'))->toDateString(),
            'estado' => Exportacion::PENDIENTE,
        ]);
        Auditoria::registrar('exportacion.solicitada', null, $s, null, ['exportacion_id' => $exp->id, 'motivo' => $exp->motivo, 'empresa_id' => $sol->empresa_id]);

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
        $this->eliminarVencidas();
        $this->revisarArchivos();

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
            $resultado['recordatorios'] += $this->recordar($exp, $dias);
        }

        // (4c) Copias y archivos de empresa: recordatorios, y al vencer o descargarse, su siguiente paso.
        $otras = Exportacion::with('suscripcion.producto', 'suscripcion.cliente.operadores')
            ->where('estado', Exportacion::READY)->whereIn('motivo', [Exportacion::MOTIVO_COPIA, Exportacion::MOTIVO_ARCHIVO])
            ->whereNull('eliminacion')->whereNull('archivo')->get();
        foreach ($otras as $exp) {
            try {
                $this->refrescarDescarga($exp);
                $dias = (int) $hoy->diffInDays($exp->disponible_hasta->toDateString(), false);
                if ($exp->motivo === Exportacion::MOTIVO_ARCHIVO && ($exp->descargada_en || $dias < 0)) {
                    $this->pedirArchivo($exp);
                    continue;
                }
                if ($exp->motivo === Exportacion::MOTIVO_COPIA && $dias < 0) {
                    $this->borrarCopia($exp);
                    continue;
                }
                $resultado['recordatorios'] += $this->recordar($exp, $dias);
            } catch (Throwable $e) {
                $exp->update(['error' => mb_substr($e->getMessage(), 0, 300)]);
            }
        }

        return $resultado;
    }

    /** Recordatorio a 7 y 2 días si no ha descargado. @return int 1 si salió */
    private function recordar(Exportacion $exp, int $dias): int
    {
        $enviados = $exp->recordatorios ?? [];
        $tramo = collect(self::RECORDATORIOS)->filter(fn ($d) => $d >= $dias)->min();
        if ($exp->descargada_en || $tramo === null || in_array($tramo, $enviados, true) || ! $exp->clave_enviada_en) {
            return 0;
        }
        $s = $exp->suscripcion;
        $r = $this->correo->enviar(new RecordatorioDescargaMail($s->cliente->nombre, $s->producto->nombre,
            $exp->disponible_hasta->format('d/m/Y'), $dias, app(AvisosCliente::class)->asesor($s)), $s->admin_email, $this->contexto($exp));
        if ($r->estado !== CorreoEnviado::ENVIADO) {
            return 0;
        }
        $exp->update(['recordatorios' => [...$enviados, $tramo]]);

        return 1;
    }

    /** Copia: al vencer su plazo se borra (no tiene retención, v2.3 §4.4). */
    private function borrarCopia(Exportacion $exp): void
    {
        $this->app->eliminarExportacion($exp->suscripcion, $exp->exportacion_app);
        $this->boveda->purgar($exp->claveBoveda(), 'Venció el plazo de la copia');
        $exp->update(['eliminacion' => Exportacion::ELIM_COMPLETA, 'eliminada_en' => now(), 'error' => null]);
        Auditoria::registrar('exportacion.eliminada', null, $exp->suscripcion, null, ['exportacion_id' => $exp->id, 'motivo' => $exp->motivo, 'sha256' => $exp->sha256]);
    }

    /** Archivo: la app retira la empresa (v2.3 §4.5). 409 = aún no se puede (no descargó y no venció): se espera. */
    private function pedirArchivo(Exportacion $exp): void
    {
        $empresa = (int) ($exp->empresas[0] ?? 0);
        $r = $this->app->archivarEmpresa($exp->suscripcion, $empresa, ['solicitud_id' => $exp->solicitud_salida_id ?? $exp->id, 'exportacion_id' => $exp->exportacion_app]);
        if (in_array($r['status'], [200, 202], true)) {
            $exp->update(['archivo' => Exportacion::ARCH_ARCHIVANDO, 'error' => null]);
        } elseif ($r['status'] !== 409) {
            throw new RuntimeException("La app no aceptó archivar la empresa ({$r['status']}).");
        }
    }

    /** Sigue los archivos en curso; al quedar `ready`, empieza la retención de 90 días. */
    private function revisarArchivos(): void
    {
        $enCurso = Exportacion::with('suscripcion.producto', 'suscripcion.cliente')
            ->where('motivo', Exportacion::MOTIVO_ARCHIVO)->where('archivo', Exportacion::ARCH_ARCHIVANDO)->get();
        foreach ($enCurso as $exp) {
            try {
                $estado = $this->app->estadoArchivarEmpresa($exp->suscripcion, (int) $exp->empresas[0]);
                if (($estado['status'] ?? null) === 'failed') {
                    throw new RuntimeException('La app no pudo archivar la empresa: '.($estado['error'] ?? 'sin detalle'));
                }
                if (($estado['status'] ?? null) === 'ready') {
                    $retencion = VigenciaService::hoy()->max($exp->disponible_hasta)->addDays((int) config('kernia.retencion_dias'));
                    $exp->update(['archivo' => Exportacion::ARCH_ARCHIVADA, 'archivada_en' => now(), 'retencion_hasta' => $retencion, 'error' => null]);
                    Auditoria::registrar('empresa.archivada', null, $exp->suscripcion, null, ['exportacion_id' => $exp->id,
                        'empresa_id' => $exp->empresas[0], 'sha256' => $exp->sha256, 'retencion_hasta' => $retencion->toDateString()]);
                }
            } catch (Throwable $e) {
                $exp->update(['error' => mb_substr($e->getMessage(), 0, 300)]);
            }
        }
    }

    /**
     * (4b) Avanza la eliminación de los finiquitos cuya retención ya terminó.
     * Un paso por vuelta; idempotente. Un error no cambia el paso: se reintenta.
     */
    public function eliminarVencidas(): void
    {
        $vencidas = Exportacion::with('suscripcion.producto', 'suscripcion.cliente')
            ->where('motivo', Exportacion::MOTIVO_FINIQUITO)->where('estado', Exportacion::READY)
            ->whereNotNull('retencion_hasta')->whereDate('retencion_hasta', '<', VigenciaService::hoy()->toDateString())
            ->where(fn ($q) => $q->whereNull('eliminacion')->orWhere('eliminacion', '!=', Exportacion::ELIM_COMPLETA))
            ->whereHas('suscripcion', fn ($q) => $q->where('estatus', Suscripcion::ESTATUS_FINIQUITADO))->get();

        // (4c) Archivo de empresa: al terminar la retención solo se borra la exportación (la empresa ya se retiró).
        $archivos = Exportacion::with('suscripcion.producto', 'suscripcion.cliente')
            ->where('motivo', Exportacion::MOTIVO_ARCHIVO)->where('archivo', Exportacion::ARCH_ARCHIVADA)->whereNull('eliminacion')
            ->whereDate('retencion_hasta', '<', VigenciaService::hoy()->toDateString())->get();
        foreach ($archivos as $exp) {
            try {
                $this->borrarCopia($exp);
            } catch (Throwable $e) {
                $exp->update(['error' => mb_substr('Eliminación: '.$e->getMessage(), 0, 300)]);
            }
        }

        foreach ($vencidas as $exp) {
            try {
                $this->pasoEliminacion($exp);
            } catch (Throwable $e) {
                $exp->update(['error' => mb_substr('Eliminación: '.$e->getMessage(), 0, 300)]);
                Log::warning('exportacion.eliminacion_reintento', ['exportacion_id' => $exp->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function pasoEliminacion(Exportacion $exp): void
    {
        $s = $exp->suscripcion;
        switch ($exp->eliminacion) {
            case null:
                $this->app->eliminarExportacion($s, $exp->exportacion_app);
                $exp->update(['eliminacion' => Exportacion::ELIM_EXPORTACION_BORRADA, 'error' => null]);
                Auditoria::registrar('exportacion.eliminada', null, $s, null, ['exportacion_id' => $exp->id, 'sha256' => $exp->sha256]);

                return;
            case Exportacion::ELIM_EXPORTACION_BORRADA:
                $codigo = $this->app->eliminarFiniquito($s, $exp->id);
                if (! in_array($codigo, [200, 202], true)) {
                    throw new RuntimeException("La app no aceptó la eliminación del finiquito ({$codigo}).");
                }
                $exp->update(['eliminacion' => Exportacion::ELIM_APP_ELIMINANDO, 'error' => null]);

                return;
            case Exportacion::ELIM_APP_ELIMINANDO:
                $estado = $this->app->estadoEliminarFiniquito($s)['status'] ?? null;
                if ($estado === 'failed') {
                    throw new RuntimeException('La app reportó que no pudo eliminar los datos del cliente.');
                }
                if ($estado !== 'ready') {
                    return;
                }
                // Patrón A: la base la creó Kernia y la borra Kernia, después de la app.
                $base = $s->producto->esDedicada() ? app(EliminadorBases::class)->eliminar($s) : null;
                $exp->update(['eliminacion' => Exportacion::ELIM_BASE_BORRADA, 'error' => null]);
                if ($base) {
                    Auditoria::registrar('suscripcion.base_eliminada', null, $s, null, ['exportacion_id' => $exp->id, ...$base]);
                }

                return;
            case Exportacion::ELIM_BASE_BORRADA:
                $this->boveda->purgar($exp->claveBoveda(), 'Terminó la retención del respaldo del finiquito');
                // `eliminado` no se notifica a la app: ella ya borró todo lo del cliente (v2.3 §4.6).
                $s->update(['estatus' => Suscripcion::ESTATUS_ELIMINADO, 'estatus_por_notificar' => null]);
                $exp->update(['eliminacion' => Exportacion::ELIM_COMPLETA, 'eliminada_en' => now(), 'error' => null]);
                // Constancia (registro mínimo del patrón A, v2.3 §4.6): quién, cuándo y la huella de la última exportación.
                Auditoria::registrar('suscripcion.eliminada', null, $s->fresh(), ['estatus' => Suscripcion::ESTATUS_FINIQUITADO], [
                    'estatus' => Suscripcion::ESTATUS_ELIMINADO, 'cliente_id' => $s->cliente_id, 'producto' => $s->producto->slug,
                    'exportacion_id' => $exp->id, 'sha256' => $exp->sha256, 'descargada_en' => $exp->descargada_en?->toIso8601String(),
                    'retencion_hasta' => $exp->retencion_hasta->toDateString(), 'eliminada_en' => now()->toIso8601String(),
                ]);

                return;
        }
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
        if ($exp->carta_enviada_en && $exp->clave_enviada_en) {
            return $exp;
        }
        $s = $exp->suscripcion->loadMissing(['cliente.operadores', 'producto']);
        $avisos = app(AvisosCliente::class);

        if (! $exp->carta_enviada_en && $exp->motivo !== Exportacion::MOTIVO_FINIQUITO) {
            // (4c) Copia o archivo de empresa: aviso de respaldo listo; copia solo al asesor (no es una salida del cliente).
            $empresa = $exp->solicitud_salida_id ? SolicitudSalida::find($exp->solicitud_salida_id) : null;
            $aviso = new RespaldoListoMail($s->cliente->nombre, $s->producto->nombre, $exp->motivo,
                $empresa ? trim($empresa->empresa_nombre.($empresa->empresa_rfc ? " ({$empresa->empresa_rfc})" : '')) : null,
                $this->contenido($exp), $exp->disponible_hasta->format('d/m/Y'), (string) $exp->sha256, $avisos->asesor($s));
            $r = $this->correo->enviar($aviso, $s->admin_email, $this->contexto($exp));
            if ($r->estado === CorreoEnviado::ENVIADO) {
                $this->correo->enviarA($aviso, array_diff($s->cliente->operadores()->where('activo', true)->pluck('email')->all(), [$s->admin_email]), $this->contexto($exp));
                $exp->update(['carta_enviada_en' => now()]);
            }
        }

        if (! $exp->carta_enviada_en && $exp->motivo === Exportacion::MOTIVO_FINIQUITO) {
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
