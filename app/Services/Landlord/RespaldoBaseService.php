<?php
namespace App\Services\Landlord;

use App\Models\Landlord\Auditoria;
use App\Models\Landlord\BovedaSecreto;
use App\Models\Landlord\RespaldoBase;
use App\Models\Landlord\Suscripcion;
use App\Services\Boveda\BovedaService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * (09-oct-2026) Respaldo de la base de un cliente del patrón A (Comercializa,
 * SVI), que hace Kernia porque Kernia administra ese servidor MySQL (estándar
 * v2.2 §3.3, Revisión 3; lineamientos §6.1).
 *
 *  - `mysqldump` con `--single-transaction` (consistente, sin bloquear), con
 *    las credenciales del aprovisionador. La contraseña viaja al proceso por su
 *    ENTORNO (`MYSQL_PWD`), nunca como argumento visible en la lista de procesos.
 *  - La salida se cifra AL VUELO, por bloques (libsodium secretstream
 *    XChaCha20-Poly1305): el respaldo nunca existe en claro en el disco.
 *  - Llave aleatoria de 32 bytes POR respaldo, en la bóveda (`respaldo_base.{id}`),
 *    con la misma caducidad que el archivo.
 *  - Archivo en el disco privado, por cliente: `respaldos_base/{cliente_id}/{id}.krnb`.
 *    Formato: "KRNB1" + cabecera del secretstream + bloques [longitud u32][cifrado].
 */
class RespaldoBaseService
{
    private const MAGIA = 'KRNB1';
    private const BLOQUE = 1048576; // 1 MiB

    public function __construct(private readonly BovedaService $boveda)
    {
    }

    public function crear(Suscripcion $s, string $motivo, ?int $solicitudPlanId = null): RespaldoBase
    {
        if (! $s->producto->esDedicada() || ! $s->db_database) {
            throw new RuntimeException('Kernia solo respalda bases que ella creó (patrón A).');
        }
        $admin = env('TENANT_PROVISION_DB_ADMIN_USERNAME');
        $password = env('TENANT_PROVISION_DB_ADMIN_PASSWORD');
        if (! $admin || ! $password) {
            throw new RuntimeException('Faltan TENANT_PROVISION_DB_ADMIN_USERNAME/PASSWORD para respaldar la base.');
        }

        $r = RespaldoBase::create([
            'suscripcion_id' => $s->id, 'solicitud_plan_id' => $solicitudPlanId, 'motivo' => $motivo, 'base' => $s->db_database,
            'estado' => RespaldoBase::FALLIDO, // pasa a listo solo si todo sale bien
            'expira_en' => now()->addDays((int) config('kernia.respaldo_base_dias'))->toDateString(),
        ]);
        $ruta = "respaldos_base/{$s->cliente_id}/{$r->id}.krnb";
        $disco = Storage::disk('local');
        $disco->makeDirectory(dirname($ruta));
        $absoluta = $disco->path($ruta);

        $llave = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        try {
            $this->volcarCifrado($s, $admin, $password, $llave, $absoluta);
            $this->boveda->guardar($r->claveBoveda(), base64_encode($llave), [
                'tipo' => BovedaSecreto::TIPO_RESPALDO,
                'descripcion' => "Llave del respaldo de la base {$s->db_database} ({$motivo})",
                'cliente_id' => $s->cliente_id, 'suscripcion_id' => $s->id, 'expira_en' => $r->expira_en,
            ], null, "Respaldo de base {$r->id}");
            $r->update(['estado' => RespaldoBase::LISTO, 'ruta' => $ruta, 'tamano_bytes' => filesize($absoluta),
                'sha256' => hash_file('sha256', $absoluta), 'error' => null]);
        } catch (\Throwable $e) {
            @unlink($absoluta);
            $r->update(['error' => mb_substr($e->getMessage(), 0, 300)]);
            Auditoria::registrar('respaldo_base.fallido', null, $s, null, ['respaldo_id' => $r->id, 'error' => $r->error]);
            throw new RuntimeException('No se pudo respaldar la base: '.$e->getMessage(), 0, $e);
        } finally {
            sodium_memzero($llave);
        }

        Auditoria::registrar('respaldo_base.creado', null, $s, null, ['respaldo_id' => $r->id, 'motivo' => $motivo,
            'base' => $r->base, 'tamano_bytes' => $r->tamano_bytes, 'sha256' => $r->sha256]);

        return $r->fresh();
    }

