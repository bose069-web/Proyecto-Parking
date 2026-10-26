<?php
declare(strict_types=1);

/**
 * Cuentas de cliente: alta, consulta y mantenimiento del perfil.
 */
final class User
{
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM CLIENTE WHERE id_cliente = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM CLIENTE WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => mb_strtolower(trim($email))]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<string> */
    public static function validarRegistro(array $data): array
    {
        $errors = [];

        if (mb_strlen(trim($data['nombre'] ?? '')) < 2) {
            $errors['nombre'] = 'El nombre es obligatorio.';
        } elseif (mb_strlen(trim($data['nombre'])) > 50) {
            $errors['nombre'] = 'El nombre no puede superar los 50 caracteres.';
        }
        if (mb_strlen(trim($data['apellidos'] ?? '')) < 2) {
            $errors['apellidos'] = 'Los apellidos son obligatorios.';
        } elseif (mb_strlen(trim($data['apellidos'])) > 100) {
            $errors['apellidos'] = 'Los apellidos no pueden superar los 100 caracteres.';
        }

        $email = mb_strtolower(trim($data['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'El correo electrónico no es válido.';
        } elseif (mb_strlen($email) > 120) {
            $errors['email'] = 'El correo no puede superar los 120 caracteres.';
        } elseif (self::findByEmail($email) !== null) {
            $errors['email'] = 'Ya hay una cuenta con este correo electrónico.';
        }

        $documento = strtoupper(trim($data['documento_ident'] ?? ''));
        if (!preg_match('/^([0-9]{8}|[XYZ][0-9]{7})[A-Z]$/', $documento)) {
            $errors['documento_ident'] = 'El documento debe ser un DNI o NIE válido (por ejemplo, 12345678Z).';
        } else {
            $stmt = Database::connection()->prepare(
                'SELECT 1 FROM CLIENTE WHERE documento_ident = :doc LIMIT 1'
            );
            $stmt->execute(['doc' => $documento]);
            if ($stmt->fetchColumn()) {
                $errors['documento_ident'] = 'Ya hay una cuenta con este documento.';
            }
        }

        $telefono = trim($data['telefono'] ?? '');
        if ($telefono !== '' && !preg_match('/^[0-9 +().-]{6,20}$/', $telefono)) {
            $errors['telefono'] = 'El teléfono no tiene un formato válido.';
        }

        $password = (string) ($data['password'] ?? '');
        if (mb_strlen($password) < 8) {
            $errors['password'] = 'La contraseña debe tener al menos 8 caracteres.';
        } elseif (strlen($password) > 72) {
            $errors['password'] = 'La contraseña no puede superar los 72 caracteres.';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors['password'] = 'La contraseña debe incluir letras y números.';
        } elseif ($password !== ($data['password2'] ?? '')) {
            $errors['password2'] = 'Las dos contraseñas no coinciden.';
        }

        return $errors;
    }

    /** Da de alta un cliente y devuelve su identificador. */
    public static function register(array $data): int
    {
        $errors = self::validarRegistro($data);
        if ($errors !== []) {
            throw new DomainException(implode(' ', $errors));
        }

        // La cuenta y su método de pago se crean juntos o no se crea ninguno:
        // sin método no se puede reservar, y dejar una cuenta a medias obliga
        // al cliente a llamar para poder usar la aplicación.
        return Database::transaction(function (PDO $pdo) use ($data): int {
            $stmt = $pdo->prepare(
                'INSERT INTO CLIENTE
                    (nombre, apellidos, email, telefono, documento_ident, password_hash, fecha_registro, estado)
                 VALUES
                    (:nombre, :apellidos, :email, :telefono, :documento, :hash, NOW(), :estado)'
            );

            $stmt->execute([
                'nombre'    => trim($data['nombre']),
                'apellidos' => trim($data['apellidos']),
                'email'     => mb_strtolower(trim($data['email'])),
                'telefono'  => trim($data['telefono'] ?? '') ?: null,
                'documento' => strtoupper(trim($data['documento_ident'])),
                'hash'      => password_hash((string) $data['password'], PASSWORD_BCRYPT, ['cost' => 12]),
                'estado'    => 'ACTIVO',
            ]);

            $idCliente = (int) $pdo->lastInsertId();

            // Pago simulado: no se guarda ningún número de tarjeta, solo una
            // referencia. El cliente puede cambiarlo cuando haya pasarela real.
            $stmt = $pdo->prepare(
                'INSERT INTO METODO_PAGO (id_cliente, tipo, descripcion, token, por_defecto, activo)
                 VALUES (:cliente, \'TARJETA\', :descripcion, :token, 1, 1)'
            );
            $stmt->execute([
                'cliente'     => $idCliente,
                'descripcion' => 'Tarjeta de prueba',
                'token'       => 'sim_' . bin2hex(random_bytes(12)),
            ]);

            return $idCliente;
        });
    }

    /** @return list<string> */
    public static function validarPerfil(int $id, array $data): array
    {
        $errors = [];

        if (mb_strlen(trim($data['nombre'] ?? '')) < 2) {
            $errors['nombre'] = 'El nombre es obligatorio.';
        } elseif (mb_strlen(trim($data['nombre'])) > 50) {
            $errors['nombre'] = 'El nombre no puede superar los 50 caracteres.';
        }
        if (mb_strlen(trim($data['apellidos'] ?? '')) < 2) {
            $errors['apellidos'] = 'Los apellidos son obligatorios.';
        } elseif (mb_strlen(trim($data['apellidos'])) > 100) {
            $errors['apellidos'] = 'Los apellidos no pueden superar los 100 caracteres.';
        }

        $email = mb_strtolower(trim($data['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'El correo electrónico no es válido.';
        } elseif (mb_strlen($email) > 120) {
            $errors['email'] = 'El correo no puede superar los 120 caracteres.';
        } else {
            $stmt = Database::connection()->prepare(
                'SELECT 1 FROM CLIENTE WHERE email = :email AND id_cliente <> :id LIMIT 1'
            );
            $stmt->execute(['email' => $email, 'id' => $id]);
            if ($stmt->fetchColumn()) {
                $errors['email'] = 'Ya hay otra cuenta con este correo electrónico.';
            }
        }

        $telefono = trim($data['telefono'] ?? '');
        if ($telefono !== '' && !preg_match('/^[0-9 +().-]{6,20}$/', $telefono)) {
            $errors['telefono'] = 'El teléfono no tiene un formato válido.';
        }

        return $errors;
    }

    public static function updateProfile(int $id, array $data): void
    {
        $errors = self::validarPerfil($id, $data);
        if ($errors !== []) {
            throw new DomainException(implode(' ', $errors));
        }

        $stmt = Database::connection()->prepare(
            'UPDATE CLIENTE
                SET nombre = :nombre, apellidos = :apellidos, email = :email, telefono = :telefono
              WHERE id_cliente = :id'
        );
        $stmt->execute([
            'nombre'    => trim($data['nombre']),
            'apellidos' => trim($data['apellidos']),
            'email'     => mb_strtolower(trim($data['email'])),
            'telefono'  => trim($data['telefono'] ?? '') ?: null,
            'id'        => $id,
        ]);
    }

    public static function changePassword(int $id, string $actual, string $nueva): bool
    {
        $user = self::find($id);
        if ($user === null || !password_verify($actual, $user['password_hash'])) {
            return false;
        }
        if (mb_strlen($nueva) < 8 || !preg_match('/[A-Za-z]/', $nueva) || !preg_match('/[0-9]/', $nueva)) {
            throw new DomainException('La contraseña nueva debe tener al menos 8 caracteres e incluir letras y números.');
        }
        if (strlen($nueva) > 72) {
            throw new DomainException('La contraseña nueva no puede superar los 72 caracteres.');
        }

        $stmt = Database::connection()->prepare(
            'UPDATE CLIENTE SET password_hash = :hash WHERE id_cliente = :id'
        );
        $stmt->execute([
            'hash' => password_hash($nueva, PASSWORD_BCRYPT, ['cost' => 12]),
            'id'   => $id,
        ]);

        return true;
    }
}
