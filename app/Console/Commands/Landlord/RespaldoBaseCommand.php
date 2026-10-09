<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\RespaldoBase;
use App\Services\Landlord\RespaldoBaseService;
use Illuminate\Console\Command;

/**
 * (09-oct-2026) Respaldo cifrado de la base de un cliente del patrón A, hecho
 * por Kernia. Sin --descifrar crea uno (motivo "manual"); con --descifrar=ID
 * lo descifra a un archivo SQL para una restauración EXCEPCIONAL (decisión
 * del 05-oct: la autoriza el escalafón; queda en la bitácora de la bóveda).
 */
class RespaldoBaseCommand extends Command
{
    use Concerns\BuscaSuscripcion;

    protected $signature = 'landlord:respaldo-base
        {cliente_slug? : Slug del cliente}
        {producto_slug? : comercializa|svi}
        {--descifrar= : id del respaldo a descifrar}
        {--salida= : archivo SQL de salida (con --descifrar)}
        {--motivo= : por qué se descifra (obligatorio con --descifrar)}';

    protected $description = 'Crea un respaldo cifrado de la base de un cliente del patrón A, o lo descifra para una restauración excepcional.';

    public function handle(RespaldoBaseService $respaldos): int
    {
        if ($id = $this->option('descifrar')) {
            $r = RespaldoBase::find($id);
            if (! $r || ! $this->option('salida') || ! trim((string) $this->option('motivo'))) {
                $this->error('Indica un respaldo existente, --salida y --motivo.');

                return self::FAILURE;
            }
            $respaldos->descifrar($r, $this->option('salida'), 'Restauración excepcional: '.$this->option('motivo'));
            \App\Models\Landlord\Auditoria::registrar('respaldo_base.descifrado', null, $r->suscripcion, null, ['respaldo_id' => $r->id, 'motivo' => $this->option('motivo')]);
            $this->warn("Descifrado en {$this->option('salida')}. Contiene datos del cliente en claro: bórralo en cuanto termines.");

            return self::SUCCESS;
        }

        $s = $this->buscarSuscripcion((string) $this->argument('cliente_slug'), (string) $this->argument('producto_slug'));
        if (! $s) {
            return self::FAILURE;
        }
        $r = $respaldos->crear($s, 'manual');
        $this->info("Respaldo {$r->id}: {$r->tamano_bytes} bytes cifrados, SHA-256 {$r->sha256}, se conserva hasta el {$r->expira_en->format('d/m/Y')}.");

        return self::SUCCESS;
    }
}
