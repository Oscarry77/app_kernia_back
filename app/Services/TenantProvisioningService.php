<?php
namespace App\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;
use PDO;

/**
 * Movido de bridge-com-api el 13-sep-2026, sin cambios de lógica -- es PDO
 * puro, sin dependencias de Eloquent ni del esquema de ningún producto, así
 * que Kernia es el dueño natural (aquí sí tienen sentido las credenciales de
 * administrador de servidor de BD, nunca en el backend de un producto).
 *
 * Crea, contra un servidor MySQL o SQL Server real (usando credenciales de
 * ADMINISTRADOR, nunca las del tenant), la base de datos de un tenant nuevo
 * y sus usuarios acotados:
 *   - un usuario "app" con permisos completos SOLO sobre esa base (el que
 *     usará el backend del producto vía su conexión 'tenant').
 *   - opcionalmente, un usuario "bi" de SOLO LECTURA sobre esa misma base,
 *     para cuando se negocie que el cliente conecte una herramienta de BI
 *     directo a su base -- nunca reutiliza el usuario de la app.
 *
 * Nunca se usan las credenciales de administrador para operar la app día a
 * día -- solo existen en el momento de aprovisionar/otorgar accesos.
 */
class TenantProvisioningService
{
    public static function generarUsuario(string $slug, string $sufijo = ''): string
    {
        $base = 'tn_'.preg_replace('/[^a-z0-9]/', '', strtolower($slug));

        return $sufijo !== '' ? "{$base}_{$sufijo}" : $base;
    }

    public static function generarPassword(): string
    {
        return Str::random(32);
    }

    /**
     * Crea la base del tenant (si no existe) + el usuario de app, y
     * opcionalmente el usuario de BI de solo lectura.
     */
    public static function crearBaseYUsuarios(
        string $driver,
        string $host,
        int $puerto,
        string $adminUsuario,
        string $adminPassword,
        string $baseDatos,
        string $usuarioApp,
        string $passwordApp,
        ?string $usuarioBi = null,
        ?string $passwordBi = null,
    ): void {
        $pdo = self::conectarAdmin($driver, $host, $puerto, $adminUsuario, $adminPassword);

        match ($driver) {
            'mysql' => self::crearMysql($pdo, $baseDatos, $usuarioApp, $passwordApp, $usuarioBi, $passwordBi),
            'sqlsrv' => self::crearSqlServer($pdo, $baseDatos, $usuarioApp, $passwordApp, $usuarioBi, $passwordBi),
            default => throw new InvalidArgumentException("Driver no soportado para aprovisionamiento automático: {$driver}"),
        };
    }

    /**
     * Da de alta (o reemplaza) SOLO el usuario de BI de solo lectura sobre
     * una base que ya existe -- para el caso más realista: se negocia el
     * acceso BI después de que el tenant ya está operando.
     */
    public static function otorgarAccesoBi(
        string $driver,
        string $host,
        int $puerto,
        string $adminUsuario,
        string $adminPassword,
        string $baseDatos,
        string $usuarioBi,
        string $passwordBi,
    ): void {
        $pdo = self::conectarAdmin($driver, $host, $puerto, $adminUsuario, $adminPassword);

        match ($driver) {
            'mysql' => self::crearUsuarioMysql($pdo, $usuarioBi, $passwordBi, self::identificadorSeguro($baseDatos), soloLectura: true),
            'sqlsrv' => self::crearUsuarioSqlServer($pdo, $usuarioBi, $passwordBi, self::identificadorSeguro($baseDatos), rol: 'db_datareader'),
            default => throw new InvalidArgumentException("Driver no soportado para aprovisionamiento automático: {$driver}"),
        };
    }

    /**
     * Crea (o reemplaza, con nueva contraseña) el usuario "app" acotado de
     * una base MySQL que ya existe. Para rotar credenciales o para sacar de
     * una suscripción credenciales que no debían estar ahí (28-sep-2026:
     * `demo-svi` apuntaba a `root`).
     */
    public static function reemplazarUsuarioAppMysql(
        string $host,
        int $puerto,
        string $adminUsuario,
        string $adminPassword,
        string $baseDatos,
        string $usuarioApp,
        string $passwordApp,
    ): void {
        $pdo = self::conectarAdmin('mysql', $host, $puerto, $adminUsuario, $adminPassword);

        self::crearUsuarioMysql($pdo, $usuarioApp, $passwordApp, self::identificadorSeguro($baseDatos), soloLectura: false);
    }

