<?php
declare(strict_types=1);

/**
 * Configuración general de la aplicación.
 * Los valores sensibles llegan por variables de entorno (docker-compose/.env).
 */
return [
    'app' => [
        'name'     => 'Tarraco Parkings',
        'env'      => getenv('APP_ENV') ?: 'production',
        'url'      => getenv('APP_URL') ?: 'https://tarraco.local',
        'timezone' => getenv('APP_TIMEZONE') ?: 'Europe/Madrid',
    ],
    'session' => [
        'name'     => 'TARRACO_SESSID',
        'lifetime' => 3600,
    ],
    'business' => [
        // Tolerancias del control de acceso, en minutos.
        'margen_entrada' => 30,
        'margen_salida'  => 15,
        // Minutos que una reserva pendiente de pago bloquea su plaza.
        'bloqueo_pendiente' => 15,
    ],
];
