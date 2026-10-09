<?php
namespace App\Services\Landlord;

use App\Models\Landlord\Suscripcion;
use PDO;
use RuntimeException;

/**
 * (09-oct-2026) Borra la base de un cliente del patrón A (la creó Kernia) al
 * terminar la retención del finiquito (estándar v2.3 §4.6). IRREVERSIBLE: por
 * eso se niega si algo no cuadra, nunca "borra por si acaso".
 *
 * Candados:
 *  - la suscripción está `finiquitado` y el producto es de base dedicada;
 *  - el nombre de la base es EXACTAMENTE el que Kernia le dio al crearla
 *    ({prefijo_db}_{slug limpio}), así nunca puede apuntar a otra base;
 *  - con las credenciales del aprovisionador (acotadas a las bases de cada
 *    producto), nunca con las del cliente.
 *
 * Es una clase aparte para poder sustituirla en las pruebas (sin MySQL real).
 */
class EliminadorBases
{
    /** @return array{base: string, usuarios: list<string>} lo que se borró, para la constancia */
    public function eliminar(Suscripcion $s): array
    {
        $producto = $s->producto;
        if ($s->estatus !== Suscripcion::ESTATUS_FINIQUITADO || ! $producto->esDedicada()) {
            throw new RuntimeException('Solo se borra la base de un cliente finiquitado de una app de base dedicada.');
        }
        $esperada = SuscripcionOnboardingService::nombreBaseDatos($producto->prefijo_db, $s->cliente->slug);
        if (! $s->db_database || $s->db_database !== $esperada) {
            throw new RuntimeException("La base registrada ({$s->db_database}) no es la que Kernia creó para este cliente ({$esperada}); no se borra.");
        }
        $admin = env('TENANT_PROVISION_DB_ADMIN_USERNAME');
        $password = env('TENANT_PROVISION_DB_ADMIN_PASSWORD');
        if (! $admin || ! $password) {
            throw new RuntimeException('Faltan TENANT_PROVISION_DB_ADMIN_USERNAME/PASSWORD para borrar la base.');
        }

        $pdo = new PDO("mysql:host={$s->db_host};port=".((int) $s->db_port), $admin, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $host = env('TENANT_PROVISION_DB_USER_HOST', '%');
        $usuarios = array_values(array_filter([$s->db_username, $s->db_bi_username]));
        foreach ($usuarios as $u) {
            $pdo->exec('DROP USER IF EXISTS '.$pdo->quote($u).'@'.$pdo->quote($host));
        }
        $pdo->exec('DROP DATABASE IF EXISTS `'.str_replace('`', '', $esperada).'`');

        return ['base' => $esperada, 'usuarios' => $usuarios];
    }
}
