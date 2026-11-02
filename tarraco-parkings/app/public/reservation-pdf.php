<?php
declare(strict_types=1);

/**
 * Resguardo de la reserva en PDF.
 *
 * El enunciado pide un número de reserva y un PDF para acceder al parking.
 * Se genera al vuelo desde los datos de la reserva, no se guarda en disco: así
 * no hay ficheros huérfanos y el documento siempre coincide con la base.
 *
 * Solo lo puede descargar su dueño, o un administrador con sesión abierta.
 */
require_once __DIR__ . '/../src/bootstrap.php';

$esAdmin = Auth::admin() !== null;
if ($esAdmin) {
    // No basta con que haya sesión de administrador: se revalida contra la base
    // que la cuenta siga activa, igual que hace el resto del panel.
    Auth::checkAdmin();
} else {
    Auth::checkAuth();
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Falta el identificador de la reserva.');
}

$reserva = Reservation::find($id, $esAdmin ? null : (int) Auth::idCliente());
if ($reserva === null) {
    http_response_code(404);
    exit('No existe ninguna reserva con ese identificador.');
}

$pagos = Payment::porReserva($id);
$codigo = (string) $reserva['codigo_reserva'];

$pdf = new Pdf();

// ---------------------------------------------------------------- cabecera
$pdf->texto('TARRACO PARKINGS', 9.5, true, null, null, '0.769 0.380 0.235');
$pdf->salto(2);
$pdf->texto('Resguardo de reserva', 22, true);
$pdf->salto(4);
$pdf->texto(
    'Documento generado por la aplicación web. Preséntalo en el acceso o úsalo para '
    . 'identificarte en el parking.',
    10,
    false,
    null,
    null,
    '0.35 0.40 0.43'
);
$pdf->salto(6);
$pdf->linea(1.2, '0.769 0.380 0.235');
$pdf->salto(10);

// ---------------------------------------------------------------- reserva
$pdf->texto('RESERVA ' . $codigo, 15, true);
$pdf->salto(2);
$pdf->campo('Estado', etiqueta_estado((string) $reserva['estado']));
$pdf->campo('Fecha de creación', fecha((string) $reserva['fecha_creacion']));
$pdf->campo('Cliente', trim((string) $reserva['cliente_nombre'] . ' ' . (string) $reserva['cliente_apellidos']));
$pdf->campo('Correo', (string) $reserva['cliente_email']);
$pdf->campo('Vehículo', (string) $reserva['matricula'] . ' · ' . trim((string) ($reserva['marca'] ?? '') . ' ' . (string) ($reserva['modelo'] ?? '')));
$pdf->linea();
$pdf->salto(4);

$pdf->texto('Acceso', 12, true);
$pdf->salto(2);
$pdf->campo('Parking', (string) $reserva['parking_nombre']);
$pdf->campo('Dirección', (string) $reserva['direccion'] . ', ' . (string) $reserva['ciudad']);
$pdf->campo('Plaza', ($reserva['codigo_plaza'] ?? 'Por asignar') . ' (' . ($reserva['tipo_plaza'] ?? '—') . ')');
$pdf->campo('Entrada prevista', fecha((string) $reserva['fecha_hora_entrada_prevista']));
$pdf->campo('Salida prevista', fecha((string) $reserva['fecha_hora_salida_prevista']));
if (!empty($reserva['fecha_hora_entrada_real'])) {
    $pdf->campo('Entrada real', fecha((string) $reserva['fecha_hora_entrada_real']));
}
if (!empty($reserva['fecha_hora_salida_real'])) {
    $pdf->campo('Salida real', fecha((string) $reserva['fecha_hora_salida_real']));
}
$pdf->linea();
$pdf->salto(4);

// ---------------------------------------------------------------- importes
$pdf->texto('Importe', 12, true);
$pdf->salto(2);
$horas = Payment::horasFacturables(
    (string) $reserva['fecha_hora_entrada_prevista'],
    (string) $reserva['fecha_hora_salida_prevista']
);
$pdf->campo('Horas facturadas', $horas . ' h');
$pdf->campo(
    'Precio por hora',
    money((float) $reserva['precio_hora']) . ' · ' . (int) round((float) $reserva['porcentaje_gestion'] * 100) . ' % de gestión'
);
$pdf->campo('Importe base', money((float) $reserva['importe_base']));
$pdf->campo('Gestión', money((float) $reserva['importe_gestion']));
$pdf->linea(0.4, '0.85 0.83 0.80');
$pdf->campo('TOTAL', money((float) $reserva['importe_total']), 12);
if ((float) ($reserva['importe_exceso'] ?? 0) > 0) {
    $pdf->campo('Exceso cobrado aparte', money((float) $reserva['importe_exceso']));
}
$pdf->salto(4);

foreach ($pagos as $pago) {
    $pdf->texto(
        'Cobro ' . ($pago['tipo_pago'] ?? '') . ' · ' . money((float) $pago['importe'])
        . ' · ' . ($pago['estado'] ?? '') . ' · ' . fecha((string) $pago['fecha_hora'])
        . ' · ref. ' . ($pago['referencia_transaccion'] ?? '—'),
        9,
        false,
        null,
        null,
        '0.45 0.48 0.50'
    );
}

$pdf->salto(14);
$pdf->caja('Código de acceso', $codigo . '   ·   plaza ' . ($reserva['codigo_plaza'] ?? 'por asignar'));
$pdf->salto(14);
$pdf->texto('Condiciones', 9.5, true);
$pdf->texto(
    'La reserva cubre la franja indicada. La entrada se autoriza hasta 30 minutos después '
    . 'de la hora prevista; pasado ese plazo la reserva puede marcarse como no presentada. '
    . 'La salida con más de 15 minutos de retraso genera un cobro de exceso por horas '
    . 'completas o fracción, con el mismo método de pago de la reserva. La reserva no se '
    . 'borra nunca: solo cambia de estado.',
    8.5,
    false,
    null,
    null,
    '0.42 0.45 0.47'
);

$pdf->marcaAgua('DEMO');

$contenido = $pdf->salida();

$nombre = 'reserva-' . preg_replace('/[^A-Za-z0-9\-]/', '', $codigo) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $nombre . '"');
header('Content-Length: ' . strlen($contenido));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $contenido;
