<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\Producto;
use App\Models\Landlord\ProductoModulo;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SembrarCatalogoCommand extends Command
{
    protected $signature = 'landlord:sembrar-catalogo';

    protected $description = 'Siembra el catálogo de productos (comercializa/hrm/svi) y los módulos de Comercializa. Idempotente: no regenera token_interno si el producto ya existe.';

    /** @var array<int, array{slug:string,nombre:string,base_url_interna:string,modo_datos:string,prefijo_db:?string}> */
    private const PRODUCTOS = [
        [
            'slug' => 'comercializa',
            'nombre' => 'Comercializa',
            'base_url_interna' => 'http://127.0.0.1:8100',
            'modo_datos' => Producto::MODO_DEDICADA,
            'prefijo_db' => 'com',
        ],
        [
            'slug' => 'hrm',
            'nombre' => 'Bridge HRM',
            'base_url_interna' => 'http://127.0.0.1:8300',
            'modo_datos' => Producto::MODO_COMPARTIDA,
            'prefijo_db' => null,
        ],
        [
            'slug' => 'svi',
            'nombre' => 'Bridge SVI',
            'base_url_interna' => 'http://127.0.0.1:8400',
            'modo_datos' => Producto::MODO_DEDICADA,
            'prefijo_db' => 'svi',
        ],
    ];

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

        foreach (self::PRODUCTOS as $datos) {
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
