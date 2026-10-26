<?php
declare(strict_types=1);

/**
 * Punto de entrada común: autoload, configuración, sesión y utilidades.
 * Todas las páginas de public/ empiezan con:
 *     require_once __DIR__ . '/../src/bootstrap.php';
 */

define('APP_ROOT', dirname(__DIR__));

/** @var array<string, mixed> $GLOBALS['app_config'] */
$GLOBALS['app_config'] = require APP_ROOT . '/config/config.php';

date_default_timezone_set(app_config('app.timezone'));

// Autoload sencillo: una clase por archivo en src/.
spl_autoload_register(static function (string $class): void {
    $file = APP_ROOT . '/src/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

if (PHP_SAPI !== 'cli' && ($_SERVER['SCRIPT_NAME'] ?? '') !== '/metrics.php' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name(app_config('session.name'));
    session_set_cookie_params([
        'lifetime' => app_config('session.lifetime'),
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Error controlado: el detalle va al log, no a la pantalla del usuario.
if (PHP_SAPI !== 'cli') {
    set_exception_handler(static function (Throwable $e): void {
        error_log('[tarraco] ' . $e);
        if (!headers_sent()) {
            http_response_code(500);
        }
        $debug = (getenv('APP_ENV') ?: 'production') === 'development';
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<title>Error · Tarraco Parkings</title><link rel="stylesheet" href="/css/style.css">'
            . '</head><body><main class="container main"><div class="alert alert-danger">'
            . '<strong>Se ha producido un error inesperado.</strong> Vuelve a intentarlo en unos segundos.';
        if ($debug) {
            echo '<pre class="mono" style="white-space:pre-wrap;margin-top:.8rem">' . e($e->getMessage()) . '</pre>';
        }
        echo '</div></main></body></html>';
    });

    // Contador de peticiones para Prometheus. El propio endpoint no se cuenta.
    if (!str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', '/metrics.php')) {
        register_shutdown_function(static function (): void {
            $status = http_response_code();
            Metrics::record(is_int($status) ? $status : 200);
        });
    }
}

/** Lee una clave de configuración con notación de puntos: app.timezone. */
function app_config(string $path, mixed $default = null): mixed
{
    $value = $GLOBALS['app_config'] ?? [];
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return $default;
        }
        $value = $value[$key];
    }
    return $value;
}

/** Escapa texto para HTML. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redirección interna y fin de la petición. */
function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

/** Formatea un importe con coma decimal y euro. */
function money(float|string|null $value): string
{
    return number_format((float) $value, 2, ',', '.') . ' €';
}

/** Formatea una fecha de la base de datos para mostrarla. */
function fecha(?string $value, string $format = 'd/m/Y H:i'): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts === false ? '—' : date($format, $ts);
}

/** Guarda un mensaje flash para la siguiente petición. */
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** Devuelve y vacía los mensajes flash. */
function flashes(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $items;
}

/** Guarda los datos de un formulario fallido y los devuelve una vez. */
function remember_old(array $data): void
{
    $_SESSION['_old'] = $data;
}

function old(string $key, mixed $default = ''): mixed
{
    $value = $_SESSION['_old'][$key] ?? $default;
    return $value;
}

function clear_old(): void
{
    unset($_SESSION['_old']);
}

/** Token CSRF de la sesión. */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Corta la petición si el token no es válido. */
function verify_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        // Una página de verdad, no un `exit` con texto plano: así el usuario
        // no se queda con una pantalla en blanco sin forma de volver.
        render_header('La sesión ha caducado');
        echo '<div class="card" style="max-width: 560px; margin: 2rem auto;">'
            . '<h1>La sesión ha caducado</h1>'
            . '<p class="parking-meta">El formulario llevaba demasiado tiempo abierto o se ha '
            . 'abierto en otra pestaña. No se ha guardado nada.</p>'
            . '<div class="form-actions">'
            . '<a class="btn btn-primary" href="/login.php">Volver a entrar</a>'
            . '<a class="btn btn-ghost" href="/index.php">Ir al inicio</a>'
            . '</div></div>';
        render_footer();
        exit;
    }
}

/**
 * Concordancia de número. «1 reservas» delata descuido en un aviso del sistema,
 * y en una aplicación en castellano se nota al leer.
 */
function plural(int $n, string $singular, string $plural): string
{
    return $n === 1 ? "{$n} {$singular}" : "{$n} {$plural}";
}

/**
 * Nombre legible de un estado de reserva. En la base de datos va en mayúsculas
 * porque es un ENUM; en pantalla se lee en castellano.
 */
function etiqueta_estado(string $estado): string
{
    return [
        'PENDIENTE_PAGO' => 'Pendiente de pago',
        'ACTIVA'         => 'Activa',
        'EN_CURSO'       => 'En curso',
        'FINALIZADA'     => 'Finalizada',
        'CON_EXCESO'     => 'Con exceso',
        'CANCELADA'      => 'Cancelada',
        'NO_PRESENTADA'  => 'No presentada',
    ][$estado] ?? $estado;
}

/** Solo se aceptan peticiones POST en los formularios que modifican datos. */
function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Método no permitido.');
    }
}

/** Cabecera común de las páginas. */
function render_header(string $title, string $active = '', bool $adminLayout = false): void
{
    $pageTitle = $title;
    $activeNav = $active;
    require APP_ROOT . '/templates/header.php';
}

function render_footer(): void
{
    require APP_ROOT . '/templates/footer.php';
}

/** Marca el ítem activo del menú. */
function nav_active(string $key, string $active): string
{
    return $key === $active ? ' aria-current="page" class="active"' : '';
}
