<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\Auditoria;
use App\Models\Landlord\Producto;
use Illuminate\Console\Command;

/**
 * (09-oct-2026) Marca que una app ya cumple el estándar v2.2 (lista de
 * empresas, en_mantenimiento, ajuste-plan y desbloquear). Mientras no lo
 * esté, Kernia aplica las bajas de plan como antes (sin bloquear empresas) y
 * no muestra el expediente de empresas. Se enciende cuando Kernia cierra la
 * prueba conjunta de la app.
 */
class EmpresasV22ProductoCommand extends Command
{
    protected $signature = 'landlord:empresas-v22
        {producto_slug : comercializa|hrm}
        {--apagar : revierte}';

    protected $description = 'Habilita (o deshabilita) la orquestación de bajas de plan v2.2 para una app.';

    public function handle(): int
    {
        $producto = Producto::where('slug', strtolower((string) $this->argument('producto_slug')))->first();
        if (! $producto) {
            $this->error('Producto no encontrado.');

            return self::FAILURE;
        }

        $valor = ! $this->option('apagar');
        if ($valor && $producto->esDedicada()) {
            // Patrón A: Kernia debe respaldar antes de ajuste-plan (v2.2 §3.3, Revisión 3); aún no construido.
            $this->error("{$producto->nombre} usa base dedicada (patrón A): el respaldo previo de Kernia aún no está construido.");

            return self::FAILURE;
        }

        $antes = ['empresas_v22' => (bool) $producto->empresas_v22];
        $producto->update(['empresas_v22' => $valor]);
        Auditoria::registrar('producto.empresas_v22', null, null, $antes, ['producto' => $producto->slug, 'empresas_v22' => $valor]);

        $this->info($valor
            ? "{$producto->nombre}: las bajas de plan ya bloquean empresas y el panel muestra el expediente de empresas."
            : "{$producto->nombre}: orquestación v2.2 deshabilitada.");

        return self::SUCCESS;
    }
}
