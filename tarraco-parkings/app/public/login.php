<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if (Auth::cliente() !== null) {
    redirect('/dashboard.php');
}

$email = '';
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();

    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Escribe tu correo y tu contraseña.';
    } elseif (Auth::attemptCliente($email, $password)) {
        flash('success', 'Bienvenido de nuevo.');
        redirect('/dashboard.php');
    } else {
        $error = 'El correo o la contraseña no son correctos.';
    }
}

render_header('Entrar', 'login');
?>

<div class="grid grid-2" style="align-items:start; max-width: 880px; margin: 0 auto;">
    <section class="card">
        <p class="eyebrow">Área de cliente</p>
        <h1>Entrar</h1>
        <p class="parking-meta">Accede para reservar una plaza y gestionar tus vehículos.</p>

        <?php if ($error !== null): ?>
            <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/login.php" novalidate>
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="field field-full">
                    <label for="email">Correo electrónico</label>
                    <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="email">
                </div>
                <div class="field field-full">
                    <label for="password">Contraseña</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Entrar</button>
                <a class="btn btn-ghost" href="/register.php">Crear una cuenta</a>
            </div>
        </form>
    </section>

    <aside class="card">
        <h2>Cuentas de prueba</h2>
        <p class="parking-meta">
            Los datos de ejemplo del Sprint 2 incluyen cinco clientes. Puedes entrar con
            cualquiera de ellos:
        </p>
        <dl class="dl">
            <div><dt>Correo</dt><dd class="mono">pere.brau@example.com</dd></div>
            <div><dt>Contraseña</dt><dd class="mono">Client1234</dd></div>
        </dl>
        <div class="note" style="margin-top: 1rem;">
            Estas credenciales son de laboratorio. La aplicación no guarda ninguna
            contraseña en claro: solo el hash bcrypt.
        </div>
        <p class="parking-meta" style="margin-top: 1rem;">
            ¿Eres administrador? <a href="/admin/login.php">Entra por el panel de gestión</a>.
        </p>
    </aside>
</div>

<?php render_footer(); ?>
