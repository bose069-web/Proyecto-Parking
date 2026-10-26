<?php
declare(strict_types=1);

/**
 * Parkings, plazas y tarifas.
 *
 * La disponibilidad no se guarda como estado de la plaza: se calcula
 * comparando la franja pedida con las reservas que ya la ocupan. Así no
 * hay dos verdades que puedan contradecirse.
 */
final class Parking
{
    public static function allActive(): array
    {
        $stmt = Database::connection()->query(
            'SELECT * FROM PARKING WHERE activo = 1 ORDER BY nombre'
        );

        return $stmt->fetchAll();
    }

    public static function all(): array
    {
        return Database::connection()
            ->query('SELECT * FROM PARKING ORDER BY nombre')
            ->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM PARKING WHERE id_parking = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Tipos de plaza que puede usar cada tipo de vehículo. */
    public static function tiposCompatibles(string $tipoVehiculo): array
    {
        return match ($tipoVehiculo) {
            'MOTO'     => ['MOTO'],
            'ADAPTADO' => ['ADAPTADA', 'ESTANDAR'],
            'CARGA'    => ['CARGA', 'ESTANDAR'],
            default    => ['ESTANDAR', 'ADAPTADA', 'ELECTRICA'],
        };
    }

    /** Plazas libres ahora mismo, sin mirar reservas futuras. */
    public static function plazasLibres(int $idParking): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM PLAZA
              WHERE id_parking = :id AND estado = 'LIBRE'"
        );
        $stmt->execute(['id' => $idParking]);

