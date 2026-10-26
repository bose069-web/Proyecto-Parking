<?php
declare(strict_types=1);

/**
 * Conexión PDO única (singleton) y helper de transacciones.
 * Todas las consultas de la aplicación usan sentencias preparadas.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static ?string $now = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
        $config = require $root . '/config/database.php';

        self::$pdo = new PDO(
            $config['dsn'],
            $config['user'],
            $config['password'],
            $config['options']
        );

        return self::$pdo;
    }

    /**
     * Ejecuta un bloque dentro de una transacción y deshace si algo falla.
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Hora actual según la base de datos, para no mezclar relojes. */
    public static function now(): string
    {
        if (self::$now === null) {
            self::$now = (string) self::connection()->query('SELECT NOW()')->fetchColumn();
        }

        return self::$now;
    }

    /** Solo para pruebas: obliga a abrir una conexión nueva. */
}
