<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Producto;
use Illuminate\Console\Command;

/**
 * (08-oct-2026) Marca que una app ya reconoce los estados de salida
 * (`retirado`, `en_finiquito`, `finiquitado`; estándar v2.3 §4.1). Mientras
 * no lo esté, Kernia no permite retirar ni finiquitar en ella, para no
 * mandarle un estatus que no entiende. Se enciende cuando Kernia valida la
 * entrega de la app.
 */
class EstatusSalidaProductoCommand extends Command
{
    protected $signature = 'landlord:estatus-salida
        {producto_slug : comercializa|hrm|svi}
        {--apagar : la app deja de reconocerlos (revierte)}
        {--finiquito : además, la app ya exporta y tiene Descargas (v2.3): habilita finiquitar}';

    protected $description = 'Habilita (o deshabilita) los estados de salida v2.3 para una app.';

    public function handle(): int
    {
        $producto = Producto::where('slug', strtolower((string) $this->argument('producto_slug')))->first();
        if (! $producto) {
            $this->error('Producto no encontrado.');

            return self::FAILURE;
        }

        $valor = ! $this->option('apagar');
        // 09-oct-2026: el finiquito se enciende aparte (--finiquito), cuando la app ya tiene v2.3.
        $cambios = $valor
            ? ['estatus_salida' => true, ...($this->option('finiquito') ? ['exportacion_v23' => true] : [])]
            : ['estatus_salida' => false, 'exportacion_v23' => false];
        $antes = ['estatus_salida' => (bool) $producto->estatus_salida, 'exportacion_v23' => (bool) $producto->exportacion_v23];
        $producto->update($cambios);
        Auditoria::registrar('producto.estatus_salida', null, null, $antes, ['producto' => $producto->slug, ...$cambios]);

        $producto->refresh();
        $this->info(match (true) {
            ! $valor => "{$producto->nombre}: retirar y finiquitar quedan deshabilitados.",
            $producto->exportacion_v23 => "{$producto->nombre}: ya se puede retirar, reactivar y finiquitar desde el panel.",
            default => "{$producto->nombre}: ya se puede retirar y reactivar. Finiquitar, cuando tenga v2.3 (--finiquito).",
        });

        return self::SUCCESS;
    }
}
