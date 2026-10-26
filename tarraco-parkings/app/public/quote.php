<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (Auth::cliente() === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Inicia sesión para calcular el precio.']);
    exit;
}

$idCliente  = (int) Auth::idCliente();
$idParking  = (int) ($_GET['id_parking'] ?? 0);
$idVehiculo = (int) ($_GET['id_vehiculo'] ?? 0);
$entrada    = Reservation::normalizarFecha((string) ($_GET['entrada'] ?? ''));
$salida     = Reservation::normalizarFecha((string) ($_GET['salida'] ?? ''));

try {
    $vehiculo = Vehicle::find($idVehiculo, $idCliente);
    $parking  = Parking::find($idParking);

    if ($vehiculo === null || $parking === null) {
        throw new DomainException('Selecciona un parking y un vehículo válidos.');
    }
    if ($entrada === null || $salida === null || strtotime($salida) <= strtotime($entrada)) {
        throw new DomainException('Revisa las fechas de entrada y salida.');
    }

    $plaza = Parking::plazaDisponible(
        $idParking,
        Parking::tiposCompatibles((string) $vehiculo['tipo_vehiculo']),
        $entrada,
        $salida
    );
    if ($plaza === null) {
        throw new DomainException('No quedan plazas libres de este tipo en esa franja.');
    }

    $tarifa = Parking::tarifaVigente($idParking, (string) $plaza['tipo_plaza'], $entrada);
    if ($tarifa === null) {
        throw new DomainException('No hay tarifa vigente para este parking.');
    }

    $horas    = Payment::horasFacturables($entrada, $salida);
    $importes = Payment::calcular(
        (float) $tarifa['precio_hora'],
        (float) $tarifa['porcentaje_gestion'],
        $horas
    );

    echo json_encode([
        'ok'          => true,
        'horas'       => $importes['horas'],
        'precio_hora' => money((float) $tarifa['precio_hora']),
        'base'        => money($importes['base']),
        'gestion'     => money($importes['gestion']),
        'gestion_pct' => rtrim(rtrim(number_format((float) $tarifa['porcentaje_gestion'] * 100, 2, ',', '.'), '0'), ',') . ' %',
        'total'       => money($importes['total']),
        'plaza_tipo'  => $plaza['tipo_plaza'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
