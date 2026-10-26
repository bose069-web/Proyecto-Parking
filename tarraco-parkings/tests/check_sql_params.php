#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Comprueba que los marcadores con nombre del SQL y los parámetros que se
 * pasan a execute() coinciden, sin necesidad de una base de datos.
 *
 * Nació de un defecto real: una consulta con marcadores posicionales tenía
 * los parámetros desordenados y ninguna reserva podía crearse. Con
 * marcadores con nombre ese error ya no es posible, y este guion vigila que
 * no se cuele un nombre mal escrito.
 *
 * Uso: php tests/check_sql_params.php
 */

$dir  = __DIR__ . '/../app/src';
$files = glob($dir . '/*.php') ?: [];
$errores = 0;
$consultas = 0;

foreach ($files as $file) {
    $source = file_get_contents($file);
    $name = basename($file);

    // 1. Cadenas SQL con marcadores con nombre.
    preg_match_all(
        '/["\']((?:SELECT|INSERT|UPDATE|DELETE)\b.*?)["\']\s*(?:\)|,|;)/is',
        $source,
        $matches
    );

    $placeholders = [];
    foreach ($matches[1] as $sql) {
        $consultas++;
        if (preg_match_all('/:([a-z_][a-z0-9_]*)/i', $sql, $ph)) {
            foreach ($ph[1] as $p) {
                $placeholders[$p] = true;
            }
        }
    }

    // 2. Parámetros: claves de array y también bindValue/bindParam.
    preg_match_all("/['\"]([a-z_][a-z0-9_]*)['\"]\s*=>/i", $source, $keys);
    $params = array_fill_keys($keys[1], true);

    preg_match_all("/bind(?:Value|Param)\s*\(\s*['\"]:?([a-z_][a-z0-9_]*)/i", $source, $binds);
    foreach ($binds[1] as $b) {
        $params[$b] = true;
    }

    foreach (array_keys($placeholders) as $p) {
        if (!isset($params[$p])) {
            echo "  FALLO {$name}: el marcador :{$p} no tiene parámetro con ese nombre\n";
            $errores++;
        }
    }
}

printf("\n%d consultas SQL con marcadores revisadas en %d archivos.\n", $consultas, count($files));

if ($errores > 0) {
    echo "RESULTADO: {$errores} marcadores sin parámetro.\n";
    exit(1);
}

echo "RESULTADO: todos los marcadores con nombre tienen su parámetro.\n";