        return (int) $stmt->fetchColumn();
    }

    public static function plazasDeParking(int $idParking): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM PLAZA WHERE id_parking = :id ORDER BY planta, codigo_plaza'
        );
        $stmt->execute(['id' => $idParking]);

        return $stmt->fetchAll();
    }

    /**
     * Tarifa aplicable para un tipo de plaza en una fecha.
     * Si no hay tarifa específica, se usa la estándar del parking.
     *
     * Las dos columnas de vigencia son DATE: se comparan contra DATE(:fecha)
     * para que el último día de vigencia siga contando a cualquier hora.
     */
    public static function tarifaVigente(int $idParking, string $tipoPlaza, string $fecha): ?array
    {
        $sql = "SELECT * FROM TARIFA
                 WHERE id_parking = :parking
                   AND tipo_plaza = :tipo
                   AND activa = 1
                   AND fecha_inicio_vigencia <= DATE(:fecha)
                   AND (fecha_fin_vigencia IS NULL OR fecha_fin_vigencia >= DATE(:fecha2))
                 ORDER BY fecha_inicio_vigencia DESC
                 LIMIT 1";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            'parking' => $idParking,
            'tipo'    => $tipoPlaza,
            'fecha'   => $fecha,
            'fecha2'  => $fecha,
        ]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }

        if ($tipoPlaza === 'ESTANDAR') {
            return null;
        }

        $stmt->execute([
            'parking' => $idParking,
            'tipo'    => 'ESTANDAR',
            'fecha'   => $fecha,
            'fecha2'  => $fecha,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Primera plaza libre en una franja, bloqueando la fila para que dos
     * peticiones simultáneas no reciban la misma. Debe llamarse dentro de
     * una transacción.
     */
    public static function plazaDisponible(
        int $idParking,
        array $tipos,
        string $desde,
        string $hasta,
        ?PDO $pdo = null
    ): ?array {
        $pdo ??= Database::connection();

        $tipos = array_values(array_unique($tipos));
        if ($tipos === []) {
            return null;
        }

        // Marcadores con nombre: el orden de los parámetros ya no puede
        // descolocarse respecto del orden del SQL.
        $marcadores = [];
        $params = ['parking' => $idParking, 'desde' => $desde, 'hasta' => $hasta];

        foreach ($tipos as $i => $tipo) {
            $clave = 'tipo' . $i;
            $marcadores[] = ':' . $clave;
            $params[$clave] = $tipo;
        }

        $in = implode(', ', $marcadores);

        $sql = "SELECT p.*
                  FROM PLAZA p
                 WHERE p.id_parking = :parking
                   AND p.tipo_plaza IN ($in)
                   AND p.estado <> 'FUERA_SERVICIO'
                   AND NOT EXISTS (
                       SELECT 1
                         FROM RESERVA r
                        WHERE r.id_plaza = p.id_plaza
                          AND r.estado IN ('PENDIENTE_PAGO','ACTIVA','EN_CURSO')
                          AND r.fecha_hora_entrada_prevista < :hasta
                          AND r.fecha_hora_salida_prevista > :desde
                          AND (
                              r.estado <> 'PENDIENTE_PAGO'
                              OR r.fecha_creacion > NOW() - INTERVAL 15 MINUTE
                          )
                   )
                 ORDER BY p.id_plaza
                 LIMIT 1
                 FOR UPDATE";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<string> */
    public static function validar(array $data): array
    {
        $errors = [];

        if (mb_strlen(trim($data['nombre'] ?? '')) < 3) {
            $errors['nombre'] = 'El nombre del parking es obligatorio.';
        } elseif (mb_strlen(trim($data['nombre'])) > 80) {
            $errors['nombre'] = 'El nombre no puede superar los 80 caracteres.';
        }
        if (mb_strlen(trim($data['direccion'] ?? '')) < 5) {
            $errors['direccion'] = 'La dirección es obligatoria.';
        } elseif (mb_strlen(trim($data['direccion'])) > 150) {
            $errors['direccion'] = 'La dirección no puede superar los 150 caracteres.';
        }
        if (mb_strlen(trim($data['ciudad'] ?? '')) < 2) {
            $errors['ciudad'] = 'La ciudad es obligatoria.';
        } elseif (mb_strlen(trim($data['ciudad'])) > 50) {
            $errors['ciudad'] = 'La ciudad no puede superar los 50 caracteres.';
        }
        if (mb_strlen(trim($data['descripcion'] ?? '')) > 255) {
            $errors['descripcion'] = 'La descripción no puede superar los 255 caracteres.';
        }
        if (mb_strlen(trim($data['horario'] ?? '')) > 80) {
            $errors['horario'] = 'El horario no puede superar los 80 caracteres.';
        }
        if (!is_numeric($data['capacidad_total'] ?? '') || (int) $data['capacidad_total'] < 1) {
            $errors['capacidad_total'] = 'La capacidad debe ser un número mayor que cero.';
        } elseif ((int) $data['capacidad_total'] > 500) {
            $errors['capacidad_total'] = 'La capacidad no puede superar las 500 plazas.';
        }

        // El precio solo se valida al crear: al editar los datos descriptivos
        // de un parking no se toca la tarifa, que tiene su propia vigencia.
        if (array_key_exists('precio_hora', $data)) {
            if (!is_numeric($data['precio_hora']) || (float) $data['precio_hora'] < 0) {
                $errors['precio_hora'] = 'El precio por hora debe ser un número igual o mayor que cero.';
            } elseif ((float) $data['precio_hora'] > 100) {
                $errors['precio_hora'] = 'El precio por hora parece fuera de rango (máximo 100 €).';
            }
        }

        foreach (['latitud' => 90, 'longitud' => 180] as $field => $limit) {
            $value = $data[$field] ?? '';
            if ($value !== '' && (!is_numeric($value) || abs((float) $value) > $limit)) {
                $errors[$field] = 'La coordenada no es válida.';
            }
        }

        return $errors;
    }

    public static function create(array $data): int
    {
        $errors = self::validar($data);
        if ($errors !== []) {
            throw new DomainException(implode(' ', $errors));
        }

        // El parking, sus plazas y su tarifa se crean en la misma transacción.
        // Sin plazas no se puede reservar y sin tarifa no hay precio, así que
        // un parking a medias es un parking que no sirve: mejor no crearlo.
        return Database::transaction(function (PDO $pdo) use ($data): int {
            $stmt = $pdo->prepare(
                'INSERT INTO PARKING
                    (nombre, direccion, ciudad, descripcion, capacidad_total, horario, latitud, longitud, activo)
                 VALUES
                    (:nombre, :direccion, :ciudad, :descripcion, :capacidad, :horario, :lat, :lon, :activo)'
            );
            $stmt->execute([
                'nombre'      => trim($data['nombre']),
                'direccion'   => trim($data['direccion']),
                'ciudad'      => trim($data['ciudad']),
                'descripcion' => trim($data['descripcion'] ?? '') ?: null,
                'capacidad'   => (int) $data['capacidad_total'],
                'horario'     => trim($data['horario'] ?? '') ?: '24 h',
                'lat'         => ($data['latitud'] ?? '') !== '' ? (float) $data['latitud'] : null,
                'lon'         => ($data['longitud'] ?? '') !== '' ? (float) $data['longitud'] : null,
                'activo'      => isset($data['activo']) ? 1 : 0,
            ]);

            $idParking = (int) $pdo->lastInsertId();

            self::crearPlazas($pdo, $idParking, 0, (int) $data['capacidad_total']);

            $stmt = $pdo->prepare(
                'INSERT INTO TARIFA
                    (id_parking, tipo_plaza, descripcion, precio_hora, porcentaje_gestion,
                     fecha_inicio_vigencia, fecha_fin_vigencia, activa)
                 VALUES
                    (:parking, \'ESTANDAR\', :descripcion, :precio, :gestion, CURDATE(), NULL, 1)'
            );
            $stmt->execute([
                'parking'     => $idParking,
                'descripcion' => 'Tarifa general',
                'precio'      => (float) ($data['precio_hora'] ?? 1.50),
                'gestion'     => (float) ($data['porcentaje_gestion'] ?? 0.10),
            ]);

            return $idParking;
        });
    }

    /**
     * Añade `cantidad` plazas estándar al parking, numeradas a partir de las
     * que ya existan. Debe llamarse dentro de una transacción.
     */
    private static function crearPlazas(PDO $pdo, int $idParking, int $planta, int $cantidad): void
    {
        if ($cantidad < 1) {
            return;
        }

        // Se numera desde la plaza más alta que haya, para no chocar con
        // uq_plaza_codigo si el parking ya tenía plazas.
        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo_plaza, '-', -1) AS UNSIGNED)), 0)
               FROM PLAZA WHERE id_parking = :id"
        );
        $stmt->execute(['id' => $idParking]);
        $ultimo = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "INSERT INTO PLAZA (id_parking, codigo_plaza, planta, tipo_plaza, estado)
             VALUES (:parking, :codigo, :planta, 'ESTANDAR', 'LIBRE')"
        );
        for ($i = 1; $i <= $cantidad; $i++) {
            $stmt->execute([
                'parking' => $idParking,
                'codigo'  => sprintf('A-%02d', $ultimo + $i),
                'planta'  => $planta,
            ]);
        }
    }

    public static function update(int $id, array $data): void
    {
        $actual = self::find($id);
        if ($actual === null) {
            throw new DomainException('El parking no existe.');
        }

        $errors = self::validar($data);
        if ($errors !== []) {
            throw new DomainException(implode(' ', $errors));
        }

        // La capacidad no puede bajar por debajo de las plazas que ya existen:
        // borrar plazas con reservas asociadas rompería el histórico.
        $capacidad = (int) $data['capacidad_total'];
        $existentes = count(self::plazasDeParking($id));
        if ($capacidad < $existentes) {
            throw new DomainException(
                "Este parking ya tiene {$existentes} plazas creadas; la capacidad no puede ser menor. "
                . 'Para retirar una plaza, ponla fuera de servicio.'
            );
        }

        Database::transaction(function (PDO $pdo) use ($id, $data, $capacidad, $existentes): void {
            $stmt = $pdo->prepare(
                'UPDATE PARKING
                    SET nombre = :nombre, direccion = :direccion, ciudad = :ciudad,
                        descripcion = :descripcion, capacidad_total = :capacidad,
                        horario = :horario, latitud = :lat, longitud = :lon, activo = :activo
                  WHERE id_parking = :id'
            );
            $stmt->execute([
                'nombre'      => trim($data['nombre']),
                'direccion'   => trim($data['direccion']),
                'ciudad'      => trim($data['ciudad']),
                'descripcion' => trim($data['descripcion'] ?? '') ?: null,
                'capacidad'   => $capacidad,
                'horario'     => trim($data['horario'] ?? '') ?: '24 h',
                'lat'         => ($data['latitud'] ?? '') !== '' ? (float) $data['latitud'] : null,
                'lon'         => ($data['longitud'] ?? '') !== '' ? (float) $data['longitud'] : null,
                'activo'      => isset($data['activo']) ? 1 : 0,
                'id'          => $id,
            ]);

            // Ampliar la capacidad amplía el inventario: si no, el número de
            // plazas y la capacidad dirían cosas distintas.
            if ($capacidad > $existentes) {
                self::crearPlazas($pdo, $id, 0, $capacidad - $existentes);
            }
        });
    }

    /** Ocupación actual: plazas ocupadas sobre plazas utilizables. */
    public static function ocupacion(int $idParking): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT
                 COUNT(*) AS total,
                 SUM(estado = 'OCUPADA') AS ocupadas,
                 SUM(estado = 'FUERA_SERVICIO') AS fuera_servicio
               FROM PLAZA
              WHERE id_parking = :id"
        );
        $stmt->execute(['id' => $idParking]);
        $row = $stmt->fetch() ?: ['total' => 0, 'ocupadas' => 0, 'fuera_servicio' => 0];

        $total = max(1, (int) $row['total'] - (int) $row['fuera_servicio']);
        $ocupadas = (int) $row['ocupadas'];

        return [
            'total'       => (int) $row['total'],
            'ocupadas'    => $ocupadas,
            'porcentaje'  => (int) round($ocupadas * 100 / $total),
        ];
    }

    /**
     * Cambia el estado de una plaza. Solo se admite LIBRE o FUERA_SERVICIO:
     * OCUPADA lo pone la aplicación al autorizar una entrada, no una persona.
     */
    public static function cambiarEstadoPlaza(int $idPlaza, string $estado): void
    {
        if (!in_array($estado, ['LIBRE', 'FUERA_SERVICIO'], true)) {
            throw new DomainException('Ese estado de plaza no se puede fijar a mano.');
        }

        $stmt = Database::connection()->prepare('SELECT * FROM PLAZA WHERE id_plaza = :id LIMIT 1');
        $stmt->execute(['id' => $idPlaza]);
        $plaza = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$plaza) {
            throw new DomainException('La plaza no existe.');
        }

        // Una plaza con una reserva viva no se puede retirar: el cliente se
        // quedaría sin sitio al llegar aunque su reserva diga que sí.
        if ($estado === 'FUERA_SERVICIO') {
            $stmt = Database::connection()->prepare(
                "SELECT COUNT(*) FROM RESERVA
                  WHERE id_plaza = :plaza
                    AND estado IN ('PENDIENTE_PAGO','ACTIVA','EN_CURSO')"
            );
            $stmt->execute(['plaza' => $idPlaza]);
            $vivas = (int) $stmt->fetchColumn();
            if ($vivas > 0) {
                throw new DomainException(
                    "La plaza {$plaza['codigo_plaza']} tiene {$vivas} reserva(s) activa(s): "
                    . 'cancélalas o espera a que terminen antes de retirarla.'
                );
            }
        }

        $stmt = Database::connection()->prepare(
            'UPDATE PLAZA SET estado = :estado WHERE id_plaza = :id'
        );
        $stmt->execute(['estado' => $estado, 'id' => $idPlaza]);
    }

    /** Tarifas del parking, la más reciente primero. */
    public static function tarifas(int $idParking): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM TARIFA WHERE id_parking = :id
              ORDER BY tipo_plaza, fecha_inicio_vigencia DESC, id_tarifa DESC'
        );
        $stmt->execute(['id' => $idParking]);

        return $stmt->fetchAll();
    }

    /**
     * Añade una tarifa. No se edita la existente: se crea otra con una fecha
     * de inicio posterior, y `tarifaVigente()` se queda con la más reciente.
     * Así el precio que ya se cobró nunca cambia hacia atrás.
     */
    public static function crearTarifa(array $data): int
    {
        $idParking = (int) ($data['id_parking'] ?? 0);
        if (self::find($idParking) === null) {
            throw new DomainException('El parking no existe.');
        }

        $tipos = ['ESTANDAR', 'ADAPTADA', 'ELECTRICA', 'CARGA', 'MOTO'];
        $tipo = (string) ($data['tipo_plaza'] ?? 'ESTANDAR');
        if (!in_array($tipo, $tipos, true)) {
            throw new DomainException('El tipo de plaza no es válido.');
        }

        if (!is_numeric($data['precio_hora'] ?? '') || (float) $data['precio_hora'] < 0
            || (float) $data['precio_hora'] > 100) {
            throw new DomainException('El precio por hora debe estar entre 0 y 100 €.');
        }

        $fecha = trim((string) ($data['fecha_inicio_vigencia'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || strtotime($fecha) === false) {
            throw new DomainException('La fecha de inicio de vigencia no es válida.');
        }

        $stmt = Database::connection()->prepare(
            'INSERT INTO TARIFA
                (id_parking, tipo_plaza, descripcion, precio_hora, porcentaje_gestion,
                 fecha_inicio_vigencia, fecha_fin_vigencia, activa)
             VALUES
                (:parking, :tipo, :descripcion, :precio, :gestion, :inicio, NULL, 1)'
        );
        $stmt->execute([
            'parking'     => $idParking,
            'tipo'        => $tipo,
            'descripcion' => mb_substr(trim((string) ($data['descripcion'] ?? '')) ?: 'Tarifa general', 0, 80),
            'precio'      => (float) $data['precio_hora'],
            'gestion'     => 0.10,
            'inicio'      => $fecha,
        ]);

        return (int) Database::connection()->lastInsertId();
    }
}
