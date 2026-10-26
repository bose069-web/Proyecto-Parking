<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

Auth::checkAdmin();
$idAdmin = (int) Auth::idAdmin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Auth::requireRole(['SUPERADMIN', 'ADMIN']);
    verify_csrf();
    $accion = (string) ($_POST['accion'] ?? '');

    try {
        if ($accion === 'crear') {
            $nuevo = Parking::create($_POST);
            Audit::log($idAdmin, 'PARKING', $nuevo, 'CREAR', null, [
                'nombre' => $_POST['nombre'] ?? null,
                'capacidad_total' => $_POST['capacidad_total'] ?? null,
            ]);
            flash('success', 'Parking creado.');
            redirect('/admin/parkings.php');
        }

        if ($accion === 'actualizar') {
            $id = (int) ($_POST['id_parking'] ?? 0);
            $antes = Parking::find($id);
            Parking::update($id, $_POST);
            Audit::log($idAdmin, 'PARKING', $id, 'MODIFICAR', $antes, [
                'nombre' => $_POST['nombre'] ?? null,
                'capacidad_total' => $_POST['capacidad_total'] ?? null,
                'activo' => isset($_POST['activo']),
            ]);
            flash('success', 'Parking actualizado.');
            redirect('/admin/parkings.php?edit=' . $id);
        }

        if ($accion === 'estado_plaza') {
            $idPlaza = (int) ($_POST['id_plaza'] ?? 0);
            $estado  = (string) ($_POST['estado'] ?? '');
            Parking::cambiarEstadoPlaza($idPlaza, $estado);
            Audit::log($idAdmin, 'PLAZA', $idPlaza, 'MODIFICAR', null, ['estado' => $estado]);
            flash('success', $estado === 'FUERA_SERVICIO'
                ? 'Plaza fuera de servicio: deja de ofrecerse.'
                : 'Plaza disponible otra vez.');
            redirect('/admin/parkings.php?edit=' . (int) ($_POST['volver'] ?? 0));
        }

        if ($accion === 'tarifa') {
            $idTarifa = Parking::crearTarifa($_POST);
            Audit::log($idAdmin, 'TARIFA', $idTarifa, 'CREAR', null, [
                'id_parking' => (int) ($_POST['id_parking'] ?? 0),
                'precio_hora' => $_POST['precio_hora'] ?? null,
            ]);
            flash('success', 'Tarifa añadida. Se aplicará a las reservas nuevas.');
            redirect('/admin/parkings.php?edit=' . (int) ($_POST['id_parking'] ?? 0));
        }
    } catch (DomainException $e) {
        flash('danger', $e->getMessage());
        redirect('/admin/parkings.php');
    }
}

$editando = isset($_GET['edit']) ? Parking::find((int) $_GET['edit']) : null;
$parkings = Parking::all();

render_header('Parkings', 'parkings', true);
?>

<div class="page-head">
    <div>
        <p class="eyebrow">Panel de gestión</p>
        <h1>Parkings</h1>
        <p>Datos, plazas y tarifas. Al crear un parking se generan sus plazas y su tarifa general.</p>
    </div>
</div>

