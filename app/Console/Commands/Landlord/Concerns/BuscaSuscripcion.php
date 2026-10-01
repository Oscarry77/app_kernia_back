<?php
namespace App\Console\Commands\Landlord\Concerns;

use App\Models\Landlord\Cliente;
use App\Models\Landlord\Producto;
use App\Models\Landlord\Suscripcion;

trait BuscaSuscripcion
{
    private function buscarSuscripcion(string $clienteSlug, string $productoSlug): ?Suscripcion
    {
        $cliente = Cliente::where('slug', strtolower($clienteSlug))->first();
        $producto = Producto::where('slug', strtolower($productoSlug))->first();

        $suscripcion = ($cliente && $producto)
            ? Suscripcion::where('cliente_id', $cliente->id)->where('producto_id', $producto->id)->first()
            : null;

        if (! $suscripcion) {
            $this->error("No existe la suscripción de '{$clienteSlug}' a '{$productoSlug}'.");
        }

        return $suscripcion;
    }
}
