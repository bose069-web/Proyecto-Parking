<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

Auth::checkAdmin();
$idAdmin = (int) Auth::idAdmin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['accion'] ?? '') === 'marcar_no_presentadas') {
    Auth::requireRole(['SUPERADMIN', 'ADMIN']);
    verify_csrf();
    $cerradas  = Reservation::marcarNoPresentadas();
    $expiradas = Reservation::expirarPendientes();

    if ($cerradas > 0 || $expiradas > 0) {
        Audit::log($idAdmin, 'RESERVA', null, 'MODIFICAR', null, [
            'no_presentadas' => $cerradas,
            'pendientes_caducadas' => $expiradas,
        ]);
    }

    $partes = [];
    if ($cerradas > 0) {
        $partes[] = plural($cerradas, 'reserva marcada como no presentada',
                                    'reservas marcadas como no presentadas');
    }
    if ($expiradas > 0) {
        $partes[] = plural($expiradas, 'pago pendiente caducado',
                                      'pagos pendientes caducados');
    }

    flash($partes === [] ? 'info' : 'success',
        $partes === [] ? 'No había ninguna reserva que cerrar.' : ucfirst(implode(' y ', $partes)) . '.');
    redirect('/admin/index.php');
}

$resumen  = Reservation::adminResumen();
$parkings = Parking::all();
$accesos  = AccessControl::historial(null, 10);

$ultimas = Reservation::adminList([]);
$ultimas = array_slice($ultimas, 0, 8);

render_header('Panel de administración', 'admin', true);
?>

<div class="page-head">
    <div>
        <p class="eyebrow">Panel de gestión</p>
        <h1>Resumen general</h1>
        <p>Estado de los parkings, reservas y accesos de las últimas horas.</p>
    </div>
    <form method="post" action="/admin/index.php" class="inline-form"
          data-confirm="¿Marcar como NO_PRESENTADA toda reserva activa que ya debería haber entrado y cancelar los pagos pendientes caducados?">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="marcar_no_presentadas">
        <button class="btn btn-ghost btn-small" type="submit">Cerrar reservas caducadas</button>
    </form>
</div>

<div class="grid grid-4">
    <div class="stat">
        <div class="stat-label">Reservas activas</div>
        <div class="stat-value"><?= (int) $resumen['activas'] ?></div>
        <div class="stat-note">Activas y en curso</div>
    </div>
    <div class="stat">
        <div class="stat-label">Entradas hoy</div>
        <div class="stat-value"><?= (int) $resumen['hoy'] ?></div>
        <div class="stat-note">Reservas que empiezan hoy</div>
    </div>
    <div class="stat">
        <div class="stat-label">Clientes activos</div>
        <div class="stat-value"><?= (int) $resumen['clientes'] ?></div>
        <div class="stat-note">Cuentas dadas de alta</div>
    </div>
    <div class="stat">
        <div class="stat-label">Ingresos confirmados</div>
        <div class="stat-value"><?= money($resumen['ingresos']) ?></div>
        <div class="stat-note">Reservas y excesos cobrados</div>
    </div>
</div>

<div class="grid grid-3" style="margin-top:1.2rem;">
    <?php foreach ($parkings as $p): ?>
        <?php
        $idP = (int) $p['id_parking'];
        $oc  = Parking::ocupacion($idP);
        ?>
        <article class="parking-card">
            <h3><?= e($p['nombre']) ?></h3>
            <p class="parking-meta"><?= e($p['direccion']) ?></p>
            <div class="meter <?= $oc['porcentaje'] >= 90 ? 'is-full' : ($oc['porcentaje'] >= 65 ? 'is-high' : '') ?>">
                <span style="width: <?= min(100, $oc['porcentaje']) ?>%"></span>
            </div>
            <p class="parking-meta"><?= plural($oc['ocupadas'], 'ocupada', 'ocupadas') ?> de <?= plural($oc['total'], 'plaza', 'plazas') ?> (<?= $oc['porcentaje'] ?> %)</p>
            <div class="parking-foot">
                <span class="badge badge-<?= (int) $p['activo'] === 1 ? 'LIBRE' : 'FUERA_SERVICIO' ?>">
                    <?= (int) $p['activo'] === 1 ? 'Activo' : 'Inactivo' ?>
                </span>
                <a class="btn btn-ghost btn-small" href="/admin/parkings.php?edit=<?= $idP ?>">Editar</a>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<div class="grid grid-2" style="margin-top:1.2rem; align-items:start;">
    <section class="card">
        <div class="card-head">
            <h2>Últimas reservas</h2>
            <a class="btn btn-ghost btn-small" href="/admin/reservations.php">Ver todas</a>
        </div>
        <div class="table-wrap">
            <table class="data">
                <caption><span class="sr-only">Últimas reservas</span></caption>
                <thead><tr><th scope="col">Código</th><th scope="col">Cliente</th><th scope="col">Entrada</th><th scope="col">Estado</th></tr></thead>
                <tbody>
                <?php foreach ($ultimas as $r): ?>
                    <tr>
                        <td class="mono"><?= e($r['codigo_reserva']) ?></td>
                        <td><?= e($r['cliente_nombre'] . ' ' . $r['cliente_apellidos']) ?></td>
                        <td><?= fecha($r['fecha_hora_entrada_prevista'], 'd/m H:i') ?></td>
                        <td><span class="badge badge-<?= e($r['estado']) ?>"><?= e(etiqueta_estado($r['estado'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>Últimos accesos</h2>
            <a class="btn btn-ghost btn-small" href="/access-control.php">Simulador</a>
        </div>
        <div class="table-wrap">
            <table class="data">
                <caption><span class="sr-only">Últimos accesos</span></caption>
                <thead><tr><th scope="col">Hora</th><th scope="col">Matrícula</th><th scope="col">Parking</th><th scope="col">Resultado</th></tr></thead>
                <tbody>
                <?php foreach ($accesos as $a): ?>
                    <tr>
                        <td><?= fecha($a['fecha_hora'], 'd/m H:i') ?></td>
                        <td class="mono"><?= e($a['matricula_leida']) ?></td>
                        <td><?= e($a['parking_nombre']) ?></td>
                        <td>
                            <span class="badge badge-<?= (int) $a['autorizado'] === 1 ? 'si' : 'no' ?>">
                                <?= (int) $a['autorizado'] === 1 ? 'OK' : 'No' ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php render_footer(); ?>
