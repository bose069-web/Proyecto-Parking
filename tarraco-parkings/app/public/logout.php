<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_post();
verify_csrf();

$eraAdmin = Auth::admin() !== null;
Auth::logout();

redirect($eraAdmin ? '/admin/login.php' : '/index.php');
