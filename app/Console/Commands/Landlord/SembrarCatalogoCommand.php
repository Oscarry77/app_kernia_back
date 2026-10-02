<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\Producto;
use App\Models\Landlord\ProductoExtra;
use App\Models\Landlord\ProductoModulo;
use App\Models\Landlord\ProductoPlan;
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

    /**
     * Estándar v2.1 (01-oct-2026, decisión del dueño). La edición define los
     * módulos (Viáticos solo existe junto con Tesorería) y las empresas base;
     * se suman empresas adicionales. null = sin límite.
     */
    private const PLANES = [
        'comercializa' => [
            ['codigo' => 'basico', 'nombre' => 'Backoffice Básico', 'orden' => 1,
                'modulos' => ['ventas', 'compras', 'inventarios'], 'limites' => ['max_empresas' => 4]],
            ['codigo' => 'profesional', 'nombre' => 'Backoffice Profesional', 'orden' => 2,
                'modulos' => ['ventas', 'compras', 'inventarios', 'tesoreria'], 'limites' => ['max_empresas' => 8]],
            ['codigo' => 'corporativo', 'nombre' => 'Backoffice Corporativo', 'orden' => 3,
                'modulos' => ['ventas', 'compras', 'inventarios', 'tesoreria', 'viaticos'], 'limites' => ['max_empresas' => 10]],
        ],
        // CATALOGO_PLANES_HRM_2026-09-22.md -- HRM aún aplica su tabla local;
        // estos límites viajan en `resolve` para cuando migre a ellos.
        'hrm' => [
            ['codigo' => 'basico', 'nombre' => 'Básico', 'orden' => 1, 'modulos' => null,
                'limites' => ['max_empresas' => 1, 'max_empleados' => 250, 'max_usuarios' => null]],
            ['codigo' => 'estandar', 'nombre' => 'Estándar', 'orden' => 2, 'modulos' => null,
                'limites' => ['max_empresas' => 3, 'max_empleados' => 500, 'max_usuarios' => null]],
            ['codigo' => 'profesional', 'nombre' => 'Profesional', 'orden' => 3, 'modulos' => null,
                'limites' => ['max_empresas' => 5, 'max_empleados' => 750, 'max_usuarios' => null]],
            ['codigo' => 'senior', 'nombre' => 'Senior', 'orden' => 4, 'modulos' => null,
                'limites' => ['max_empresas' => 7, 'max_empleados' => 1000, 'max_usuarios' => null]],
            ['codigo' => 'premium', 'nombre' => 'Premium', 'orden' => 5, 'modulos' => null,
                'limites' => ['max_empresas' => null, 'max_empleados' => null, 'max_usuarios' => null]],
        ],
    ];

    /** Viáticos no existe sin Tesorería (decisión del dueño). */
    private const REQUIERE = [
        'viaticos' => ['tesoreria'],
    ];

    private const DESCRIPTIVOS = [
        'comercializa' => ['nombre_corto' => 'COM', 'permite_ws_cntpaq' => true,
            'descripcion' => 'BackOffice: ventas, compras, inventarios, tesorería y viáticos, con contabilización de pólizas.'],
        'hrm' => ['nombre_corto' => 'HRM', 'permite_ws_cntpaq' => true,
            'descripcion' => 'Cálculo de nómina, IMSS/INFONAVIT y administración de personal.'],
        'svi' => ['nombre_corto' => 'SVI', 'permite_ws_cntpaq' => false,
            'descripcion' => 'Generación de informes para la autoridad tributaria.'],
    ];

    private const EXTRAS = [
        'comercializa' => [
            ['codigo' => 'empresa_adicional', 'nombre' => 'Empresa adicional', 'limite' => 'max_empresas', 'incremento' => 1],
        ],
        'hrm' => [
            ['codigo' => 'empleados_adicionales', 'nombre' => 'Adición de empleados (AddEmp)', 'limite' => 'max_empleados', 'incremento' => 100],
        ],
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
            $modulo = ProductoModulo::firstOrCreate(
                ['producto_id' => $comercializa->id, 'clave' => $clave],
                ['nombre' => $nombre]
            );
            // Dependencias (02-oct-2026): solo si aún no están declaradas.
            if ($modulo->requiere === null && isset(self::REQUIERE[$clave])) {
                $modulo->update(['requiere' => self::REQUIERE[$clave]]);
            }
        }

        // Datos descriptivos (02-oct-2026): solo la primera vez (sin nombre
        // corto todavía); después se editan desde el panel y no se pisan.
        foreach (self::DESCRIPTIVOS as $slug => $datos) {
            $producto = Producto::where('slug', $slug)->first();
            if ($producto->nombre_corto === null) {
                $producto->update($datos);
            }
        }

        $this->line('Módulos de Comercializa sembrados: ' . implode(', ', array_keys(self::MODULOS_COMERCIALIZA)));

        foreach (self::PLANES as $slug => $planes) {
            $producto = Producto::where('slug', $slug)->first();
            foreach ($planes as $plan) {
                // firstOrCreate (02-oct-2026): los planes se editan desde el
                // panel; volver a correr este comando no debe revertirlos.
                ProductoPlan::firstOrCreate(
                    ['producto_id' => $producto->id, 'codigo' => $plan['codigo']],
                    [...$plan, 'activo' => true]
                );
            }
            foreach (self::EXTRAS[$slug] ?? [] as $extra) {
                ProductoExtra::firstOrCreate(
                    ['producto_id' => $producto->id, 'codigo' => $extra['codigo']],
                    [...$extra, 'activo' => true]
                );
            }
            $this->line("Planes de '{$slug}' sembrados: ".implode(', ', array_column($planes, 'codigo')));
        }

        if ($tokensNuevos) {
            $this->warn('Tokens internos generados -- ÚNICA vez que se muestran en claro. Entrégalos por canal seguro a cada agente (NO en un .md de 0. Contexto):');
            foreach ($tokensNuevos as $slug => $token) {
                $this->line("  {$slug}: {$token}");
            }
        }

        return self::SUCCESS;
    }
}
