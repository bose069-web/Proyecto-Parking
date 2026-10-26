<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

Auth::checkAuth();
$idCliente = (int) Auth::idCliente();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $accion = (string) ($_POST['accion'] ?? '');

    try {
        if ($accion === 'crear') {
            Vehicle::create($idCliente, $_POST);
            flash('success', 'Vehículo añadido.');
            redirect('/vehicles.php');
        }

        if ($accion === 'actualizar') {
            Vehicle::update((int) ($_POST['id_vehiculo'] ?? 0), $idCliente, $_POST);
            flash('success', 'Vehículo actualizado.');
            redirect('/vehicles.php');
        }

        if ($accion === 'baja') {
            Vehicle::deactivate((int) ($_POST['id_vehiculo'] ?? 0), $idCliente);
            flash('success', 'Vehículo dado de baja.');
            redirect('/vehicles.php');
        }
    } catch (DomainException $e) {
        // Lo escrito no se pierde: se repinta el formulario con estos valores.
        remember_old($_POST);
        flash('danger', $e->getMessage());
        redirect('/vehicles.php');
    }
}

$vehiculos = Vehicle::listByCliente($idCliente);

$editando = null;
if (isset($_GET['edit'])) {
    $editando = Vehicle::find((int) $_GET['edit'], $idCliente);
}

$tipos = ['COCHE' => 'Coche', 'MOTO' => 'Moto', 'ADAPTADO' => 'Vehículo adaptado', 'CARGA' => 'Vehículo de carga'];

render_header('Mis vehículos', 'vehiculos');
?>

<div class="page-head">
    <div>
        <p class="eyebrow">Área de cliente</p>
        <h1>Mis vehículos</h1>
        <p>Puedes tener varios vehículos. La matrícula es única y se usa en el control de acceso.</p>
    </div>
</div>

<div class="grid grid-2" style="align-items:start;">
    <section class="card">
        <div class="card-head">
            <h2><?= $editando ? 'Editar vehículo' : 'Añadir vehículo' ?></h2>
            <?php if ($editando): ?>
                <a class="btn btn-ghost btn-small" href="/vehicles.php">Cancelar</a>
            <?php endif; ?>
        </div>

        <form method="post" action="/vehicles.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="<?= $editando ? 'actualizar' : 'crear' ?>">
            <?php if ($editando): ?>
                <input type="hidden" name="id_vehiculo" value="<?= (int) $editando['id_vehiculo'] ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="field">
                    <label for="matricula">Matrícula</label>
                    <input type="text" id="matricula" name="matricula" data-plate
                           value="<?= e(old('matricula', $editando['matricula'] ?? '')) ?>" required maxlength="10"
                           placeholder="1234ABC">
                </div>
                <div class="field">
                    <label for="tipo_vehiculo">Tipo</label>
                    <select id="tipo_vehiculo" name="tipo_vehiculo">
                        <?php foreach ($tipos as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= old('tipo_vehiculo', $editando['tipo_vehiculo'] ?? 'COCHE') === $value ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="marca">Marca</label>
                    <input type="text" id="marca" name="marca" maxlength="50" value="<?= e(old('marca', $editando['marca'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label for="modelo">Modelo</label>
                    <input type="text" id="modelo" name="modelo" maxlength="50" value="<?= e(old('modelo', $editando['modelo'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label for="color">Color</label>
                    <input type="text" id="color" name="color" maxlength="30" value="<?= e(old('color', $editando['color'] ?? '')) ?>">
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editando ? 'Guardar cambios' : 'Añadir vehículo' ?></button>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="card-head"><h2>Tus vehículos (<?= count($vehiculos) ?>)</h2></div>

        <?php if ($vehiculos === []): ?>
            <div class="empty">Todavía no hay ningún vehículo dado de alta.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <caption><span class="sr-only">Tus vehículos</span></caption>
                    <thead>
                        <tr><th scope="col">Matrícula</th><th scope="col">Vehículo</th><th scope="col">Tipo</th><th scope="col">Acciones</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vehiculos as $v): ?>
                        <tr>
                            <td class="mono"><?= e($v['matricula']) ?></td>
                            <td><?= e(trim(($v['marca'] ?? '') . ' ' . ($v['modelo'] ?? ''))) ?: '—' ?></td>
                            <td><?= e($v['tipo_vehiculo']) ?></td>
                            <td class="num">
                                <a class="btn btn-ghost btn-small" href="/vehicles.php?edit=<?= (int) $v['id_vehiculo'] ?>">Editar</a>
                                <form method="post" action="/vehicles.php" class="inline-form"
                                      data-confirm="¿Dar de baja el vehículo <?= e($v['matricula']) ?>?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="accion" value="baja">
                                    <input type="hidden" name="id_vehiculo" value="<?= (int) $v['id_vehiculo'] ?>">
                                    <button class="btn btn-danger btn-small" type="submit">Baja</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="hint" style="margin-top:.8rem;">
                La baja es lógica: el vehículo deja de estar disponible pero sus reservas
                históricas se conservan.
            </p>
        <?php endif; ?>
    </section>
</div>

<?php render_footer(); ?>

<?php clear_old(); ?>
