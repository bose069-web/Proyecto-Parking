<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

// El simulador escribe en RESERVA, PLAZA y PAGO: exige sesión. Un cliente
// puede usarlo para sus propios movimientos; el administrador, para cualquiera.
if (Auth::cliente() === null && Auth::admin() === null) {
    flash('warning', 'Inicia sesión para usar el control de acceso.');
    redirect('/login.php');
}

$parkings  = Parking::allActive();
$resultado = null;
$error     = null;
$matricula = '';
$idParking = (int) ($_POST['id_parking'] ?? $_GET['parking'] ?? ($parkings[0]['id_parking'] ?? 0));
$movimiento = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();

    $matricula  = trim((string) ($_POST['matricula'] ?? ''));
    $movimiento = (string) ($_POST['movimiento'] ?? '');

    // El administrador puede mover cualquier matrícula; un cliente, solo las suyas.
    $scope = Auth::admin() !== null ? null : Auth::idCliente();

    try {
        $resultado = match ($movimiento) {
            'ENTRADA' => AccessControl::entrada($idParking, $matricula, $scope),
            'SALIDA'  => AccessControl::salida($idParking, $matricula, $scope),
            default   => throw new DomainException('Selecciona entrada o salida.'),
        };
    } catch (DomainException $e) {
        $error = $e->getMessage();
    }
}

$historial = $idParking > 0 ? AccessControl::historial($idParking, 12) : [];

// Un cliente solo ve sus propias matrículas en el historial.
if (Auth::admin() === null) {
    $propias = array_column(Vehicle::listByCliente((int) Auth::idCliente()), 'matricula');
    $historial = array_values(array_filter(
        $historial,
        static fn (array $a): bool => in_array($a['matricula_leida'], $propias, true)
    ));
}

$parkingNombre = '';
foreach ($parkings as $p) {
    if ((int) $p['id_parking'] === $idParking) {
        $parkingNombre = $p['nombre'];
    }
}

render_header('Control de acceso', 'acceso');
?>

<div class="page-head">
    <div>
        <p class="eyebrow">Simulación</p>
        <h1>Control de acceso</h1>
        <p>Teclea la matrícula como lo haría el sistema al leerla. No hay barrera física: la decisión es lógica.</p>
    </div>
</div>

<div class="detail-grid">
    <section class="card">
        <div class="card-head"><h2>Movimiento</h2></div>

        <form method="post" action="/access-control.php" novalidate>
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="field">
                    <label for="id_parking">Parking</label>
                    <select id="id_parking" name="id_parking" required>
                        <?php foreach ($parkings as $p): ?>
                            <option value="<?= (int) $p['id_parking'] ?>" <?= $idParking === (int) $p['id_parking'] ? 'selected' : '' ?>>
                                <?= e($p['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="matricula">Matrícula</label>
                    <input type="text" id="matricula" name="matricula" data-plate
                           value="<?= e($matricula) ?>" required maxlength="10" placeholder="1234JKL"
                           autocomplete="off">
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit" name="movimiento" value="ENTRADA">Registrar entrada</button>
                <button class="btn btn-ghost" type="submit" name="movimiento" value="SALIDA">Registrar salida</button>
            </div>
        </form>

        <?php if ($error !== null): ?>
            <div class="alert alert-danger" role="alert" style="margin-top:1rem;"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($resultado !== null): ?>
            <div class="alert alert-<?= $resultado['ok'] ? 'success' : 'danger' ?>" style="margin-top:1rem;">
                <strong><?= $resultado['ok'] ? 'Acceso autorizado' : 'Acceso denegado' ?>.</strong>
                <?= e($resultado['mensaje']) ?>
            </div>

            <?php if ($resultado['reserva'] !== null): ?>
                <dl class="dl">
                    <div><dt>Reserva</dt><dd class="mono"><?= e($resultado['reserva']['codigo_reserva']) ?></dd></div>
                    <div><dt>Estado</dt><dd><span class="badge badge-<?= e($resultado['reserva']['estado']) ?>"><?= e($resultado['reserva']['estado']) ?></span></dd></div>
                    <div><dt>Entrada prevista</dt><dd><?= fecha($resultado['reserva']['fecha_hora_entrada_prevista']) ?></dd></div>
                    <div><dt>Salida prevista</dt><dd><?= fecha($resultado['reserva']['fecha_hora_salida_prevista']) ?></dd></div>
                </dl>
            <?php endif; ?>

            <?php if (($resultado['exceso']['importe'] ?? 0) > 0): ?>
                <div class="total-box" style="margin-top:1rem;">
                    <div class="line"><span>Exceso</span><span><?= (int) $resultado['exceso']['minutos'] ?> min</span></div>
                    <div class="line"><span>Horas cobradas</span><span><?= (int) $resultado['exceso']['horas'] ?> h</span></div>
                    <div class="grand"><span>Importe</span><span><?= money((float) $resultado['exceso']['importe']) ?></span></div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="note" style="margin-top:1.2rem;">
            <strong>Cómo se decide.</strong> La entrada exige un vehículo registrado y una
            reserva <code>ACTIVA</code> en ese parking, con la hora actual dentro de la franja
            (30 minutos de margen antes). La salida exige una reserva <code>EN_CURSO</code>;
            a partir de 15 minutos de retraso se cobra el exceso por horas completas.
        </div>
    </section>

    <aside class="card">
        <div class="card-head">
            <h2>Últimos movimientos</h2>
            <span class="hint"><?= e($parkingNombre) ?></span>
        </div>

        <?php if ($historial === []): ?>
            <div class="empty">Todavía no hay movimientos registrados en este parking.</div>
        <?php else: ?>
            <div class="table-wrap historial">
                <table class="data" style="min-width: 0;">
                    <caption><span class="sr-only">Últimos accesos del parking</span></caption>
                    <thead><tr><th scope="col">Hora</th><th scope="col">Matrícula</th><th scope="col">Movimiento</th></tr></thead>
                    <tbody>
                    <?php foreach ($historial as $a): ?>
                        <tr>
                            <td><?= fecha($a['fecha_hora'], 'd/m H:i') ?></td>
                            <td class="mono"><?= e($a['matricula_leida']) ?></td>
                            <td>
                                <?= $a['tipo_movimiento'] === 'ENTRADA' ? 'Entrada' : 'Salida' ?>
                                <span class="badge badge-<?= (int) $a['autorizado'] === 1 ? 'si' : 'no' ?>">
                                    <?= (int) $a['autorizado'] === 1 ? 'Autorizado' : 'Denegado' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </aside>
</div>

<?php render_footer(); ?>
