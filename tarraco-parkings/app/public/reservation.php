<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

Auth::checkAuth();
$idCliente = (int) Auth::idCliente();

// Cancelación desde el detalle.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['accion'] ?? '') === 'cancelar') {
    verify_csrf();
    try {
        $reembolso = Reservation::cancelar((int) ($_POST['id_reserva'] ?? 0), $idCliente);
        flash('success', $reembolso > 0
            ? 'Reserva cancelada. Se han reembolsado ' . money($reembolso) . '.'
            : 'Reserva cancelada.');
    } catch (DomainException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('/my-reservations.php');
}

// ---------------------------- detalle de una reserva ----------------------------
if (isset($_GET['id'])) {
    $reserva = Reservation::find((int) $_GET['id'], $idCliente);
    if ($reserva === null) {
        http_response_code(404);
        render_header('Reserva no encontrada', 'reservas');
        echo '<div class="empty">No existe ninguna reserva tuya con ese identificador. '
            . '<a href="/my-reservations.php">Volver a mis reservas</a>.</div>';
        render_footer();
        exit;
    }

    $pagos = Payment::porReserva((int) $reserva['id_reserva']);
    $cancelable = Reservation::esCancelable($reserva);

    render_header('Reserva ' . $reserva['codigo_reserva'], 'reservas');
    ?>
    <div class="page-head">
        <div>
            <p class="eyebrow">Detalle de la reserva</p>
            <h1><?= e($reserva['codigo_reserva']) ?></h1>
            <p>Estado actual: <span class="badge badge-<?= e($reserva['estado']) ?>"><?= e(etiqueta_estado($reserva['estado'])) ?></span></p>
        </div>
        <div>
            <a class="btn btn-ghost btn-small" href="/my-reservations.php">Volver</a>
            <button class="btn btn-ghost btn-small" type="button" onclick="window.print()">Imprimir</button>
        </div>
    </div>

    <div class="detail-grid">
        <section class="card">
            <div class="card-head"><h2>Datos de la reserva</h2></div>
            <dl class="dl">
                <div><dt>Parking</dt><dd><?= e($reserva['parking_nombre']) ?></dd></div>
                <div><dt>Dirección</dt><dd><?= e($reserva['direccion']) ?>, <?= e($reserva['ciudad']) ?></dd></div>
                <div><dt>Plaza</dt><dd><?= e($reserva['codigo_plaza'] ?? 'Por asignar') ?> (<?= e($reserva['tipo_plaza'] ?? '—') ?>)</dd></div>
                <div><dt>Vehículo</dt><dd><span class="mono"><?= e($reserva['matricula']) ?></span> · <?= e(trim(($reserva['marca'] ?? '') . ' ' . ($reserva['modelo'] ?? ''))) ?></dd></div>
                <div><dt>Entrada prevista</dt><dd><?= fecha($reserva['fecha_hora_entrada_prevista']) ?></dd></div>
                <div><dt>Salida prevista</dt><dd><?= fecha($reserva['fecha_hora_salida_prevista']) ?></dd></div>
                <div><dt>Entrada real</dt><dd><?= fecha($reserva['fecha_hora_entrada_real']) ?></dd></div>
                <div><dt>Salida real</dt><dd><?= fecha($reserva['fecha_hora_salida_real']) ?></dd></div>
                <div><dt>Observaciones</dt><dd><?= e($reserva['observaciones'] ?? '—') ?></dd></div>
            </dl>

            <div class="card-head" style="margin-top:1.4rem;"><h2>Movimientos económicos</h2></div>
            <div class="table-wrap">
                <table class="data">
                    <caption><span class="sr-only">Pagos de la reserva</span></caption>
                    <thead><tr><th scope="col">Tipo</th><th scope="col">Fecha</th><th scope="col">Método</th><th class="num" scope="col">Importe</th><th scope="col">Estado</th></tr></thead>
                    <tbody>
                    <?php foreach ($pagos as $pago): ?>
                        <tr>
                            <td><?= e($pago['tipo_pago']) ?></td>
                            <td><?= fecha($pago['fecha_hora']) ?></td>
                            <td><?= e($pago['metodo_descripcion'] ?? $pago['metodo_tipo']) ?></td>
                            <td class="num"><?= money($pago['importe']) ?></td>
                            <td><span class="badge badge-<?= $pago['estado'] === 'CONFIRMADO' ? 'FINALIZADA' : ($pago['estado'] === 'REEMBOLSADO' ? 'CANCELADA' : 'PENDIENTE_PAGO') ?>"><?= $pago['estado'] === 'CONFIRMADO' ? 'Confirmado' : ($pago['estado'] === 'REEMBOLSADO' ? 'Reembolsado' : 'Pendiente') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <aside>
            <div class="total-box">
                <div class="line"><span>Horas facturadas</span><span><?= Payment::horasFacturables($reserva['fecha_hora_entrada_prevista'], $reserva['fecha_hora_salida_prevista']) ?> h</span></div>
                <div class="line"><span>Importe base</span><span><?= money($reserva['importe_base']) ?></span></div>
                <div class="line"><span>Gestión (<?= rtrim(rtrim(number_format((float) $reserva['porcentaje_gestion'] * 100, 2, ',', '.'), '0'), ',') ?> %)</span><span><?= money($reserva['importe_gestion']) ?></span></div>
                <div class="grand"><span>Total</span><span><?= money($reserva['importe_total']) ?></span></div>
                <?php if ((float) $reserva['importe_exceso'] > 0): ?>
                    <div class="line" style="margin-top:.7rem;color:#ffd9d4;"><span>Exceso cobrado aparte</span><span><?= money($reserva['importe_exceso']) ?></span></div>
                <?php endif; ?>
            </div>

            <?php if ($cancelable): ?>
                <form method="post" action="/reservation.php" class="card" style="margin-top:1rem;"
                      data-confirm="¿Cancelar esta reserva? Se reembolsará el importe pagado.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="cancelar">
                    <input type="hidden" name="id_reserva" value="<?= (int) $reserva['id_reserva'] ?>">
                    <p class="parking-meta">Puedes cancelar sin coste hasta la hora de entrada.</p>
                    <button class="btn btn-danger btn-block" type="submit">Cancelar la reserva</button>
                </form>
            <?php endif; ?>
        </aside>
    </div>
    <?php
    render_footer();
    exit;
}