<div class="grid grid-2" style="align-items:start;">
    <section class="card">
        <div class="card-head">
            <h2><?= $editando ? 'Editar parking' : 'Nuevo parking' ?></h2>
            <?php if ($editando): ?>
                <a class="btn btn-ghost btn-small" href="/admin/parkings.php">Cancelar</a>
            <?php endif; ?>
        </div>

        <form method="post" action="/admin/parkings.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="<?= $editando ? 'actualizar' : 'crear' ?>">
            <?php if ($editando): ?>
                <input type="hidden" name="id_parking" value="<?= (int) $editando['id_parking'] ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="field field-full">
                    <label for="nombre">Nombre</label>
                    <input type="text" id="nombre" name="nombre" required maxlength="80"
                           value="<?= e($editando['nombre'] ?? '') ?>">
                </div>
                <div class="field field-full">
                    <label for="direccion">Dirección</label>
                    <input type="text" id="direccion" name="direccion" required maxlength="150"
                           value="<?= e($editando['direccion'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="ciudad">Ciudad</label>
                    <input type="text" id="ciudad" name="ciudad" required maxlength="50"
                           value="<?= e($editando['ciudad'] ?? 'Tarragona') ?>">
                </div>
                <div class="field">
                    <label for="capacidad_total">Capacidad total</label>
                    <input type="number" id="capacidad_total" name="capacidad_total" min="1" required
                           value="<?= e($editando['capacidad_total'] ?? 12) ?>">
                    <span class="hint">Al ampliarla se añaden plazas; nunca se borran.</span>
                </div>
                <?php if (!$editando): ?>
                    <div class="field">
                        <label for="precio_hora">Precio por hora (€)</label>
                        <input type="number" id="precio_hora" name="precio_hora" min="0" max="100"
                               step="0.05" value="<?= e($_POST['precio_hora'] ?? '1.50') ?>" required>
                        <span class="hint">Tarifa general que se aplica al parking.</span>
                    </div>
                <?php endif; ?>
                <div class="field">
                    <label for="horario">Horario</label>
                    <input type="text" id="horario" name="horario" maxlength="80"
                           value="<?= e($editando['horario'] ?? '24 h') ?>">
                </div>
                <div class="field">
                    <label for="latitud">Latitud</label>
                    <input type="text" id="latitud" name="latitud" inputmode="decimal"
                           value="<?= e($editando['latitud'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="longitud">Longitud</label>
                    <input type="text" id="longitud" name="longitud" inputmode="decimal"
                           value="<?= e($editando['longitud'] ?? '') ?>">
                </div>
                <div class="field field-full">
                    <label for="descripcion">Descripción</label>
                    <textarea id="descripcion" name="descripcion" rows="2" maxlength="255"><?= e($editando['descripcion'] ?? '') ?></textarea>
                </div>
                <div class="field field-full">
                    <label class="check">
                        <input type="checkbox" name="activo" value="1" <?= (int) ($editando['activo'] ?? 1) === 1 ? 'checked' : '' ?>>
                        Parking activo (visible para los clientes)
                    </label>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editando ? 'Guardar cambios' : 'Crear parking' ?></button>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="card-head"><h2>Parkings (<?= count($parkings) ?>)</h2></div>
        <div class="table-wrap">
            <table class="data compacta">
                <caption><span class="sr-only">Parkings</span></caption>
                <thead><tr><th scope="col">Nombre</th><th class="num" scope="col">Plazas</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($parkings as $p): ?>
                    <?php $oc = Parking::ocupacion((int) $p['id_parking']); ?>
                    <tr>
                        <td>
                            <strong><?= e($p['nombre']) ?></strong><br>
                            <span class="hint"><?= e($p['direccion']) ?></span>
                        </td>
                        <td class="num">
                            <?= $oc['total'] ?>
                            <br><span class="hint"><?= $oc['porcentaje'] ?> % ocupadas</span>
                        </td>
                        <td>
                            <span class="badge badge-<?= (int) $p['activo'] === 1 ? 'LIBRE' : 'FUERA_SERVICIO' ?>">
                                <?= (int) $p['activo'] === 1 ? 'Activo' : 'Inactivo' ?>
                            </span>
                        </td>
                        <td class="num">
                            <a class="btn btn-ghost btn-small" href="/admin/parkings.php?edit=<?= (int) $p['id_parking'] ?>">Editar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($editando): ?>
            <?php
            $idEdit  = (int) $editando['id_parking'];
            $plazas  = Parking::plazasDeParking($idEdit);
            $tarifas = Parking::tarifas($idEdit);
            ?>

            <h3 style="margin-top:1.4rem;">Plazas de <?= e($editando['nombre']) ?> (<?= count($plazas) ?>)</h3>
            <p class="hint" style="margin-bottom:.6rem;">
                Una plaza fuera de servicio deja de ofrecerse, pero conserva su historial.
            </p>
            <div class="table-wrap">
                <table class="data compacta">
                    <caption><span class="sr-only">Plazas del parking</span></caption>
                    <thead>
                        <tr>
                            <th scope="col">Código</th><th scope="col">Planta</th>
                            <th scope="col">Tipo</th><th scope="col">Estado</th><th scope="col">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($plazas as $pl): ?>
                        <tr>
                            <td class="mono"><?= e($pl['codigo_plaza']) ?></td>
                            <td><?= (int) $pl['planta'] ?></td>
                            <td><?= e($pl['tipo_plaza']) ?></td>
                            <td><span class="badge badge-<?= e($pl['estado']) ?>"><?= e($pl['estado']) ?></span></td>
                            <td>
                                <?php if ($pl['estado'] === 'OCUPADA'): ?>
                                    <span class="hint">En uso</span>
                                <?php else: ?>
                                    <form method="post" action="/admin/parkings.php" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="accion" value="estado_plaza">
                                        <input type="hidden" name="id_plaza" value="<?= (int) $pl['id_plaza'] ?>">
                                        <input type="hidden" name="volver" value="<?= $idEdit ?>">
                                        <?php if ($pl['estado'] === 'FUERA_SERVICIO'): ?>
                                            <input type="hidden" name="estado" value="LIBRE">
                                            <button class="btn btn-ghost btn-small" type="submit">Poner en servicio</button>
                                        <?php else: ?>
                                            <input type="hidden" name="estado" value="FUERA_SERVICIO">
                                            <button class="btn btn-ghost btn-small" type="submit">Retirar</button>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h3 style="margin-top:1.4rem;">Tarifas (<?= count($tarifas) ?>)</h3>
            <p class="hint" style="margin-bottom:.6rem;">
                Una tarifa no se edita: se añade otra con fecha posterior. El precio que ya
                se cobró no cambia nunca, y las reservas nuevas usan la más reciente.
            </p>
            <div class="table-wrap">
                <table class="data compacta">
                    <caption><span class="sr-only">Tarifas del parking</span></caption>
                    <thead>
                        <tr>
                            <th scope="col">Tipo</th><th class="num" scope="col">€/hora</th>
                            <th class="num" scope="col">Gestión</th><th scope="col">Vigencia</th><th scope="col">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tarifas as $t): ?>
                        <?php
                        $vigente = Parking::tarifaVigente($idEdit, (string) $t['tipo_plaza'], date('Y-m-d'));
                        // tarifaVigente() devuelve null si no hay ninguna aplicable.
                        $esVigente = $vigente !== null && (int) $vigente['id_tarifa'] === (int) $t['id_tarifa'];
                        ?>
                        <tr>
                            <td><?= e($t['tipo_plaza']) ?></td>
                            <td class="num"><?= money((float) $t['precio_hora']) ?></td>
                            <td class="num"><?= rtrim(rtrim(number_format((float) $t['porcentaje_gestion'] * 100, 2, ',', '.'), '0'), ',') ?> %</td>
                            <td>
                                <?= fecha($t['fecha_inicio_vigencia'], 'd/m/Y') ?>
                                <?php if ($t['fecha_fin_vigencia']): ?>
                                    — <?= fecha($t['fecha_fin_vigencia'], 'd/m/Y') ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($esVigente): ?>
                                    <span class="badge badge-FINALIZADA">En vigor</span>
                                <?php elseif ((int) $t['activa'] === 0): ?>
                                    <span class="badge badge-CANCELADA">Inactiva</span>
                                <?php else: ?>
                                    <span class="badge badge-LIBRE">Programada</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" action="/admin/parkings.php" class="inline-form" style="margin-top:.9rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="tarifa">
                <input type="hidden" name="id_parking" value="<?= $idEdit ?>">
                <label class="sr-only" for="t-tipo">Tipo de plaza</label>
                <select id="t-tipo" name="tipo_plaza">
                    <?php foreach (['ESTANDAR','ADAPTADA','ELECTRICA','CARGA','MOTO'] as $tp): ?>
                        <option value="<?= e($tp) ?>"><?= e($tp) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="sr-only" for="t-precio">Precio por hora</label>
                <input type="number" id="t-precio" name="precio_hora" min="0" max="100" step="0.05"
                       value="1.50" required style="max-width:7rem;">
                <label class="sr-only" for="t-fecha">Inicio de vigencia</label>
                <input type="date" id="t-fecha" name="fecha_inicio_vigencia"
                       value="<?= date('Y-m-d') ?>" required>
                <button class="btn btn-primary btn-small" type="submit">Añadir tarifa</button>
            </form>
        <?php endif; ?>
    </section>
</div>

<?php render_footer(); ?>
