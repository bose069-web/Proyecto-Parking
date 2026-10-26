<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if (Auth::cliente() !== null) {
    redirect('/dashboard.php');
}

$data   = [];
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();

    $data = [
        'nombre'          => trim((string) ($_POST['nombre'] ?? '')),
        'apellidos'       => trim((string) ($_POST['apellidos'] ?? '')),
        'email'           => trim((string) ($_POST['email'] ?? '')),
        'telefono'        => trim((string) ($_POST['telefono'] ?? '')),
        'documento_ident' => trim((string) ($_POST['documento_ident'] ?? '')),
        'password'        => (string) ($_POST['password'] ?? ''),
        'password2'       => (string) ($_POST['password2'] ?? ''),
    ];

    $errors = User::validarRegistro($data);

    if ($errors === []) {
        try {
            User::register($data);
            flash('success', 'Cuenta creada. Ya puedes iniciar sesión.');
            redirect('/login.php');
        } catch (DomainException $e) {
            $errors['general'] = $e->getMessage();
        }
    }
}

render_header('Crear cuenta', 'login');
?>

<div class="card" style="max-width: 760px; margin: 0 auto;">
    <p class="eyebrow">Área de cliente</p>
    <h1>Crear cuenta</h1>
    <p class="parking-meta">Solo necesitas un correo, tu documento de identidad y una contraseña.</p>

    <?php if (isset($errors['general'])): ?>
        <div class="alert alert-danger" role="alert"><?= e($errors['general']) ?></div>
    <?php endif; ?>

    <form method="post" action="/register.php" novalidate>
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field">
                <label for="nombre">Nombre</label>
                <input type="text" id="nombre" name="nombre" value="<?= e($data['nombre'] ?? '') ?>"
                       required autocomplete="given-name" aria-invalid="<?= isset($errors['nombre']) ? 'true' : 'false' ?>"
                       <?= isset($errors['nombre']) ? 'aria-describedby="error-nombre"' : '' ?>>
                <?php if (isset($errors['nombre'])): ?><span class="field-error" id="error-nombre"><?= e($errors['nombre']) ?></span><?php endif; ?>
            </div>

            <div class="field">
                <label for="apellidos">Apellidos</label>
                <input type="text" id="apellidos" name="apellidos" value="<?= e($data['apellidos'] ?? '') ?>"
                       required autocomplete="family-name" aria-invalid="<?= isset($errors['apellidos']) ? 'true' : 'false' ?>"
                       <?= isset($errors['apellidos']) ? 'aria-describedby="error-apellidos"' : '' ?>>
                <?php if (isset($errors['apellidos'])): ?><span class="field-error" id="error-apellidos"><?= e($errors['apellidos']) ?></span><?php endif; ?>
            </div>

            <div class="field">
                <label for="email">Correo electrónico</label>
                <input type="email" id="email" name="email" value="<?= e($data['email'] ?? '') ?>"
                       required autocomplete="email" aria-invalid="<?= isset($errors['email']) ? 'true' : 'false' ?>"
                       <?= isset($errors['email']) ? 'aria-describedby="error-email"' : '' ?>>
                <?php if (isset($errors['email'])): ?><span class="field-error" id="error-email"><?= e($errors['email']) ?></span><?php endif; ?>
            </div>

            <div class="field">
                <label for="telefono">Teléfono <span class="hint">(opcional)</span></label>
                <input type="tel" id="telefono" name="telefono" value="<?= e($data['telefono'] ?? '') ?>"
                       autocomplete="tel" aria-invalid="<?= isset($errors['telefono']) ? 'true' : 'false' ?>"
                       <?= isset($errors['telefono']) ? 'aria-describedby="error-telefono"' : '' ?>>
                <?php if (isset($errors['telefono'])): ?><span class="field-error" id="error-telefono"><?= e($errors['telefono']) ?></span><?php endif; ?>
            </div>

            <div class="field">
                <label for="documento_ident">DNI o NIE</label>
                <input type="text" id="documento_ident" name="documento_ident"
                       value="<?= e($data['documento_ident'] ?? '') ?>" required maxlength="20"
                       placeholder="12345678Z" aria-invalid="<?= isset($errors['documento_ident']) ? 'true' : 'false' ?>"
                       <?= isset($errors['documento_ident']) ? 'aria-describedby="error-documento_ident"' : '' ?>>
                <?php if (isset($errors['documento_ident'])): ?><span class="field-error" id="error-documento_ident"><?= e($errors['documento_ident']) ?></span><?php endif; ?>
            </div>

            <div class="field">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" required minlength="8"
                       autocomplete="new-password" aria-invalid="<?= isset($errors['password']) ? 'true' : 'false' ?>"
                       <?= isset($errors['password']) ? 'aria-describedby="error-password"' : '' ?>>
                <span class="hint">Mínimo 8 caracteres, con letras y números.</span>
                <?php if (isset($errors['password'])): ?><span class="field-error" id="error-password"><?= e($errors['password']) ?></span><?php endif; ?>
            </div>

            <div class="field">
                <label for="password2">Repite la contraseña</label>
                <input type="password" id="password2" name="password2" required minlength="8"
                       autocomplete="new-password" aria-invalid="<?= isset($errors['password2']) ? 'true' : 'false' ?>"
                       <?= isset($errors['password2']) ? 'aria-describedby="error-password2"' : '' ?>>
                <?php if (isset($errors['password2'])): ?><span class="field-error" id="error-password2"><?= e($errors['password2']) ?></span><?php endif; ?>
            </div>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Crear cuenta</button>
            <a class="btn btn-ghost" href="/login.php">Ya tengo cuenta</a>
        </div>
    </form>
</div>

<?php render_footer(); ?>
