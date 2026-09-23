<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\Producto;
use App\Models\Landlord\ProductoModulo;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SembrarCatalogoCommand extends Command
{
    protected $signature = 'landlord:sembrar-catalogo
        {--comercializa-url= : base_url_interna de Comercializa (default dev: http://127.0.0.1:8100)}
        {--hrm-url= : base_url_interna de Bridge HRM (default dev: http://127.0.0.1:8300)}
        {--svi-url= : base_url_interna de Bridge SVI (default dev: http://127.0.0.1:8400)}';

    protected $description = 'Siembra el catálogo de productos (comercializa/hrm/svi) y los módulos de Comercializa. Idempotente: no regenera token_interno si el producto ya existe. Las URLs son por ambiente -- pásalas por opción al correr esto en QA/producción, no edites los defaults de dev.';

    private function productos(): array
    {
        return [
            [
                'slug' => 'comercializa',
                'nombre' => 'Comercializa',
                'base_url_interna' => $this->option('comercializa-url') ?: 'http://127.0.0.1:8100',
                'modo_datos' => Producto::MODO_DEDICADA,
                'prefijo_db' => 'com',
            ],
            [
                'slug' => 'hrm',
                'nombre' => 'Bridge HRM',
                'base_url_interna' => $this->option('hrm-url') ?: 'http://127.0.0.1:8300',
                'modo_datos' => Producto::MODO_COMPARTIDA,
                'prefijo_db' => null,
            ],
            [
                'slug' => 'svi',
                'nombre' => 'Bridge SVI',
                'base_url_interna' => $this->option('svi-url') ?: 'http://127.0.0.1:8400',
                'modo_datos' => Producto::MODO_DEDICADA,
                'prefijo_db' => 'svi',
            ],
        ];
    }

    /** Mapa validado en RESPUESTA_COMERCIALIZA_A_KERNIA_2026-09-22.md §3. */
    private const MODULOS_COMERCIALIZA = [
        'ventas' => 'Ventas',
        'compras' => 'Compras',
        'inventarios' => 'Inventarios',
        'tesoreria' => 'Tesorería',
        'viaticos' => 'Viáticos',
    ];

    public function handle(): int
    {
        $tokensNuevos = [];

        foreach ($this->productos() as $datos) {
            $producto = Producto::where('slug', $datos['slug'])->first();

            if ($producto) {
                $producto->fill([
                    'nombre' => $datos['nombre'],
                    'base_url_interna' => $datos['base_url_interna'],
                    'modo_datos' => $datos['modo_datos'],
                    'prefijo_db' => $datos['prefijo_db'],
                ])->save();

                $this->line("Producto '{$producto->slug}' actualizado (token_interno sin cambios).");
            } else {
                $token = Str::random(64);

                $producto = Producto::create([
                    ...$datos,
                    'token_interno' => $token,
                ]);

                $tokensNuevos[$producto->slug] = $token;
                $this->info("Producto '{$producto->slug}' creado.");
            }
        }

        $comercializa = Producto::where('slug', 'comercializa')->first();

        foreach (self::MODULOS_COMERCIALIZA as $clave => $nombre) {
            ProductoModulo::updateOrCreate(
                ['producto_id' => $comercializa->id, 'clave' => $clave],
                ['nombre' => $nombre]
            );
        }

        $this->line('Módulos de Comercializa sembrados: ' . implode(', ', array_keys(self::MODULOS_COMERCIALIZA)));

        if ($tokensNuevos) {
            $this->warn('Tokens internos generados -- ÚNICA vez que se muestran en claro. Entrégalos por canal seguro a cada agente (NO en un .md de 0. Contexto):');
            foreach ($tokensNuevos as $slug => $token) {
                $this->line("  {$slug}: {$token}");
            }
        }

        return self::SUCCESS;
    }
}