    private static function conectarAdmin(string $driver, string $host, int $puerto, string $usuario, string $password): PDO
    {
        $dsn = $driver === 'mysql'
            ? "mysql:host={$host};port={$puerto}"
            : "sqlsrv:Server={$host},{$puerto}";

        return new PDO($dsn, $usuario, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private static function crearMysql(
        PDO $pdo,
        string $baseDatos,
        string $usuarioApp,
        string $passwordApp,
        ?string $usuarioBi,
        ?string $passwordBi,
    ): void {
        $db = self::identificadorSeguro($baseDatos);

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        self::crearUsuarioMysql($pdo, $usuarioApp, $passwordApp, $db, soloLectura: false);

        if ($usuarioBi !== null && $passwordBi !== null) {
            self::crearUsuarioMysql($pdo, $usuarioBi, $passwordBi, $db, soloLectura: true);
        }

        // Sin FLUSH PRIVILEGES (28-sep-2026): CREATE USER/GRANT ya aplican
        // de inmediato en MySQL 8, y FLUSH exige el privilegio global RELOAD
        // que el usuario acotado `kernia_provisioner` no tiene (ni necesita).
    }

    private static function crearUsuarioMysql(PDO $pdo, string $usuario, string $password, string $db, bool $soloLectura): void
    {
        $u = self::identificadorSeguro($usuario);

        // Host desde el que el usuario del cliente puede conectarse. '%' en
        // dev; en QA/producción, la IP o subred privada de los servidores de
        // las apps (28-sep-2026, lineamientos de seguridad SaaS).
        $h = self::identificadorSeguro(env('TENANT_PROVISION_DB_USER_HOST', '%'), '%.');

        // DROP + CREATE (no ALTER) para que "reemplazar" un usuario BI
        // existente (rotación) sea idempotente sin heredar grants viejos.
        $pdo->exec("DROP USER IF EXISTS '{$u}'@'{$h}'");
        $pdo->exec("CREATE USER '{$u}'@'{$h}' IDENTIFIED BY ".$pdo->quote($password));

        // En GRANT, `_` es comodín: `svi_x` sería el patrón `svi?x`, más amplio
        // que la base real. Se escapa para otorgar SOLO esa base -- y porque
        // `kernia_provisioner` (acotado a `svi\_%`, etc.) no puede otorgar un
        // patrón más amplio que el suyo (error 1044, 28-sep-2026).
        $dbExacta = self::patronGrantExacto($db);

        $privilegio = $soloLectura ? 'SELECT' : 'ALL PRIVILEGES';
        $pdo->exec("GRANT {$privilegio} ON `{$dbExacta}`.* TO '{$u}'@'{$h}'");
    }

    private static function crearSqlServer(
        PDO $pdo,
        string $baseDatos,
        string $usuarioApp,
        string $passwordApp,
        ?string $usuarioBi,
        ?string $passwordBi,
    ): void {
        $db = self::identificadorSeguro($baseDatos);

        $existe = $pdo->prepare('SELECT database_id FROM sys.databases WHERE name = ?');
        $existe->execute([$db]);

        if (! $existe->fetch()) {
            $pdo->exec("CREATE DATABASE [{$db}]");
        }

        self::crearUsuarioSqlServer($pdo, $usuarioApp, $passwordApp, $db, rol: 'db_owner');

        if ($usuarioBi !== null && $passwordBi !== null) {
            self::crearUsuarioSqlServer($pdo, $usuarioBi, $passwordBi, $db, rol: 'db_datareader');
        }
    }

    private static function crearUsuarioSqlServer(PDO $pdo, string $usuario, string $password, string $db, string $rol): void
    {
        $u = self::identificadorSeguro($usuario);
        // CREATE LOGIN no admite parámetros vía prepared statement -- se
        // escapa manualmente la comilla simple (única forma de romper el
        // literal T-SQL) sobre un password generado por generarPassword(),
        // nunca sobre input libre de usuario.
        $passwordEscapado = str_replace("'", "''", $password);

        $loginExiste = $pdo->prepare('SELECT 1 FROM sys.server_principals WHERE name = ?');
        $loginExiste->execute([$u]);

        if (! $loginExiste->fetch()) {
            $pdo->exec("CREATE LOGIN [{$u}] WITH PASSWORD = '{$passwordEscapado}', CHECK_POLICY = ON");
        } else {
            $pdo->exec("ALTER LOGIN [{$u}] WITH PASSWORD = '{$passwordEscapado}'");
        }

        $pdo->exec("USE [{$db}]");

        $usuarioExiste = $pdo->prepare('SELECT 1 FROM sys.database_principals WHERE name = ?');
        $usuarioExiste->execute([$u]);

        if (! $usuarioExiste->fetch()) {
            $pdo->exec("CREATE USER [{$u}] FOR LOGIN [{$u}]");
        }

        $pdo->exec("ALTER ROLE [{$rol}] ADD MEMBER [{$u}]");

        // Vuelve al contexto de la base admin por si el llamador sigue
        // usando esta misma conexión PDO para algo más.
        $pdo->exec('USE [master]');
    }

    /** Nombre de base para GRANT sin comodines (`_` y `%` escapados). Público para probarlo sin MySQL. */
    public static function patronGrantExacto(string $baseDatos): string
    {
        return str_replace(['_', '%'], ['\_', '\%'], $baseDatos);
    }

    /** @param string $extra caracteres adicionales permitidos (p. ej. '%.' para un host MySQL) */
    private static function identificadorSeguro(string $valor, string $extra = ''): string
    {
        if (! preg_match('/^[A-Za-z0-9_'.preg_quote($extra, '/').']+$/', $valor)) {
            throw new InvalidArgumentException("Identificador inválido para provisión de base de datos: {$valor}");
        }

        return $valor;
    }
}
