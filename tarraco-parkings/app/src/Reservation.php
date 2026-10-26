<?php
declare(strict_types=1);

/**
 * Reservas: creación, consulta, cancelación y cambios del administrador.
 *
 * Reglas que no se negocian:
 *  - Una reserva nunca se borra. Solo cambia de estado.
 *  - El precio se calcula al crear y se congela en importe_base/gestion/total.
 *  - La plaza se elige dentro de una transacción con FOR UPDATE, para que dos
 *    clientes simultáneos no reciban la misma.
 */
final class Reservation
{
    public const ESTADOS = [
        'PENDIENTE_PAGO',
        'ACTIVA',
        'EN_CURSO',
        'FINALIZADA',
        'CON_EXCESO',
        'CANCELADA',
        'NO_PRESENTADA',
    ];

    /** Estados que ocupan plaza en una franja. */
    public const ESTADOS_OCUPAN = ['PENDIENTE_PAGO', 'ACTIVA', 'EN_CURSO'];

    public static function crear(int $idCliente, array $data): int
    {
        $vehiculo = Vehicle::find((int) ($data['id_vehiculo'] ?? 0), $idCliente);
        if ($vehiculo === null) {
            throw new DomainException('El vehículo seleccionado no existe o no es tuyo.');
        }

        $parking = Parking::find((int) ($data['id_parking'] ?? 0));
        if ($parking === null || (int) $parking['activo'] !== 1) {
            throw new DomainException('El parking seleccionado no está disponible.');
        }

        $entrada = self::normalizarFecha((string) ($data['entrada'] ?? ''));
        $salida  = self::normalizarFecha((string) ($data['salida'] ?? ''));

        if ($entrada === null || $salida === null) {
            throw new DomainException('Las fechas de entrada y salida no son válidas.');
        }
        if (strtotime($salida) <= strtotime($entrada)) {
            throw new DomainException('La salida debe ser posterior a la entrada.');
        }
        if (strtotime($entrada) < strtotime(Database::now()) - 300) {
            throw new DomainException('No se puede reservar una franja que ya ha empezado.');
        }
        if (strtotime($salida) - strtotime($entrada) > 30 * 86400) {
            throw new DomainException('La reserva no puede durar más de 30 días.');
        }

        return Database::transaction(function (PDO $pdo) use ($idCliente, $parking, $vehiculo, $entrada, $salida, $data): int {
            $tipos = Parking::tiposCompatibles((string) $vehiculo['tipo_vehiculo']);

            $plaza = Parking::plazaDisponible(
                (int) $parking['id_parking'],
                $tipos,
                $entrada,
                $salida,
                $pdo
            );
            if ($plaza === null) {
                throw new DomainException(
                    'No quedan plazas libres de este tipo en la franja seleccionada.'
                );
            }

            $tarifa = Parking::tarifaVigente(
                (int) $parking['id_parking'],
                (string) $plaza['tipo_plaza'],
                $entrada
            );
            if ($tarifa === null) {
                throw new DomainException('No hay ninguna tarifa vigente para este parking.');
            }

            $horas    = Payment::horasFacturables($entrada, $salida);
            $importes = Payment::calcular(
                (float) $tarifa['precio_hora'],
                (float) $tarifa['porcentaje_gestion'],
                $horas
            );

            $metodo = Payment::metodoPorDefecto($idCliente);
            if ($metodo === null) {
                throw new DomainException('Antes de reservar necesitas un método de pago activo.');
            }

            $codigo = self::generarCodigo();

            $stmt = $pdo->prepare(
                'INSERT INTO RESERVA
                    (id_cliente, id_vehiculo, id_parking, id_plaza, id_tarifa, codigo_reserva,
                     fecha_hora_entrada_prevista, fecha_hora_salida_prevista,
                     estado, importe_base, importe_gestion, importe_total, importe_exceso,
                     observaciones, fecha_creacion)
                 VALUES
                    (:cliente, :vehiculo, :parking, :plaza, :tarifa, :codigo,
                     :entrada, :salida,
                     :estado, :base, :gestion, :total, 0.00,
                     :observaciones, NOW())'
            );
            $stmt->execute([
                'cliente'       => $idCliente,
                'vehiculo'      => (int) $vehiculo['id_vehiculo'],
                'parking'       => (int) $parking['id_parking'],
                'plaza'         => (int) $plaza['id_plaza'],
                'tarifa'        => (int) $tarifa['id_tarifa'],
                'codigo'        => $codigo,
                'entrada'       => $entrada,
                'salida'        => $salida,
                'estado'        => 'PENDIENTE_PAGO',
                'base'          => $importes['base'],
                'gestion'       => $importes['gestion'],
                'total'         => $importes['total'],
                'observaciones' => mb_substr(trim((string) ($data['observaciones'] ?? '')), 0, 255) ?: null,
            ]);

            $idReserva = (int) $pdo->lastInsertId();

            // Pasarela simulada: el pago se confirma en el acto. Si fallara,
            // la reserva se quedaría en PENDIENTE_PAGO y no ocuparía la plaza
            // más allá del tiempo de bloqueo configurado.
            Payment::registrar(
                $idReserva,
                (int) $metodo['id_metodo_pago'],
                'RESERVA',
                $importes['total'],
                'CONFIRMADO'
            );

            $pdo->prepare('UPDATE RESERVA SET estado = :estado WHERE id_reserva = :id')
                ->execute(['estado' => 'ACTIVA', 'id' => $idReserva]);

            return $idReserva;
        });
    }

