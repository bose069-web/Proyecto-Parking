<?php
/**
 * Navegación. Dos menús distintos: cliente y administración.
 */
$clienteActual = $clienteActual ?? Auth::cliente();
$adminActual   = $adminActual ?? Auth::admin();
$esAdmin       = $esAdmin ?? false;
$activeNav     = $activeNav ?? '';
?>
<nav class="site-nav" aria-label="Navegación principal">
<?php if ($esAdmin): ?>
    <?php if ($adminActual): ?>
        <a href="/admin/index.php"<?= nav_active('admin', $activeNav) ?>>Panel</a>
        <a href="/admin/parkings.php"<?= nav_active('parkings', $activeNav) ?>>Parkings</a>
        <a href="/admin/reservations.php"<?= nav_active('reservas', $activeNav) ?>>Reservas</a>
        <a href="/admin/users.php"<?= nav_active('usuarios', $activeNav) ?>>Clientes</a>
        <a href="/admin/audit.php"<?= nav_active('auditoria', $activeNav) ?>>Auditoría</a>
        <a href="/access-control.php"<?= nav_active('acceso', $activeNav) ?>>Control de acceso</a>
    <?php endif; ?>
    <a href="/index.php" class="nav-secondary">Ver el sitio</a>
    <?php if ($adminActual): ?>
        <span class="nav-user"><?= e($adminActual['nombre']) ?></span>
        <form method="post" action="/logout.php" class="inline-form">
            <?= csrf_field() ?>
            <button type="submit" class="link-button">Salir</button>
        </form>
    <?php endif; ?>
<?php else: ?>
    <a href="/index.php"<?= nav_active('inicio', $activeNav) ?>>Inicio</a>

    <?php if ($clienteActual): ?>
        <a href="/access-control.php"<?= nav_active('acceso', $activeNav) ?>>Control de acceso</a>
        <a href="/dashboard.php"<?= nav_active('panel', $activeNav) ?>>Mi panel</a>
        <a href="/my-reservations.php"<?= nav_active('reservas', $activeNav) ?>>Mis reservas</a>
        <a href="/vehicles.php"<?= nav_active('vehiculos', $activeNav) ?>>Vehículos</a>
        <a href="/profile.php"<?= nav_active('perfil', $activeNav) ?>>Perfil</a>
        <span class="nav-user"><?= e($clienteActual['nombre']) ?></span>
        <form method="post" action="/logout.php" class="inline-form">
            <?= csrf_field() ?>
            <button type="submit" class="link-button">Salir</button>
        </form>
    <?php else: ?>
        <a href="/login.php"<?= nav_active('login', $activeNav) ?>>Entrar</a>
        <a href="/register.php" class="btn btn-primary btn-small">Crear cuenta</a>
    <?php endif; ?>

    <?php if ($adminActual): ?>
        <a href="/admin/index.php" class="nav-admin">Panel admin</a>
    <?php endif; ?>
<?php endif; ?>
</nav>
