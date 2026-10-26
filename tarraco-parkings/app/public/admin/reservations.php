<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

Auth::checkAdmin();
$idAdmin = (int) Auth::idAdmin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // OPERADOR puede consultar y filtrar, pero no modificar ni cancelar.
    Auth::requireRole(['SUPERADMIN', 'ADMIN']);
    verify_csrf();
    $accion = (string) ($_POST['accion'] ?? '');

    try {
        if ($accion === 'actualizar') {
            Reservation::adminActualizar((int) ($_POST['id_reserva'] ?? 0), $_POST, $idAdmin);
            flash('success', 'Reserva actualizada. Se ha recalculado el importe.');
            redirect('/admin/reservations.php');
        }

        if ($accion === 'cancelar') {
            Reservation::adminCancelar(
                (int) ($_POST['id_reserva'] ?? 0),
                $idAdmin,
                trim((string) ($_POST['motivo'] ?? ''))
            );
            flash('success', 'Reserva cancelada y pago reembolsado.');
            redirect('/admin/reservations.php');
        }
    } catch (DomainException $e) {
        flash('danger', $e->getMessage());
        redirect('/admin/reservations.php');
    }
}

$filtros = [
    'estado'  => (string) ($_GET['estado'] ?? ''),
    'parking' => (string) ($_GET['parking'] ?? ''),
    'q'       => (string) ($_GET['q'] ?? ''),
];

$reservas = Reservation::adminList($filtros);
$parkings = Parking::all();
$editando = isset($_GET['edit']) ? Reservation::find((int) $_GET['edit']) : null;
$plazas   = $editando ? Parking::plazasDeParking((int) $editando['id_parking']) : [];

$entradaEdit = $editando ? date('Y-m-d\TH:i', strtotime($editando['fecha_hora_entrada_prevista'])) : '';
$salidaEdit  = $editando ? date('Y-m-d\TH:i', strtotime($editando['fecha_hora_salida_prevista'])) : '';

render_header('Reservas', 'reservas', true);
?>

<div class="page-head">
    <div>
        <p class="eyebrow">Panel de gestión</p>
        <h1>Reservas</h1>
        <p>Se pueden modificar fechas y plaza, y cancelar. Nunca se borran.</p>
    </div>
</div>

