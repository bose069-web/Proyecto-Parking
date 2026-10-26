<?php
declare(strict_types=1);

/**
 * Autenticación de clientes y de administradores.
 * Son dos áreas separadas: una sesión de cliente nunca da acceso al panel.
 */
final class Auth
{
    public static function attemptCliente(string $email, string $password): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT id_cliente, nombre, apellidos, email, password_hash
               FROM CLIENTE
              WHERE email = :email AND estado = 'ACTIVO'
              LIMIT 1"
        );
        $stmt->execute(['email' => mb_strtolower(trim($email))]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            return false;
        }

        self::rehashIfNeeded('CLIENTE', 'id_cliente', (int) $row['id_cliente'], $password, $row['password_hash']);

        self::regenerate();
        unset($_SESSION['admin']);
        $_SESSION['cliente'] = [
            'id'       => (int) $row['id_cliente'],
            'nombre'   => $row['nombre'] . ' ' . $row['apellidos'],
            'email'    => $row['email'],
        ];

        return true;
    }

    public static function attemptAdmin(string $email, string $password): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT id_admin, nombre, email, rol, password_hash
               FROM ADMINISTRADOR
              WHERE email = :email AND activo = 1
              LIMIT 1"
        );
        $stmt->execute(['email' => mb_strtolower(trim($email))]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            return false;
        }

        self::rehashIfNeeded('ADMINISTRADOR', 'id_admin', (int) $row['id_admin'], $password, $row['password_hash']);

        self::regenerate();
        unset($_SESSION['cliente']);
        $_SESSION['admin'] = [
            'id'     => (int) $row['id_admin'],
            'nombre' => $row['nombre'],
            'email'  => $row['email'],
            'rol'    => $row['rol'],
        ];

        // La auditoría no debe impedir el acceso si la tabla falla.
        try {
            Audit::log((int) $row['id_admin'], 'ADMINISTRADOR', (int) $row['id_admin'], 'LOGIN', null, null);
        } catch (Throwable $e) {
            error_log('[tarraco] no se pudo auditar el login: ' . $e->getMessage());
        }

        return true;
    }

    public static function cliente(): ?array
    {
        return $_SESSION['cliente'] ?? null;
    }

    public static function admin(): ?array
    {
        return $_SESSION['admin'] ?? null;
    }

    public static function idCliente(): ?int
    {
        return isset($_SESSION['cliente']['id']) ? (int) $_SESSION['cliente']['id'] : null;
    }

    public static function idAdmin(): ?int
    {
        return isset($_SESSION['admin']['id']) ? (int) $_SESSION['admin']['id'] : null;
    }

    public static function checkAuth(): void
    {
        $id = self::idCliente();
        if ($id === null) {
            flash('warning', 'Inicia sesión para continuar.');
            redirect('/login.php');
        }

        $stmt = Database::connection()->prepare(
            "SELECT estado FROM CLIENTE WHERE id_cliente = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);

        if ($stmt->fetchColumn() !== 'ACTIVO') {
            unset($_SESSION['cliente']);
            flash('warning', 'Esta cuenta está desactivada. Habla con el administrador.');
            redirect('/login.php');
        }
    }

    public static function checkAdmin(): void
    {
        $id = self::idAdmin();
        if ($id === null) {
            flash('warning', 'Inicia sesión como administrador para continuar.');
            redirect('/admin/login.php');
        }

        $stmt = Database::connection()->prepare(
            'SELECT activo FROM ADMINISTRADOR WHERE id_admin = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        if ((int) $stmt->fetchColumn() !== 1) {
            unset($_SESSION['admin']);
            flash('warning', 'Esta cuenta de administración está desactivada.');
            redirect('/admin/login.php');
        }
    }

    /**
     * Exige uno de los roles indicados. Un OPERADOR consulta, pero no
     * cancela reservas ni modifica parkings ni cuentas.
     */
    public static function requireRole(array $roles): void
    {
        self::checkAdmin();
        $rol = self::admin()['rol'] ?? '';

        if (!in_array($rol, $roles, true)) {
            flash('danger', 'Tu cuenta no tiene permisos para esta acción.');
            redirect('/admin/index.php');
        }
    }

    public static function logout(): void
    {
        $adminId = self::idAdmin();
        if ($adminId !== null) {
            try {
                Audit::log($adminId, 'ADMINISTRADOR', $adminId, 'LOGOUT', null, null);
            } catch (Throwable $e) {
                error_log('[tarraco] no se pudo auditar el logout: ' . $e->getMessage());
            }
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    private static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private static function rehashIfNeeded(
        string $table,
        string $idColumn,
        int $id,
        string $password,
        string $hash
    ): void {
        if (!password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12])) {
            return;
        }

        $stmt = Database::connection()->prepare(
            "UPDATE {$table} SET password_hash = :hash WHERE {$idColumn} = :id"
        );
        $stmt->execute(['hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), 'id' => $id]);
    }
}
