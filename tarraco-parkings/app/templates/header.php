<?php
/**
 * Cabecera común. Espera $pageTitle, $activeNav y, opcionalmente, $adminLayout.
 */
$clienteActual = Auth::cliente();
$adminActual   = Auth::admin();
$esAdmin       = !empty($adminLayout);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · Tarraco Parkings</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body class="<?= $esAdmin ? 'layout-admin' : '' ?>">
<a class="skip-link" href="#contenido">Saltar al contenido</a>

<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="<?= $esAdmin ? '/admin/index.php' : '/index.php' ?>">
            <span class="brand-mark" aria-hidden="true">TP</span>
            <span class="brand-text">Tarraco <b>Parkings</b></span>
        </a>

        <?php require __DIR__ . '/nav.php'; ?>
    </div>
</header>

<main id="contenido" class="container main">
<?php foreach (flashes() as $mensaje): ?>
    <div class="alert alert-<?= e($mensaje['type']) ?>"
         role="<?= $mensaje['type'] === 'danger' ? 'alert' : 'status' ?>">
        <?= e($mensaje['message']) ?>
    </div>
<?php endforeach; ?>
