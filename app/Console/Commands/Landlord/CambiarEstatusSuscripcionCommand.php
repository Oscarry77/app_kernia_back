<?php
namespace App\Console\Commands\Landlord;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;
use App\Services\Landlord\SuscripcionEstatusService;
use Illuminate\Console\Command;

class CambiarEstatusSuscripcionCommand extends Command
{
    protected $signature = 'landlord:cambiar-estatus-suscripcion
        {cliente_slug : Slug del cliente en Kernia}
        {producto_slug : comercializa|hrm|svi}
        {estatus : activo|suspendido|baja}
        {--motivo= : vencimiento|manual|prorroga|pago_registrado}';

    protected $description = 'Cambia el estatus local de una suscripción y empuja PATCH /internal/v1/clientes/{slug}/estatus a la app para revocación inmediata.';

    public function handle(SuscripcionEstatusService $estatusService): int
    {
        $estatus = strtolower((string) $this->argument('estatus'));

        if (! in_array($estatus, [Suscripcion::ESTATUS_ACTIVO, Suscripcion::ESTATUS_SUSPENDIDO, 'baja'], true)) {
            $this->error("Estatus inválido: '{$estatus}'. Usa activo|suspendido|baja.");

            return self::FAILURE;
        }

        $cliente = Cliente::where('slug', strtolower((string) $this->argument('cliente_slug')))->first();
        if (! $cliente) {
            $this->error('Cliente no encontrado.');

            return self::FAILURE;
        }

        $producto = Producto::where('slug', strtolower((string) $this->argument('producto_slug')))->first();
        if (! $producto) {
            $this->error('Producto no encontrado.');

            return self::FAILURE;
        }

        $suscripcion = Suscripcion::where('cliente_id', $cliente->id)->where('producto_id', $producto->id)->first();
        if (! $suscripcion) {
            $this->error('No existe esa suscripción.');

            return self::FAILURE;
        }

        // 08-oct-2026: la salida (retiro, reactivación, finiquito) solo se mueve por solicitud con escalafón.
        if (in_array($suscripcion->estatus, Suscripcion::ESTATUS_SALIDA, true)) {
            $this->error("La suscripción está '{$suscripcion->estatus}'; se mueve solo con una solicitud de salida autorizada en el panel.");

            return self::FAILURE;
        }

        $pushOk = $estatusService->cambiarEstatus($suscripcion, $estatus, $this->option('motivo'));

        $this->info("Estatus local actualizado a '{$estatus}'.");

        if ($pushOk) {
            $this->info('La app confirmó la notificación (revocación inmediata).');
        } else {
            $this->warn('La app NO confirmó la notificación. El cambio local ya aplica y el aviso quedó pendiente:');
            $this->warn('landlord:reintentar-notificaciones-estatus lo reintenta cada minuto (con espera creciente) hasta que la app lo confirme.');
        }

        return self::SUCCESS;
    }
}