    public static function find(int $id, ?int $idCliente = null): ?array
    {
        $sql = 'SELECT r.*,
                       p.nombre AS parking_nombre, p.direccion, p.ciudad,
                       pl.codigo_plaza, pl.tipo_plaza,
                       v.matricula, v.marca, v.modelo,
                       t.precio_hora, t.porcentaje_gestion,
                       c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos, c.email AS cliente_email
                  FROM RESERVA r
                  JOIN PARKING p     ON p.id_parking = r.id_parking
                  LEFT JOIN PLAZA pl ON pl.id_plaza = r.id_plaza
                  JOIN VEHICULO v    ON v.id_vehiculo = r.id_vehiculo
                  JOIN TARIFA t      ON t.id_tarifa = r.id_tarifa
                  JOIN CLIENTE c     ON c.id_cliente = r.id_cliente
                 WHERE r.id_reserva = :id';

        $params = ['id' => $id];
        if ($idCliente !== null) {
            $sql .= ' AND r.id_cliente = :cliente';
            $params['cliente'] = $idCliente;
        }
        $sql .= ' LIMIT 1';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function listByCliente(int $idCliente, int $limit = 100): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.*, p.nombre AS parking_nombre, p.direccion,
                    pl.codigo_plaza, v.matricula
               FROM RESERVA r
               JOIN PARKING p     ON p.id_parking = r.id_parking
               LEFT JOIN PLAZA pl ON pl.id_plaza = r.id_plaza
               JOIN VEHICULO v    ON v.id_vehiculo = r.id_vehiculo
              WHERE r.id_cliente = :cliente
              ORDER BY r.fecha_hora_entrada_prevista DESC
              LIMIT :limite'
        );
        $stmt->bindValue(':cliente', $idCliente, PDO::PARAM_INT);
        $stmt->bindValue(':limite', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Indica si el cliente puede cancelar todavía, con el reloj de la base. */
    public static function esCancelable(array $reserva): bool
    {
        if (!in_array($reserva['estado'], ['PENDIENTE_PAGO', 'ACTIVA'], true)) {
            return false;
        }

        return strtotime($reserva['fecha_hora_entrada_prevista']) > strtotime(Database::now());
    }

    /** Cancela una reserva propia. Devuelve el importe reembolsado. */
    public static function cancelar(int $id, int $idCliente): float
    {
        return Database::transaction(function (PDO $pdo) use ($id, $idCliente): float {
            $stmt = $pdo->prepare(
                'SELECT * FROM RESERVA WHERE id_reserva = :id AND id_cliente = :cliente FOR UPDATE'
            );
            $stmt->execute(['id' => $id, 'cliente' => $idCliente]);
            $reserva = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reserva) {
                throw new DomainException('La reserva no existe o no es tuya.');
            }
            if (!in_array($reserva['estado'], ['PENDIENTE_PAGO', 'ACTIVA'], true)) {
                throw new DomainException('Esta reserva ya no se puede cancelar.');
            }
            if (strtotime($reserva['fecha_hora_entrada_prevista']) <= strtotime($pdo->query('SELECT NOW()')->fetchColumn())) {
                throw new DomainException('La reserva ya ha empezado; no se puede cancelar.');
            }

            $pdo->prepare('UPDATE RESERVA SET estado = :estado WHERE id_reserva = :id')
                ->execute(['estado' => 'CANCELADA', 'id' => $id]);

            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(importe), 0) FROM PAGO
                  WHERE id_reserva = :id AND tipo_pago = 'RESERVA' AND estado = 'CONFIRMADO'"
            );
            $stmt->execute(['id' => $id]);
            $reembolso = (float) $stmt->fetchColumn();

            $pdo->prepare(
                "UPDATE PAGO SET estado = 'REEMBOLSADO'
                  WHERE id_reserva = :id AND tipo_pago = 'RESERVA' AND estado = 'CONFIRMADO'"
            )->execute(['id' => $id]);

            return $reembolso;
        });
    }

    /** Reservas que ya deberían haber entrado y no lo han hecho. */
    public static function marcarNoPresentadas(int $margenMin = 30): int
    {
        $margen = max(0, min(1440, $margenMin));

        $stmt = Database::connection()->prepare(
            "UPDATE RESERVA
                SET estado = 'NO_PRESENTADA'
              WHERE estado = 'ACTIVA'
                AND fecha_hora_entrada_real IS NULL
                AND fecha_hora_entrada_prevista < NOW() - INTERVAL {$margen} MINUTE"
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Cancela las reservas que se quedaron a medias en el pago.
     * Sin esto, una reserva abandonada bloquearía su plaza para siempre.
     */
    public static function expirarPendientes(int $minutos = 15): int
    {
        $minutos = max(1, min(1440, $minutos));

        return Database::transaction(function (PDO $pdo) use ($minutos): int {
            $stmt = $pdo->prepare(
                "UPDATE RESERVA
                    SET estado = 'CANCELADA'
                  WHERE estado = 'PENDIENTE_PAGO'
                    AND fecha_creacion < NOW() - INTERVAL {$minutos} MINUTE"
            );
            $stmt->execute();
            $cerradas = $stmt->rowCount();

            if ($cerradas > 0) {
                $pdo->prepare(
                    "UPDATE PAGO p
                       JOIN RESERVA r ON r.id_reserva = p.id_reserva
                        SET p.estado = 'FALLIDO'
                      WHERE p.estado = 'PENDIENTE'
                        AND r.estado = 'CANCELADA'
                        AND r.fecha_creacion < NOW() - INTERVAL {$minutos} MINUTE"
                )->execute();
            }

            return $cerradas;
        });
    }

    /** @return array<string,int> */
    public static function resumenCliente(int $idCliente): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT estado, COUNT(*) AS total FROM RESERVA WHERE id_cliente = :id GROUP BY estado'
        );
        $stmt->execute(['id' => $idCliente]);

        $out = array_fill_keys(self::ESTADOS, 0);
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['estado']] = (int) $row['total'];
        }

        return $out;
    }

    public static function proxima(int $idCliente): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT r.*, p.nombre AS parking_nombre, pl.codigo_plaza
               FROM RESERVA r
               JOIN PARKING p ON p.id_parking = r.id_parking
               LEFT JOIN PLAZA pl ON pl.id_plaza = r.id_plaza
              WHERE r.id_cliente = :id
                AND r.estado IN ('ACTIVA','EN_CURSO')
                AND r.fecha_hora_salida_prevista >= NOW()
              ORDER BY r.fecha_hora_entrada_prevista
              LIMIT 1"
        );
        $stmt->execute(['id' => $idCliente]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Listado del panel de administración con filtros. */
    public static function adminList(array $filtros = []): array
    {
        $sql = 'SELECT r.*, p.nombre AS parking_nombre, v.matricula,
                       c.nombre AS cliente_nombre, c.apellidos AS cliente_apellidos
                  FROM RESERVA r
                  JOIN PARKING p  ON p.id_parking = r.id_parking
                  JOIN VEHICULO v ON v.id_vehiculo = r.id_vehiculo
                  JOIN CLIENTE c  ON c.id_cliente = r.id_cliente
                 WHERE 1 = 1';
        $params = [];

        if (!empty($filtros['estado']) && in_array($filtros['estado'], self::ESTADOS, true)) {
            $sql .= ' AND r.estado = :estado';
            $params['estado'] = $filtros['estado'];
        }
        if (!empty($filtros['parking'])) {
            $sql .= ' AND r.id_parking = :parking';
            $params['parking'] = (int) $filtros['parking'];
        }
        if (!empty($filtros['q'])) {
            $sql .= ' AND (r.codigo_reserva LIKE :q OR v.matricula LIKE :q2 OR c.email LIKE :q3)';
            $like = '%' . trim((string) $filtros['q']) . '%';
            $params['q'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
        }

        $sql .= ' ORDER BY r.fecha_hora_entrada_prevista DESC LIMIT 250';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Cambios del administrador: fechas, plaza y observaciones. */
    public static function adminActualizar(int $id, array $data, int $idAdmin): void
    {
        Database::transaction(function (PDO $pdo) use ($id, $data, $idAdmin): void {
            $stmt = $pdo->prepare('SELECT * FROM RESERVA WHERE id_reserva = :id FOR UPDATE');
            $stmt->execute(['id' => $id]);
            $reserva = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reserva) {
                throw new DomainException('La reserva no existe.');
            }
            if (!in_array($reserva['estado'], ['PENDIENTE_PAGO', 'ACTIVA'], true)) {
                throw new DomainException('Solo se pueden modificar reservas pendientes o activas.');
            }

            $entrada = self::normalizarFecha((string) ($data['entrada'] ?? ''));
            $salida  = self::normalizarFecha((string) ($data['salida'] ?? ''));
            if ($entrada === null || $salida === null || strtotime($salida) <= strtotime($entrada)) {
                throw new DomainException('Las fechas no son válidas.');
            }

            $idPlaza = isset($data['id_plaza']) && $data['id_plaza'] !== ''
                ? (int) $data['id_plaza']
                : null;

            if ($idPlaza !== null) {
                $stmt = $pdo->prepare(
                    "SELECT estado FROM PLAZA WHERE id_plaza = :plaza AND id_parking = :parking LIMIT 1"
                );
                $stmt->execute(['plaza' => $idPlaza, 'parking' => (int) $reserva['id_parking']]);
                $estadoPlaza = $stmt->fetchColumn();

                if ($estadoPlaza === false) {
                    throw new DomainException('La plaza elegida no pertenece a este parking.');
                }
                if ($estadoPlaza === 'FUERA_SERVICIO') {
                    throw new DomainException('La plaza elegida está fuera de servicio.');
                }
            }

            // Se comprueba siempre que hay plaza, no solo cuando cambia: al
            // mover las fechas también se puede invadir otra reserva.
            if ($idPlaza !== null) {
                $stmt = $pdo->prepare(
                    "SELECT 1 FROM RESERVA
                      WHERE id_plaza = :plaza AND id_reserva <> :id
                        AND estado IN ('PENDIENTE_PAGO','ACTIVA','EN_CURSO')
                        AND fecha_hora_entrada_prevista < :salida
                        AND fecha_hora_salida_prevista > :entrada
                      LIMIT 1"
                );
                $stmt->execute([
                    'plaza'   => $idPlaza,
                    'id'      => $id,
                    'salida'  => $salida,
                    'entrada' => $entrada,
                ]);
                if ($stmt->fetchColumn()) {
                    throw new DomainException('La plaza elegida ya está ocupada en esa franja.');
                }
            }

            $tarifa = $pdo->prepare('SELECT * FROM TARIFA WHERE id_tarifa = :id');
            $tarifa->execute(['id' => $reserva['id_tarifa']]);
            $t = $tarifa->fetch(PDO::FETCH_ASSOC);

            $horas    = Payment::horasFacturables($entrada, $salida);
            $importes = Payment::calcular((float) $t['precio_hora'], (float) $t['porcentaje_gestion'], $horas);

            $pdo->prepare(
                'UPDATE RESERVA
                    SET fecha_hora_entrada_prevista = :entrada,
                        fecha_hora_salida_prevista = :salida,
                        id_plaza = :plaza,
                        importe_base = :base,
                        importe_gestion = :gestion,
                        importe_total = :total,
                        observaciones = :observaciones
                  WHERE id_reserva = :id'
            )->execute([
                'entrada'       => $entrada,
                'salida'        => $salida,
                'plaza'         => $idPlaza,
                'base'          => $importes['base'],
                'gestion'       => $importes['gestion'],
                'total'         => $importes['total'],
                'observaciones' => mb_substr(trim((string) ($data['observaciones'] ?? '')), 0, 255) ?: null,
                'id'            => $id,
            ]);

            Audit::log($idAdmin, 'RESERVA', $id, 'MODIFICAR', [
                'entrada' => $reserva['fecha_hora_entrada_prevista'],
                'salida'  => $reserva['fecha_hora_salida_prevista'],
                'plaza'   => $reserva['id_plaza'],
                'total'   => $reserva['importe_total'],
            ], [
                'entrada' => $entrada,
                'salida'  => $salida,
                'plaza'   => $idPlaza,
                'total'   => $importes['total'],
            ]);
        });
    }

    public static function adminCancelar(int $id, int $idAdmin, string $motivo = ''): void
    {
        Database::transaction(function (PDO $pdo) use ($id, $idAdmin, $motivo): void {
            $stmt = $pdo->prepare('SELECT * FROM RESERVA WHERE id_reserva = :id FOR UPDATE');
            $stmt->execute(['id' => $id]);
            $reserva = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reserva) {
                throw new DomainException('La reserva no existe.');
            }
            if (!in_array($reserva['estado'], ['PENDIENTE_PAGO', 'ACTIVA'], true)) {
                throw new DomainException('Esta reserva ya no se puede cancelar.');
            }

            $pdo->prepare('UPDATE RESERVA SET estado = :estado, observaciones = :obs WHERE id_reserva = :id')
                ->execute([
                    'estado' => 'CANCELADA',
                    'obs'    => $motivo !== '' ? mb_substr($motivo, 0, 180) : $reserva['observaciones'],
                    'id'     => $id,
                ]);

            $pdo->prepare(
                "UPDATE PAGO SET estado = 'REEMBOLSADO'
                  WHERE id_reserva = :id AND tipo_pago = 'RESERVA' AND estado = 'CONFIRMADO'"
            )->execute(['id' => $id]);

            Audit::log($idAdmin, 'RESERVA', $id, 'CANCELAR', [
                'estado' => $reserva['estado'],
            ], [
                'estado' => 'CANCELADA',
                'motivo' => $motivo,
            ]);
        });
    }

    /** @return array<string,int|float> */
    public static function adminResumen(): array
    {
        $pdo = Database::connection();

        $porEstado = [];
        foreach ($pdo->query('SELECT estado, COUNT(*) AS total FROM RESERVA GROUP BY estado')->fetchAll() as $row) {
            $porEstado[$row['estado']] = (int) $row['total'];
        }

        $ingresos = (float) $pdo->query(
            "SELECT COALESCE(SUM(importe), 0) FROM PAGO WHERE estado = 'CONFIRMADO'"
        )->fetchColumn();

        $hoy = (int) $pdo->query(
            'SELECT COUNT(*) FROM RESERVA WHERE DATE(fecha_hora_entrada_prevista) = CURDATE()'
        )->fetchColumn();

        $clientes = (int) $pdo->query("SELECT COUNT(*) FROM CLIENTE WHERE estado = 'ACTIVO'")->fetchColumn();

        return [
            'por_estado' => $porEstado,
            'ingresos'   => $ingresos,
            'hoy'        => $hoy,
            'clientes'   => $clientes,
            'activas'    => ($porEstado['ACTIVA'] ?? 0) + ($porEstado['EN_CURSO'] ?? 0),
        ];
    }

    public static function generarCodigo(): string
    {
        return sprintf('TR-%s-%s', date('Y'), strtoupper(bin2hex(random_bytes(4))));
    }

    /** Acepta el formato de datetime-local y el de MySQL. */
    public static function normalizarFecha(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $value);
            if ($dt === false) {
                continue;
            }
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }
            return $dt->format('Y-m-d H:i:s');
        }

        return null;
    }
}
