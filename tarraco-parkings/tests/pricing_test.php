#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Pruebas del cálculo económico, sin base de datos.
 * Uso: php tests/pricing_test.php
 */

require_once __DIR__ . '/../app/src/Payment.php';

$fallos = 0;
$total  = 0;

function check(string $nombre, mixed $esperado, mixed $obtenido): void
{
    global $fallos, $total;
    $total++;

    $ok = is_float($esperado)
        ? abs((float) $esperado - (float) $obtenido) < 0.0001
        : $esperado === $obtenido;

    if ($ok) {
        echo "  OK   {$nombre}\n";
        return;
    }

    $fallos++;
    echo "  FALLO {$nombre}: esperaba " . var_export($esperado, true)
        . ' y obtuve ' . var_export($obtenido, true) . "\n";
}

function checkThrows(string $nombre, callable $fn): void
{
    global $fallos, $total;
    $total++;

    try {
        $fn();
        $fallos++;
        echo "  FALLO {$nombre}: no lanzó ninguna excepción\n";
    } catch (InvalidArgumentException) {
        echo "  OK   {$nombre}\n";
    }
}

echo "Horas facturables\n";
check('4 horas exactas', 4, Payment::horasFacturables('2026-11-10 08:00:00', '2026-11-10 12:00:00'));
check('2 h 30 min se cobran como 3', 3, Payment::horasFacturables('2026-11-10 08:00:00', '2026-11-10 10:30:00'));
check('un minuto se cobra como una hora', 1, Payment::horasFacturables('2026-11-10 08:00:00', '2026-11-10 08:01:00'));
checkThrows('salida anterior a la entrada', static fn () => Payment::horasFacturables('2026-11-10 12:00:00', '2026-11-10 08:00:00'));

echo "\nCálculo de importes\n";
$r = Payment::calcular(1.80, 0.10, 4);
check('base 4 h x 1,80', 7.20, $r['base']);
check('gestión 10 %', 0.72, $r['gestion']);
check('total', 7.92, $r['total']);

$r = Payment::calcular(1.20, 0.10, 1);
check('redondeo a dos decimales (base)', 1.20, $r['base']);
check('redondeo a dos decimales (gestión)', 0.12, $r['gestion']);
check('redondeo a dos decimales (total)', 1.32, $r['total']);

$r = Payment::calcular(2.00, 0.0, 3);
check('gestión cero', 0.0, $r['gestion']);
check('total sin gestión', 6.00, $r['total']);

checkThrows('porcentaje mayor que 1', static fn () => Payment::calcular(1.80, 1.5, 1));
checkThrows('precio negativo', static fn () => Payment::calcular(-1.0, 0.10, 1));
checkThrows('cero horas', static fn () => Payment::calcular(1.80, 0.10, 0));

echo "\nExceso de tiempo\n";
$e = Payment::exceso(1.20, '2026-11-10 12:00:00', '2026-11-10 12:14:00', 15);
check('14 minutos con 15 de margen: sin exceso', 0, $e['horas']);
check('importe cero', 0.0, $e['importe']);

$e = Payment::exceso(1.20, '2026-11-10 12:00:00', '2026-11-10 12:20:00', 15);
check('20 minutos con 15 de margen: 5 min, 1 hora', 1, $e['horas']);
check('importe de una hora', 1.20, $e['importe']);

$e = Payment::exceso(1.20, '2026-11-10 12:00:00', '2026-11-10 14:01:00', 15);
check('121 minutos con 15 de margen: 106 min, 2 horas', 2, $e['horas']);
check('importe de dos horas', 2.40, $e['importe']);

$e = Payment::exceso(1.20, '2026-11-10 12:00:00', '2026-11-10 12:15:00', 0);
check('15 minutos sin margen: 1 hora', 1, $e['horas']);

echo "\n";
if ($fallos > 0) {
    echo "RESULTADO: {$fallos} de {$total} comprobaciones fallidas.\n";
    exit(1);
}

echo "RESULTADO: {$total} comprobaciones correctas.\n";
