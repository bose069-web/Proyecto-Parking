<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

Auth::checkAuth();
$idCliente = (int) Auth::idCliente();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $accion = (string) ($_POST['accion'] ?? '');

    try {
        if ($accion === 'perfil') {
            User::updateProfile($idCliente, [
                'nombre'    => (string) ($_POST['nombre'] ?? ''),
                'apellidos' => (string) ($_POST['apellidos'] ?? ''),
                'email'     => (string) ($_POST['email'] ?? ''),
                'telefono'  => (string) ($_POST['telefono'] ?? ''),
            ]);

            // El menú lee el nombre de la sesión: si no se refresca, sigue
            // mostrando el anterior hasta el siguiente inicio de sesión.
            $_SESSION['cliente']['nombre']  = trim((string) $_POST['nombre']) . ' ' . trim((string) $_POST['apellidos']);
            $_SESSION['cliente']['email']   = mb_strtolower(trim((string) $_POST['email']));

            flash('success', 'Perfil actualizado.');
            redirect('/profile.php');
        }

        if ($accion === 'password') {
            $ok = User::changePassword(
                $idCliente,
                (string) ($_POST['actual'] ?? ''),
                (string) ($_POST['nueva'] ?? '')
            );
            if (!$ok) {
                flash('danger', 'La contraseña actual no es correcta.');
            } else {
                flash('success', 'Contraseña cambiada.');
            }
            redirect('/profile.php');
        }
    } catch (DomainException $e) {
        remember_old($_POST);
        flash('danger', $e->getMessage());
        redirect('/profile.php');
    }
}

$cliente = User::find($idCliente);
// El método lo crea el alta de la cuenta. El cliente tiene que poder verlo:
// hasta ahora se cobraba con él y no aparecía en ninguna pantalla.
$metodos = Payment::metodosPorCliente($idCliente);

render_header('Mi perfil', 'perfil');
?>

<div class="page-head">
    <div>
        <p class="eyebrow">Área de cliente</p>
        <h1>Mi perfil</h1>
        <p>Datos de la cuenta y seguridad del acceso.</p>
    </div>
</div>

<div class="grid grid-2" style="align-items:start;">
    <section class="card">
        <div class="card-head"><h2>Datos personales</h2></div>

        <form method="post" action="/profile.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="perfil">
            <div class="form-grid">
                <div class="field">
                    <label for="nombre">Nombre</label>
                    <input type="text" id="nombre" name="nombre" value="<?= e(old('nombre', $cliente['nombre'])) ?>" required>
                </div>
                <div class="field">
                    <label for="apellidos">Apellidos</label>
                    <input type="text" id="apellidos" name="apellidos" value="<?= e(old('apellidos', $cliente['apellidos'])) ?>" required>
                </div>
                <div class="field">
                    <label for="email">Correo electrónico</label>
                    <input type="email" id="email" name="email" value="<?= e(old('email', $cliente['email'])) ?>" required>
                </div>
                <div class="field">
                    <label for="telefono">Teléfono</label>
                    <input type="tel" id="telefono" name="telefono" value="<?= e(old('telefono', $cliente['telefono'] ?? '')) ?>">
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Guardar cambios</button>
            </div>
        </form>
    </section>

    <aside>
        <section class="card">
            <div class="card-head"><h2>Datos de la cuenta</h2></div>
            <dl class="dl">
                <div><dt>Documento</dt><dd class="mono"><?= e($cliente['documento_ident']) ?></dd></div>
                <div><dt>Alta</dt><dd><?= fecha($cliente['fecha_registro']) ?></dd></div>
                <div><dt>Estado</dt><dd><span class="badge badge-<?= $cliente['estado'] === 'ACTIVO' ? 'FINALIZADA' : 'CANCELADA' ?>"><?= e($cliente['estado']) ?></span></dd></div>
            </dl>

            <div class="card-head" style="margin-top:1.2rem;"><h2>Método de pago</h2></div>
            <?php if ($metodos === []): ?>
                <p class="parking-meta">No hay ningún método activo.</p>
            <?php else: ?>
                <dl class="dl">
                    <?php foreach ($metodos as $m): ?>
                        <div>
                            <dt><?= e($m['tipo']) ?></dt>
                            <dd>
                                <?= e($m['descripcion'] ?? 'Sin descripción') ?>
                                <?php if ((int) $m['por_defecto'] === 1): ?>
                                    <span class="badge badge-FINALIZADA">Por defecto</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
                <p class="hint" style="margin-top:.6rem;">
                    El pago está simulado: solo se guarda una referencia, nunca el número de tarjeta.
                </p>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card-head"><h2>Cambiar contraseña</h2></div>
            <form method="post" action="/profile.php" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="password">
                <div class="form-grid">
                    <div class="field field-full">
                        <label for="actual">Contraseña actual</label>
                        <input type="password" id="actual" name="actual" required autocomplete="current-password">
                    </div>
                    <div class="field field-full">
                        <label for="nueva">Contraseña nueva</label>
                        <input type="password" id="nueva" name="nueva" required minlength="8" autocomplete="new-password">
                        <span class="hint">Mínimo 8 caracteres, con letras y números.</span>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn btn-ghost" type="submit">Cambiar contraseña</button>
                </div>
            </form>
        </section>
    </aside>
</div>

<?php render_footer(); ?>

<?php clear_old(); ?>