// ---------------------------- formulario de reserva ----------------------------
$parkings  = Parking::allActive();
$vehiculos = Vehicle::listByCliente($idCliente);

$seleccionParking = (int) ($_POST['id_parking'] ?? $_GET['parking'] ?? 0);
$entradaDefault   = (new DateTimeImmutable('+1 hour'))->format('Y-m-d\TH:i');
$salidaDefault    = (new DateTimeImmutable('+4 hours'))->format('Y-m-d\TH:i');

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    try {
        $idReserva = Reservation::crear($idCliente, $_POST);
        flash('success', 'Reserva confirmada. El pago se ha registrado correctamente.');
        redirect('/reservation.php?id=' . $idReserva);
    } catch (DomainException $e) {
        $error = $e->getMessage();
    }
}

$valores = [
    'id_parking'  => (int) ($_POST['id_parking'] ?? $seleccionParking),
    'id_vehiculo' => (int) ($_POST['id_vehiculo'] ?? ($vehiculos[0]['id_vehiculo'] ?? 0)),
    'entrada'     => (string) ($_POST['entrada'] ?? $entradaDefault),
    'salida'      => (string) ($_POST['salida'] ?? $salidaDefault),
    'observaciones' => (string) ($_POST['observaciones'] ?? ''),
];

render_header('Nueva reserva', 'reservas');
?>

<div class="page-head">
    <div>
        <p class="eyebrow">Área de cliente</p>
        <h1>Reservar una plaza</h1>
        <p>Elige el parking, el vehículo y la franja. El precio se calcula antes de confirmar.</p>
    </div>
</div>

<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($vehiculos === []): ?>
    <div class="empty">
        Necesitas dar de alta un vehículo antes de reservar.
        <div style="margin-top:.8rem;"><a class="btn btn-primary" href="/vehicles.php">Añadir vehículo</a></div>
    </div>
<?php else: ?>
    <form method="post" action="/reservation.php" data-quote-form novalidate>
        <?= csrf_field() ?>
        <div class="detail-grid">
            <section class="card">
                <div class="card-head"><h2>Datos de la reserva</h2></div>
                <div class="form-grid">
                    <div class="field">
                        <label for="id_parking">Parking</label>
                        <select id="id_parking" name="id_parking" required>
                            <option value="">Selecciona un parking</option>
                            <?php foreach ($parkings as $p): ?>
                                <option value="<?= (int) $p['id_parking'] ?>" <?= $valores['id_parking'] === (int) $p['id_parking'] ? 'selected' : '' ?>>
                                    <?= e($p['nombre']) ?> — <?= plural(Parking::plazasLibres((int) $p['id_parking']), 'libre', 'libres') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="id_vehiculo">Vehículo</label>
                        <select id="id_vehiculo" name="id_vehiculo" required>
                            <?php foreach ($vehiculos as $v): ?>
                                <option value="<?= (int) $v['id_vehiculo'] ?>" <?= $valores['id_vehiculo'] === (int) $v['id_vehiculo'] ? 'selected' : '' ?>>
                                    <?= e($v['matricula']) ?> · <?= e(trim(($v['marca'] ?? '') . ' ' . ($v['modelo'] ?? ''))) ?> (<?= e($v['tipo_vehiculo']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="entrada">Entrada prevista</label>
                        <input type="datetime-local" id="entrada" name="entrada" value="<?= e($valores['entrada']) ?>" required>
                    </div>

                    <div class="field">
                        <label for="salida">Salida prevista</label>
                        <input type="datetime-local" id="salida" name="salida" value="<?= e($valores['salida']) ?>" required>
                    </div>

                    <div class="field field-full">
                        <label for="observaciones">Observaciones <span class="hint">(opcional)</span></label>
                        <textarea id="observaciones" name="observaciones" rows="2" maxlength="255"><?= e($valores['observaciones']) ?></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Confirmar y pagar</button>
                    <a class="btn btn-ghost" href="/dashboard.php">Cancelar</a>
                </div>
                <p class="hint" style="margin-top:.8rem;">
                    El pago está simulado: la pasarela confirma el cargo en el acto y la
                    reserva pasa a ACTIVA. No se guarda ningún dato de tarjeta.
                </p>
            </section>

            <aside>
                <div class="total-box">
                    <h2 style="color:#fff;font-size:1rem;margin-bottom:.8rem;">Resumen del precio</h2>
                    <div id="quote" role="status" aria-live="polite">
                        <p class="hint" style="color:#cfe0ea;">Selecciona parking, vehículo y fechas.</p>
                    </div>
                </div>
                <div class="note" style="margin-top:1rem;">
                    <strong>Regla de precio.</strong> Horas completas o fracción, multiplicadas
                    por la tarifa del parking, más el 10 % de gestión. El importe se congela
                    al crear la reserva.
                </div>
            </aside>
        </div>
    </form>
<?php endif; ?>

<?php render_footer(); ?>