<?php if ($editando): ?>
    <section class="card">
        <div class="card-head">
            <h2>Editar <?= e($editando['codigo_reserva']) ?></h2>
            <a class="btn btn-ghost btn-small" href="/admin/reservations.php">Cerrar</a>
        </div>

        <div class="grid grid-2" style="align-items:start;">
            <form method="post" action="/admin/reservations.php" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="actualizar">
                <input type="hidden" name="id_reserva" value="<?= (int) $editando['id_reserva'] ?>">

                <div class="form-grid">
                    <div class="field">
                        <label for="entrada">Entrada prevista</label>
                        <input type="datetime-local" id="entrada" name="entrada" value="<?= e($entradaEdit) ?>" required>
                    </div>
                    <div class="field">
                        <label for="salida">Salida prevista</label>
                        <input type="datetime-local" id="salida" name="salida" value="<?= e($salidaEdit) ?>" required>
                    </div>
                    <div class="field field-full">
                        <label for="id_plaza">Plaza</label>
                        <select id="id_plaza" name="id_plaza">
                            <option value="" <?= empty($editando['id_plaza']) ? 'selected' : '' ?>>Sin asignar</option>
                            <?php foreach ($plazas as $pl): ?>
                                <?php if ($pl['estado'] === 'FUERA_SERVICIO' && (int) $pl['id_plaza'] !== (int) $editando['id_plaza']) { continue; } ?>
                                <option value="<?= (int) $pl['id_plaza'] ?>"<?= (int) $pl['id_plaza'] === (int) $editando['id_plaza'] ? ' selected' : '' ?>>
                                    <?= e($pl['codigo_plaza']) ?> · <?= e($pl['tipo_plaza']) ?> · <?= e($pl['estado']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field field-full">
                        <label for="observaciones">Observaciones</label>
                        <textarea id="observaciones" name="observaciones" rows="2" maxlength="255"><?= e($editando['observaciones'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Guardar cambios</button>
                </div>
                <p class="hint" style="margin-top:.6rem;">
                    Al cambiar las fechas se recalcula el importe de la reserva. Los pagos ya
                    registrados no se modifican automáticamente.
                </p>
            </form>

            <aside>
                <dl class="dl">
                    <div><dt>Cliente</dt><dd><?= e($editando['cliente_nombre'] . ' ' . $editando['cliente_apellidos']) ?></dd></div>
                    <div><dt>Correo</dt><dd><?= e($editando['cliente_email']) ?></dd></div>
                    <div><dt>Vehículo</dt><dd class="mono"><?= e($editando['matricula']) ?></dd></div>
                    <div><dt>Parking</dt><dd><?= e($editando['parking_nombre']) ?></dd></div>
                    <div><dt>Estado</dt><dd><span class="badge badge-<?= e($editando['estado']) ?>"><?= e($editando['estado']) ?></span></dd></div>
                    <div><dt>Importe</dt><dd><?= money($editando['importe_total']) ?></dd></div>
                </dl>

                <?php if (in_array($editando['estado'], ['PENDIENTE_PAGO', 'ACTIVA'], true)): ?>
                    <form method="post" action="/admin/reservations.php" class="card" style="margin-top:1rem;"
                          data-confirm="¿Cancelar la reserva <?= e($editando['codigo_reserva']) ?> y reembolsar el pago?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="cancelar">
                        <input type="hidden" name="id_reserva" value="<?= (int) $editando['id_reserva'] ?>">
                        <div class="field">
                            <label for="motivo">Motivo de la cancelación</label>
                            <input type="text" id="motivo" name="motivo" maxlength="180" placeholder="Avería, petición del cliente...">
                        </div>
                        <div class="form-actions">
                            <button class="btn btn-danger btn-block" type="submit">Cancelar la reserva</button>
                        </div>
                    </form>
                <?php endif; ?>
            </aside>
        </div>
    </section>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <h2><?= plural(count($reservas), 'reserva', 'reservas') ?></h2>
        <form method="get" action="/admin/reservations.php" class="inline-form">
            <label class="sr-only" for="f-q">Buscar reservas</label>
            <input type="search" id="f-q" name="q" value="<?= e($filtros['q']) ?>"
                   placeholder="Código, matrícula o correo">
            <label class="sr-only" for="f-estado">Estado</label>
            <select id="f-estado" name="estado">
                <option value="">Todos los estados</option>
                <?php foreach (Reservation::ESTADOS as $estado): ?>
                    <option value="<?= e($estado) ?>" <?= $filtros['estado'] === $estado ? 'selected' : '' ?>><?= e(etiqueta_estado($estado)) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="sr-only" for="f-parking">Parking</label>
            <select id="f-parking" name="parking">
                <option value="">Todos los parkings</option>
                <?php foreach ($parkings as $p): ?>
                    <option value="<?= (int) $p['id_parking'] ?>" <?= (string) $p['id_parking'] === $filtros['parking'] ? 'selected' : '' ?>>
                        <?= e($p['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-ghost btn-small" type="submit">Filtrar</button>
        </form>
    </div>

    <?php if ($reservas === []): ?>
        <div class="empty">No hay reservas con estos filtros.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data compacta">
                <caption><span class="sr-only">Reservas</span></caption>
                <thead>
                    <tr>
                        <th scope="col">Código</th><th scope="col">Cliente</th><th scope="col">Parking</th><th scope="col">Vehículo</th>
                        <th scope="col">Entrada</th><th scope="col">Salida</th><th class="num" scope="col">Total</th><th scope="col">Estado</th><th scope="col">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reservas as $r): ?>
                    <tr>
                        <td class="mono"><?= e($r['codigo_reserva']) ?></td>
                        <td><?= e($r['cliente_nombre'] . ' ' . $r['cliente_apellidos']) ?></td>
                        <td><?= e($r['parking_nombre']) ?></td>
                        <td class="mono"><?= e($r['matricula']) ?></td>
                        <td><?= fecha($r['fecha_hora_entrada_prevista']) ?></td>
                        <td><?= fecha($r['fecha_hora_salida_prevista']) ?></td>
                        <td class="num"><?= money($r['importe_total']) ?></td>
                        <td><span class="badge badge-<?= e($r['estado']) ?>"><?= e(etiqueta_estado($r['estado'])) ?></span></td>
                        <td class="num">
                            <?php if (in_array($r['estado'], ['PENDIENTE_PAGO', 'ACTIVA'], true)): ?>
                                <a class="btn btn-ghost btn-small" href="/admin/reservations.php?edit=<?= (int) $r['id_reserva'] ?>">Editar</a>
                                <form method="post" action="/admin/reservations.php" class="inline-form"
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
</section>

<?php render_footer(); ?>
