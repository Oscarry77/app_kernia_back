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
        {--apagar : la app deja de reconocerlos (revierte)}';

    protected $description = 'Habilita (o deshabilita) los estados de salida v2.3 para una app.';

    public function handle(): int
    {
        $producto = Producto::where('slug', strtolower((string) $this->argument('producto_slug')))->first();
        if (! $producto) {
            $this->error('Producto no encontrado.');

            return self::FAILURE;
        }

        $valor = ! $this->option('apagar');
        $antes = ['estatus_salida' => (bool) $producto->estatus_salida];
        $producto->update(['estatus_salida' => $valor]);
        Auditoria::registrar('producto.estatus_salida', null, null, $antes, ['producto' => $producto->slug, 'estatus_salida' => $valor]);

        $this->info($valor
            ? "{$producto->nombre}: ya se puede retirar y finiquitar desde el panel."
            : "{$producto->nombre}: retirar y finiquitar quedan deshabilitados.");

        return self::SUCCESS;
    }
}
