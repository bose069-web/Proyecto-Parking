<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

if (Auth::admin() !== null) {
    redirect('/admin/index.php');
}

$email = '';
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();

    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Escribe el correo y la contraseña.';
    } elseif (Auth::attemptAdmin($email, $password)) {
        flash('success', 'Sesión de administración iniciada.');
        redirect('/admin/index.php');
    } else {
        $error = 'Las credenciales de administración no son correctas.';
    }
}

render_header('Acceso de administración', '', true);
?>

<div class="card" style="max-width: 460px; margin: 0 auto;">
    <p class="eyebrow">Panel de gestión</p>
    <h1>Administración</h1>
    <p class="parking-meta">Área separada de las cuentas de cliente.</p>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/admin/login.php" novalidate>
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field field-full">
                <label for="email">Correo</label>
                <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username">
            </div>
            <div class="field field-full">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary btn-block" type="submit">Entrar</button>
        </div>
    </form>

    <div class="note" style="margin-top:1rem;">
        <strong>Credenciales de prueba.</strong><br>
        <span class="mono">admin@tarraco.local</span> / <span class="mono">Admin1234</span><br>
        <span class="mono">operador@tarraco.local</span> / <span class="mono">Oper1234</span>
    </div>

    <p class="parking-meta" style="margin-top:1rem;">
        <a href="/index.php">Volver al sitio público</a>
    </p>
</div>

<?php render_footer(); ?>
