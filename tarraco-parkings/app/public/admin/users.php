<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

Auth::checkAdmin();
$idAdmin = (int) Auth::idAdmin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['accion'] ?? '') === 'cambiar_estado') {
    Auth::requireRole(['SUPERADMIN', 'ADMIN']);
    verify_csrf();
    $id     = (int) ($_POST['id_cliente'] ?? 0);
    $nuevo  = ($_POST['nuevo_estado'] ?? '') === 'ACTIVO' ? 'ACTIVO' : 'INACTIVO';

    $stmt = Database::connection()->prepare('SELECT * FROM CLIENTE WHERE id_cliente = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $antes = $stmt->fetch();

    if ($antes) {
        Database::connection()
            ->prepare('UPDATE CLIENTE SET estado = :estado WHERE id_cliente = :id')
            ->execute(['estado' => $nuevo, 'id' => $id]);

        Audit::log($idAdmin, 'CLIENTE', $id, 'MODIFICAR',
            ['estado' => $antes['estado']], ['estado' => $nuevo]);
        flash('success', 'Estado del cliente actualizado a ' . $nuevo . '.');
    }

    redirect('/admin/users.php');
}

$q = trim((string) ($_GET['q'] ?? ''));

$sql = "SELECT c.*,
               (SELECT COUNT(*) FROM VEHICULO v WHERE v.id_cliente = c.id_cliente AND v.activo = 1) AS vehiculos,
               (SELECT COUNT(*) FROM RESERVA r WHERE r.id_cliente = c.id_cliente) AS reservas,
               (SELECT COALESCE(SUM(p.importe), 0)
                  FROM PAGO p
                  JOIN RESERVA r2 ON r2.id_reserva = p.id_reserva
                 WHERE r2.id_cliente = c.id_cliente AND p.estado = 'CONFIRMADO') AS pagado
          FROM CLIENTE c
         WHERE 1 = 1";
$params = [];

if ($q !== '') {
    $sql .= ' AND (c.nombre LIKE :q OR c.apellidos LIKE :q2 OR c.email LIKE :q3 OR c.documento_ident LIKE :q4)';
    $like = '%' . $q . '%';
    $params = ['q' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
}

$sql .= ' ORDER BY c.fecha_registro DESC LIMIT 200';

$stmt = Database::connection()->prepare($sql);
$stmt->execute($params);
$clientes = $stmt->fetchAll();

render_header('Clientes', 'usuarios', true);
?>

<div class="page-head">
    <div>
        <p class="eyebrow">Panel de gestión</p>
        <h1>Clientes</h1>
        <p>Consulta de cuentas y baja lógica. Un cliente con historial no se borra nunca.</p>
    </div>
</div>

<section class="card">
    <div class="card-head">
        <h2><?= plural(count($clientes), 'cliente', 'clientes') ?></h2>
        <form method="get" action="/admin/users.php" class="inline-form">
            <label class="sr-only" for="u-q">Buscar clientes</label>
            <input type="search" id="u-q" name="q" value="<?= e($q) ?>"
                   placeholder="Nombre, correo o documento">
            <button class="btn btn-ghost btn-small" type="submit">Buscar</button>
        </form>
    </div>

    <?php if ($clientes === []): ?>
        <div class="empty">No hay clientes que coincidan con la búsqueda.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <caption><span class="sr-only">Clientes</span></caption>
                <thead>
                    <tr>
                        <th scope="col">Cliente</th><th scope="col">Correo</th><th scope="col">Documento</th><th class="num" scope="col">Vehículos</th>
                        <th class="num" scope="col">Reservas</th><th class="num" scope="col">Pagado</th><th scope="col">Alta</th><th scope="col">Estado</th><th scope="col">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($clientes as $c): ?>
                    <tr>
                        <td><strong><?= e($c['nombre'] . ' ' . $c['apellidos']) ?></strong></td>
                        <td><?= e($c['email']) ?></td>
                        <td class="mono"><?= e($c['documento_ident']) ?></td>
                        <td class="num"><?= (int) $c['vehiculos'] ?></td>
                        <td class="num"><?= (int) $c['reservas'] ?></td>
                        <td class="num"><?= money((float) $c['pagado']) ?></td>
                        <td><?= fecha($c['fecha_registro'], 'd/m/Y') ?></td>
                        <td>
                            <span class="badge badge-<?= $c['estado'] === 'ACTIVO' ? 'FINALIZADA' : 'CANCELADA' ?>">
                                <?= e($c['estado']) ?>
                            </span>
                        </td>
                        <td class="num">
                            <form method="post" action="/admin/users.php" class="inline-form"
                                  data-confirm="¿Cambiar el estado de <?= e($c['nombre']) ?> a <?= $c['estado'] === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO' ?>?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="cambiar_estado">
                                <input type="hidden" name="id_cliente" value="<?= (int) $c['id_cliente'] ?>">
                                <input type="hidden" name="nuevo_estado" value="<?= $c['estado'] === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO' ?>">
                                <button class="btn btn-ghost btn-small" type="submit">
                                    <?= $c['estado'] === 'ACTIVO' ? 'Desactivar' : 'Activar' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php render_footer(); ?>
