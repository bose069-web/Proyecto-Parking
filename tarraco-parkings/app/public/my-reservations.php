<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

Auth::checkAuth();
$idCliente = (int) Auth::idCliente();

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

$todas = Reservation::listByCliente($idCliente);
$filtro = (string) ($_GET['estado'] ?? '');

$reservas = $filtro === ''
    ? $todas
    : array_values(array_filter($todas, static fn (array $r): bool => $r['estado'] === $filtro));

$resumen = Reservation::resumenCliente($idCliente);

render_header('Mis reservas', 'reservas');
?>

<div class="page-head">
    <div>
        <p class="eyebrow">Área de cliente</p>
        <h1>Mis reservas</h1>
        <p>Historial completo. Las reservas no se borran: si no vas, quedan como no presentadas.</p>
    </div>
    <a class="btn btn-primary" href="/reservation.php">Nueva reserva</a>
</div>

<div class="card">
    <div class="card-head">
        <h2><?= plural(count($reservas), 'reserva', 'reservas') ?></h2>
        <form method="get" action="/my-reservations.php" class="inline-form">
            <label for="estado" class="hint">Filtrar por estado</label>
            <select id="estado" name="estado" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach (Reservation::ESTADOS as $estado): ?>
                    <option value="<?= e($estado) ?>" <?= $filtro === $estado ? 'selected' : '' ?>>
                        <?= e(etiqueta_estado($estado)) ?> (<?= (int) ($resumen[$estado] ?? 0) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-ghost btn-small" type="submit">Filtrar</button></noscript>
        </form>
    </div>

    <?php if ($reservas === []): ?>
        <div class="empty">
            No hay reservas con este filtro.
            <div style="margin-top:.8rem;"><a class="btn btn-primary btn-small" href="/reservation.php">Reservar una plaza</a></div>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <caption><span class="sr-only">Tus reservas</span></caption>
                <thead>
                    <tr>
                        <th scope="col">Código</th>
                        <th scope="col">Parking</th>
                        <th scope="col">Vehículo</th>
                        <th scope="col">Entrada</th>
                        <th scope="col">Salida</th>
                        <th class="num" scope="col">Total</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reservas as $r): ?>
                    <?php
                    $cancelable = Reservation::esCancelable($r);
                    ?>
                    <tr>
                        <td class="mono"><?= e($r['codigo_reserva']) ?></td>
                        <td><?= e($r['parking_nombre']) ?><br><span class="hint"><?= e($r['codigo_plaza'] ?? 'Sin plaza') ?></span></td>
                        <td class="mono"><?= e($r['matricula']) ?></td>
                        <td><?= fecha($r['fecha_hora_entrada_prevista']) ?></td>
                        <td><?= fecha($r['fecha_hora_salida_prevista']) ?></td>
                        <td class="num"><?= money($r['importe_total']) ?></td>
                        <td><span class="badge badge-<?= e($r['estado']) ?>"><?= e(etiqueta_estado($r['estado'])) ?></span></td>
                        <td class="num">
                            <a class="btn btn-ghost btn-small" href="/reservation.php?id=<?= (int) $r['id_reserva'] ?>">Ver</a>
                            <?php if ($cancelable): ?>
                                <form method="post" action="/my-reservations.php" class="inline-form"
                                      data-confirm="¿Cancelar la reserva <?= e($r['codigo_reserva']) ?>?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="accion" value="cancelar">
                                    <input type="hidden" name="id_reserva" value="<?= (int) $r['id_reserva'] ?>">
                                    <button class="btn btn-danger btn-small" type="submit">Cancelar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