    /** Descifra un respaldo a un archivo SQL (restauración excepcional, autorizada fuera de este método). */
    public function descifrar(RespaldoBase $r, string $destino, string $motivo): void
    {
        $llave = $this->boveda->leer($r->claveBoveda(), $motivo);
        if ($r->estado !== RespaldoBase::LISTO || $llave === null) {
            throw new RuntimeException('El respaldo no está disponible (falló, se eliminó o venció su llave).');
        }
        $llave = base64_decode($llave);
        $entrada = fopen(Storage::disk('local')->path($r->ruta), 'rb');
        $salida = fopen($destino, 'wb');
        try {
            if (fread($entrada, strlen(self::MAGIA)) !== self::MAGIA) {
                throw new RuntimeException('El archivo no es un respaldo de Kernia.');
            }
            $estado = sodium_crypto_secretstream_xchacha20poly1305_init_pull(fread($entrada, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $llave);
            $fin = false;
            while (! $fin && ($largo = fread($entrada, 4)) !== false && strlen($largo) === 4) {
                $bloque = fread($entrada, unpack('N', $largo)[1]);
                $res = sodium_crypto_secretstream_xchacha20poly1305_pull($estado, $bloque);
                if ($res === false) {
                    throw new RuntimeException('El respaldo está dañado o fue alterado.');
                }
                [$claro, $etiqueta] = $res;
                fwrite($salida, $claro);
                $fin = $etiqueta === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (! $fin) {
                throw new RuntimeException('El respaldo está incompleto.');
            }
        } finally {
            fclose($entrada);
            fclose($salida);
            sodium_memzero($llave);
        }
    }

    /** Diario: borra los archivos vencidos (la bóveda purga sus llaves por su cuenta). */
    public function eliminarVencidos(): int
    {
        $vencidos = RespaldoBase::where('estado', RespaldoBase::LISTO)->whereDate('expira_en', '<', now()->toDateString())->get();
        foreach ($vencidos as $r) {
            Storage::disk('local')->delete($r->ruta);
            $this->boveda->purgar($r->claveBoveda(), 'Venció la retención del respaldo de base');
            $r->update(['estado' => RespaldoBase::ELIMINADO]);
        }

        return $vencidos->count();
    }

    /** Línea de mysqldump (lista de argumentos, sin intérprete de comandos). Sustituible en pruebas. */
    protected function comando(Suscripcion $s, string $admin): array
    {
        return [
            config('kernia.mysqldump'), '--host='.$s->db_host, '--port='.((int) $s->db_port), '--user='.$admin,
            '--single-transaction', '--routines', '--triggers', '--no-tablespaces', '--set-gtid-purged=OFF',
            '--default-character-set=utf8mb4', $s->db_database,
        ];
    }

    /** Ejecuta mysqldump y cifra su salida por bloques. La contraseña va por el entorno. */
    protected function volcarCifrado(Suscripcion $s, string $admin, string $password, string $llave, string $destino): void
    {
        $proc = proc_open($this->comando($s, $admin), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos, null, [...getenv(), 'MYSQL_PWD' => $password]);
        if (! is_resource($proc)) {
            throw new RuntimeException('No se pudo ejecutar mysqldump.');
        }

        $salida = fopen($destino, 'wb');
        [$estado, $cabecera] = sodium_crypto_secretstream_xchacha20poly1305_init_push($llave);
        fwrite($salida, self::MAGIA.$cabecera);
        $escribir = function (string $claro, int $etiqueta) use (&$estado, $salida) {
            $cifrado = sodium_crypto_secretstream_xchacha20poly1305_push($estado, $claro, '', $etiqueta);
            fwrite($salida, pack('N', strlen($cifrado)).$cifrado);
        };
        $pendiente = '';
        while (! feof($tubos[1])) {
            $pendiente .= (string) fread($tubos[1], self::BLOQUE);
            while (strlen($pendiente) >= self::BLOQUE) {
                $escribir(substr($pendiente, 0, self::BLOQUE), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
                $pendiente = substr($pendiente, self::BLOQUE);
            }
        }
        $escribir($pendiente, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        fclose($salida);
        $errores = stream_get_contents($tubos[2]);
        fclose($tubos[1]);
        fclose($tubos[2]);
        $codigo = proc_close($proc);
        unset($pendiente);

        if ($codigo !== 0) {
            // El mensaje de mysqldump no lleva la contraseña (va por entorno); se recorta por si acaso.
            throw new RuntimeException("mysqldump terminó con código {$codigo}: ".mb_substr(trim((string) $errores), 0, 200));
        }
    }
}
