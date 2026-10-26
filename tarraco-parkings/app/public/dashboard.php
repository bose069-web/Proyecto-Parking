<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

Auth::checkAuth();

$idCliente = (int) Auth::idCliente();
$cliente   = User::find($idCliente);
$resumen   = Reservation::resumenCliente($idCliente);
$proxima   = Reservation::proxima($idCliente);
$vehiculos = Vehicle::listByCliente($idCliente);
$gastado   = Payment::totalCobradoPorCliente($idCliente);

render_header('Mi panel', 'panel');
?>

<div class="page-head">
    <div>
        <p class="eyebrow">Área de cliente</p>
        <h1>Hola, <?= e($cliente['nombre']) ?></h1>
        <p>Este es el resumen de tu cuenta y de tus reservas.</p>
    </div>
    <a class="btn btn-primary" href="/reservation.php">Nueva reserva</a>
</div>

<div class="grid grid-4">
    <div class="stat">
        <div class="stat-label">Reservas activas</div>
        <div class="stat-value"><?= (int) $resumen['ACTIVA'] + (int) $resumen['EN_CURSO'] ?></div>
        <div class="stat-note"><?= (int) $resumen['EN_CURSO'] ?> en curso ahora</div>
    </div>
    <div class="stat">
        <div class="stat-label">Vehículos</div>
        <div class="stat-value"><?= count($vehiculos) ?></div>
        <div class="stat-note">Dados de alta y activos</div>
    </div>
    <div class="stat">
        <div class="stat-label">Total pagado</div>
        <div class="stat-value"><?= money($gastado) ?></div>
        <div class="stat-note">Pagos confirmados</div>
    </div>
    <div class="stat">
        <div class="stat-label">Finalizadas</div>
        <div class="stat-value"><?= (int) $resumen['FINALIZADA'] + (int) $resumen['CON_EXCESO'] ?></div>
        <div class="stat-note"><?= (int) $resumen['CON_EXCESO'] ?> con exceso</div>
    </div>
</div>

<div class="grid grid-2" style="margin-top: 1.2rem; align-items:start;">
    <section class="card">
        <div class="card-head">
            <h2>Tu próxima reserva</h2>
            <a class="btn btn-ghost btn-small" href="/my-reservations.php">Ver todas</a>
        </div>

        <?php if ($proxima === null): ?>
            <div class="empty">
                No tienes ninguna reserva activa.
                <div style="margin-top:.8rem;"><a class="btn btn-primary btn-small" href="/reservation.php">Reservar una plaza</a></div>
            </div>
        <?php else: ?>
            <dl class="dl">
                <div><dt>Código</dt><dd class="mono"><?= e($proxima['codigo_reserva']) ?></dd></div>
                <div><dt>Parking</dt><dd><?= e($proxima['parking_nombre']) ?></dd></div>
                <div><dt>Plaza</dt><dd><?= e($proxima['codigo_plaza'] ?? 'Por asignar') ?></dd></div>
                <div><dt>Entrada</dt><dd><?= fecha($proxima['fecha_hora_entrada_prevista']) ?></dd></div>
                <div><dt>Salida</dt><dd><?= fecha($proxima['fecha_hora_salida_prevista']) ?></dd></div>
                <div><dt>Total</dt><dd><?= money($proxima['importe_total']) ?></dd></div>
            </dl>
            <div class="form-actions">
                <a class="btn btn-ghost btn-small" href="/reservation.php?id=<?= (int) $proxima['id_reserva'] ?>">Ver detalle</a>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>Tus vehículos</h2>
            <a class="btn btn-ghost btn-small" href="/vehicles.php">Gestionar</a>
        </div>

        <?php if ($vehiculos === []): ?>
            <div class="empty">
                Todavía no has dado de alta ningún vehículo.
                <div style="margin-top:.8rem;"><a class="btn btn-primary btn-small" href="/vehicles.php">Añadir vehículo</a></div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <caption><span class="sr-only">Vehículos de tu cuenta</span></caption>
                    <thead>
                        <tr><th scope="col">Matrícula</th><th scope="col">Vehículo</th><th scope="col">Tipo</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vehiculos as $v): ?>
                        <tr>
                            <td class="mono"><?= e($v['matricula']) ?></td>
                            <td><?= e(trim(($v['marca'] ?? '') . ' ' . ($v['modelo'] ?? ''))) ?: '—' ?></td>
                            <td><?= e($v['tipo_vehiculo']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php render_footer(); ?>
