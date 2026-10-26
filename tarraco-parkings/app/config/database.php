<?php
declare(strict_types=1);

/**
 * Parámetros de conexión a MariaDB.
 * Database.php es el único que lee este archivo.
 */
$host = getenv('DB_HOST') ?: 'db';
$port = (int) (getenv('DB_PORT') ?: 3306);
$name = getenv('DB_NAME') ?: 'tarraco_parkings';

return [
    'dsn'      => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
    'user'     => getenv('DB_USER') ?: 'tarraco',
    'password' => getenv('DB_PASSWORD') ?: '',
    'options'  => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ],
];
