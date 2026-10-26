<?php
declare(strict_types=1);

/**
 * Vehículos del cliente. La matrícula es única en todo el sistema.
 */
final class Vehicle
{
    private const TIPOS = ['COCHE', 'MOTO', 'ADAPTADO', 'CARGA'];

    /** Quita espacios y guiones y pasa a mayúsculas. */
    public static function normalizarMatricula(string $matricula): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $matricula) ?? '';
        return strtoupper($clean);
    }

    public static function listByCliente(int $idCliente): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM VEHICULO
              WHERE id_cliente = :id AND activo = 1
              ORDER BY id_vehiculo'
        );
        $stmt->execute(['id' => $idCliente]);

        return $stmt->fetchAll();
    }

    public static function find(int $id, int $idCliente): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM VEHICULO
              WHERE id_vehiculo = :id AND id_cliente = :cliente AND activo = 1
              LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'cliente' => $idCliente]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function findByMatricula(string $matricula): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM VEHICULO WHERE matricula = :matricula AND activo = 1 LIMIT 1'
        );
        $stmt->execute(['matricula' => self::normalizarMatricula($matricula)]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<string> */
    public static function validar(array $data): array
    {
        $errors = [];

        $matricula = self::normalizarMatricula($data['matricula'] ?? '');
        if (!preg_match('/^[A-Z0-9]{4,10}$/', $matricula)) {
            $errors['matricula'] = 'La matrícula debe tener entre 4 y 10 letras o números.';
        }

        foreach (['marca' => 50, 'modelo' => 50, 'color' => 30] as $field => $max) {
            if (mb_strlen(trim($data[$field] ?? '')) > $max) {
                $errors[$field] = 'El campo no puede superar los ' . $max . ' caracteres.';
            }
        }

        if (!in_array($data['tipo_vehiculo'] ?? 'COCHE', self::TIPOS, true)) {
            $errors['tipo_vehiculo'] = 'El tipo de vehículo no es válido.';
        }

        return $errors;
    }

    public static function create(int $idCliente, array $data): int
    {
        $errors = self::validar($data);
        if ($errors !== []) {
            throw new DomainException(implode(' ', $errors));
        }

        $matricula = self::normalizarMatricula($data['matricula']);
        $stmt = Database::connection()->prepare('SELECT 1 FROM VEHICULO WHERE matricula = :m LIMIT 1');
        $stmt->execute(['m' => $matricula]);
        if ($stmt->fetchColumn()) {
            throw new DomainException('La matrícula ' . $matricula . ' ya está registrada.');
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO VEHICULO (id_cliente, matricula, marca, modelo, color, tipo_vehiculo, activo)
             VALUES (:cliente, :matricula, :marca, :modelo, :color, :tipo, 1)'
        );
        $stmt->execute([
            'cliente'   => $idCliente,
            'matricula' => $matricula,
            'marca'     => trim($data['marca'] ?? '') ?: null,
            'modelo'    => trim($data['modelo'] ?? '') ?: null,
            'color'     => trim($data['color'] ?? '') ?: null,
            'tipo'      => $data['tipo_vehiculo'] ?? 'COCHE',
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    public static function update(int $id, int $idCliente, array $data): void
    {
        if (self::find($id, $idCliente) === null) {
            throw new DomainException('El vehículo no existe o no es tuyo.');
        }

        $errors = self::validar($data);
        if ($errors !== []) {
            throw new DomainException(implode(' ', $errors));
        }

        $matricula = self::normalizarMatricula($data['matricula']);
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM VEHICULO WHERE matricula = :m AND id_vehiculo <> :id LIMIT 1'
        );
        $stmt->execute(['m' => $matricula, 'id' => $id]);
        if ($stmt->fetchColumn()) {
            throw new DomainException('La matrícula ' . $matricula . ' ya está registrada.');
        }

        $stmt = Database::connection()->prepare(
            'UPDATE VEHICULO
                SET matricula = :matricula, marca = :marca, modelo = :modelo, color = :color, tipo_vehiculo = :tipo
              WHERE id_vehiculo = :id AND id_cliente = :cliente'
        );
        $stmt->execute([
            'matricula' => $matricula,
            'marca'     => trim($data['marca'] ?? '') ?: null,
            'modelo'    => trim($data['modelo'] ?? '') ?: null,
            'color'     => trim($data['color'] ?? '') ?: null,
            'tipo'      => $data['tipo_vehiculo'] ?? 'COCHE',
            'id'        => $id,
            'cliente'   => $idCliente,
        ]);
    }

    /** Baja lógica: no se borra la fila porque las reservas la referencian. */
    public static function deactivate(int $id, int $idCliente): void
    {
        if (self::find($id, $idCliente) === null) {
            throw new DomainException('El vehículo no existe o no es tuyo.');
        }

        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM RESERVA
              WHERE id_vehiculo = :id AND estado IN ('PENDIENTE_PAGO','ACTIVA','EN_CURSO')"
        );
        $stmt->execute(['id' => $id]);

        if ((int) $stmt->fetchColumn() > 0) {
            throw new DomainException(
                'No se puede dar de baja un vehículo con reservas pendientes o en curso.'
            );
        }

        $stmt = Database::connection()->prepare(
            'UPDATE VEHICULO SET activo = 0 WHERE id_vehiculo = :id AND id_cliente = :cliente'
        );
        $stmt->execute(['id' => $id, 'cliente' => $idCliente]);
    }
}
