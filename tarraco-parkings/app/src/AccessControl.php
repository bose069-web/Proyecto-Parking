<?php
declare(strict_types=1);

/**
 * Control de acceso SIMULADO.
 *
 * No hay lector de matrículas ni controlador de barrera: la matrícula se
 * teclea en un formulario y el sistema decide si el paso está autorizado.
 * Cada intento, autorizado o no, queda registrado en REGISTRO_ACCESO.
 */
final class AccessControl
{
    private const MARGEN_ENTRADA = 30; // minutos antes de la hora prevista
    private const MARGEN_SALIDA  = 15; // minutos de gracia al salir

    /**
     * @return array{ok:bool, mensaje:string, reserva:?array, exceso:?array}
     */
    public static function entrada(int $idParking, string $matricula, ?int $soloCliente = null): array
    {
        $placa    = Vehicle::normalizarMatricula($matricula);
        $parking  = Parking::find($idParking);
        $vehiculo = Vehicle::findByMatricula($placa);

        // Si la petición viene de un cliente, solo puede mover sus vehículos.
        if ($soloCliente !== null && $vehiculo !== null && (int) $vehiculo['id_cliente'] !== $soloCliente) {
            throw new DomainException('Ese vehículo no está en tu cuenta.');
        }

        if ($parking === null || (int) $parking['activo'] !== 1) {
            throw new DomainException('El parking no existe o está fuera de servicio.');
        }
        if ($placa === '') {
            throw new DomainException('Escribe una matrícula.');
        }

        return Database::transaction(function (PDO $pdo) use ($idParking, $placa, $vehiculo): array {
            if ($vehiculo === null) {
                $id = self::registrar($pdo, $idParking, null, null, $placa, 'ENTRADA', false,
                    'La matrícula no corresponde a ningún vehículo registrado.');

                return [
                    'ok'      => false,
                    'mensaje' => 'La matrícula no está registrada.',
                    'reserva' => null,
                    'exceso'  => null,
                    'id'      => $id,
                ];
            }

            $dentro = $pdo->prepare(
                "SELECT id_parking FROM RESERVA
                  WHERE id_vehiculo = :vehiculo AND estado = 'EN_CURSO' LIMIT 1"
            );
            $dentro->execute(['vehiculo' => (int) $vehiculo['id_vehiculo']]);
            $parkingDentro = $dentro->fetchColumn();

            if ($parkingDentro !== false) {
                $id = self::registrar($pdo, $idParking, (int) $vehiculo['id_vehiculo'], null,
                    $placa, 'ENTRADA', false, 'El vehículo ya consta dentro de un parking.');

                return [
                    'ok'      => false,
                    'mensaje' => 'El vehículo ya consta dentro de un parking.',
                    'reserva' => null,
                    'exceso'  => null,
                    'id'      => $id,
                ];
            }

            $reserva = self::reservaParaEntrada($pdo, (int) $vehiculo['id_vehiculo'], $idParking);

            if ($reserva === null) {
                $pendiente = self::reservaPendiente($pdo, (int) $vehiculo['id_vehiculo'], $idParking);
                $futura    = self::reservaFutura($pdo, (int) $vehiculo['id_vehiculo'], $idParking);

                // El motivo tiene que decir la verdad: una reserva que existe
                // pero empieza más tarde no es «no hay reserva», y un cliente
                // con plaza confirmada merece saber desde cuándo puede entrar.
                if ($pendiente !== null) {
                    $motivo = 'La reserva existe pero el pago no está confirmado.';
                } elseif ($futura !== null) {
                    $desde = date('H:i', strtotime($futura['fecha_hora_entrada_prevista'])
                        - self::MARGEN_ENTRADA * 60);
                    $motivo = sprintf(
                        'Tu reserva empieza a las %s; podrás entrar a partir de las %s.',
                        date('H:i', strtotime($futura['fecha_hora_entrada_prevista'])),
                        $desde
                    );
                } else {
                    $motivo = 'No hay ninguna reserva activa para este vehículo en este parking.';
                }

                $id = self::registrar($pdo, $idParking, (int) $vehiculo['id_vehiculo'],
                    $pendiente['id_reserva'] ?? $futura['id_reserva'] ?? null, $placa, 'ENTRADA', false, $motivo);

                return [
                    'ok'      => false,
                    // El motivo ya es una frase completa: no se le añade punto.
                    'mensaje' => ucfirst($motivo),
                    'reserva' => $pendiente ?? $futura,
                    'exceso'  => null,
                    'id'      => $id,
                ];
            }

            $pdo->prepare(
                "UPDATE RESERVA
                    SET estado = 'EN_CURSO', fecha_hora_entrada_real = NOW()
                  WHERE id_reserva = :id"
            )->execute(['id' => $reserva['id_reserva']]);

            if ($reserva['id_plaza'] !== null) {
                $pdo->prepare("UPDATE PLAZA SET estado = 'OCUPADA' WHERE id_plaza = :id")
                    ->execute(['id' => $reserva['id_plaza']]);
            }

            $id = self::registrar($pdo, $idParking, (int) $vehiculo['id_vehiculo'],
                (int) $reserva['id_reserva'], $placa, 'ENTRADA', true, null);

            // Se relee la reserva: si se devolviera la fila leída antes del
            // UPDATE, la pantalla diría ACTIVA justo después de autorizar la
            // entrada, y la hora real no sería la que ha grabado la base.
            $stmt = $pdo->prepare(
                'SELECT r.*, pl.codigo_plaza
                   FROM RESERVA r
                   LEFT JOIN PLAZA pl ON pl.id_plaza = r.id_plaza
                  WHERE r.id_reserva = :id'
            );
            $stmt->execute(['id' => (int) $reserva['id_reserva']]);
            $reservaActualizada = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($reservaActualizada) {
                $reserva = $reservaActualizada;
            }

            return [
                'ok'      => true,
                'mensaje' => 'Plaza ' . ($reserva['codigo_plaza'] ?? 'sin asignar') . ' asignada.',
                'reserva' => $reserva,
                'exceso'  => null,
                'id'      => $id,
            ];
        });
    }

    /**
     * @return array{ok:bool, mensaje:string, reserva:?array, exceso:?array}
     */
    public static function salida(int $idParking, string $matricula, ?int $soloCliente = null): array
    {
        $placa    = Vehicle::normalizarMatricula($matricula);
        $parking  = Parking::find($idParking);
        $vehiculo = Vehicle::findByMatricula($placa);

        if ($soloCliente !== null && $vehiculo !== null && (int) $vehiculo['id_cliente'] !== $soloCliente) {
            throw new DomainException('Ese vehículo no está en tu cuenta.');
        }

        if ($parking === null || (int) $parking['activo'] !== 1) {
            throw new DomainException('El parking no existe o está fuera de servicio.');
        }
        if ($placa === '') {
            throw new DomainException('Escribe una matrícula.');
        }
        return Database::transaction(function (PDO $pdo) use ($idParking, $placa, $vehiculo): array {
            if ($vehiculo === null) {
                $id = self::registrar($pdo, $idParking, null, null, $placa, 'SALIDA', false,
                    'La matrícula no corresponde a ningún vehículo registrado.');

                return [
                    'ok'      => false,
                    'mensaje' => 'La matrícula no está registrada.',
                    'reserva' => null,
                    'exceso'  => null,
                    'id'      => $id,
                ];
            }
            $stmt = $pdo->prepare(
                "SELECT r.*, t.precio_hora, t.porcentaje_gestion, pl.codigo_plaza
                   FROM RESERVA r
                   JOIN TARIFA t      ON t.id_tarifa = r.id_tarifa
                   LEFT JOIN PLAZA pl ON pl.id_plaza = r.id_plaza
                  WHERE r.id_vehiculo = :vehiculo
                    AND r.id_parking = :parking
                    AND r.estado = 'EN_CURSO'
                  ORDER BY r.fecha_hora_entrada_real DESC
                  LIMIT 1
                  FOR UPDATE"
            );
            $stmt->execute([
                'vehiculo' => (int) $vehiculo['id_vehiculo'],
                'parking'  => $idParking,
            ]);
            $reserva = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reserva) {
                $id = self::registrar($pdo, $idParking, (int) $vehiculo['id_vehiculo'], null,
                    $placa, 'SALIDA', false, 'No consta ninguna entrada en curso para este vehículo.');

                return [
                    'ok'      => false,
                    'mensaje' => 'No consta una entrada en curso para este vehículo.',
                    'reserva' => null,
                    'exceso'  => null,
                    'id'      => $id,
                ];
            }

            $ahora  = (string) $pdo->query('SELECT NOW()')->fetchColumn();
            $exceso = Payment::exceso(
                (float) $reserva['precio_hora'],
                (string) $reserva['fecha_hora_salida_prevista'],
                $ahora,
                self::MARGEN_SALIDA
            );

            $estado = $exceso['importe'] > 0 ? 'CON_EXCESO' : 'FINALIZADA';

            $pdo->prepare(
                'UPDATE RESERVA
                    SET estado = :estado,
                        fecha_hora_salida_real = :ahora,
                        importe_exceso = :exceso
                  WHERE id_reserva = :id'
            )->execute([
                'estado' => $estado,
                'ahora'  => $ahora,
                'exceso' => $exceso['importe'],
                'id'     => $reserva['id_reserva'],
            ]);

            if ($exceso['importe'] > 0) {
                $metodo = self::metodoDeReserva($pdo, (int) $reserva['id_reserva'], (int) $reserva['id_cliente']);

                Payment::registrar(
                    (int) $reserva['id_reserva'],
                    $metodo,
                    'EXCESO',
                    $exceso['importe'],
                    'CONFIRMADO'
                );
            }

            if ($reserva['id_plaza'] !== null) {
                $pdo->prepare("UPDATE PLAZA SET estado = 'LIBRE' WHERE id_plaza = :id")
                    ->execute(['id' => $reserva['id_plaza']]);
            }

            $observacion = $exceso['importe'] > 0
                ? sprintf('Exceso de %d min (%d h): %s', $exceso['minutos'], $exceso['horas'], money($exceso['importe']))
                : null;

            $id = self::registrar($pdo, $idParking, (int) $vehiculo['id_vehiculo'],
                (int) $reserva['id_reserva'], $placa, 'SALIDA', true, null, $observacion);

            // Igual que en la entrada: se relee la reserva para que la pantalla
            // muestre el estado en que ha quedado de verdad.
            $stmt = $pdo->prepare(
                'SELECT r.*, pl.codigo_plaza
                   FROM RESERVA r
                   LEFT JOIN PLAZA pl ON pl.id_plaza = r.id_plaza
                  WHERE r.id_reserva = :id'
            );
            $stmt->execute(['id' => (int) $reserva['id_reserva']]);
            $reservaActualizada = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($reservaActualizada) {
                $reserva = $reservaActualizada;
            }

            return [
                'ok'      => true,
                'mensaje' => $exceso['importe'] > 0
                    ? sprintf('Exceso de %d h: %s.', $exceso['horas'], money($exceso['importe']))
                    : 'Dentro del tiempo previsto.',
                'reserva' => $reserva,
                'exceso'  => $exceso,
                'id'      => $id,
            ];
        });
    }

    public static function historial(?int $idParking = null, int $limit = 100): array
    {
        $sql = 'SELECT a.*, p.nombre AS parking_nombre, v.marca, v.modelo
                  FROM REGISTRO_ACCESO a
                  JOIN PARKING p  ON p.id_parking = a.id_parking
                  LEFT JOIN VEHICULO v ON v.id_vehiculo = a.id_vehiculo
                 WHERE 1 = 1';
        $params = [];

        if ($idParking !== null) {
            $sql .= ' AND a.id_parking = :parking';
            $params['parking'] = $idParking;
        }

        $sql .= ' ORDER BY a.fecha_hora DESC LIMIT ' . max(1, min(500, $limit));

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private static function reservaParaEntrada(PDO $pdo, int $idVehiculo, int $idParking): ?array
    {
        $margen = self::MARGEN_ENTRADA;

        $sql = "SELECT r.*, pl.codigo_plaza, t.precio_hora
                  FROM RESERVA r
                  LEFT JOIN PLAZA pl ON pl.id_plaza = r.id_plaza
                  JOIN TARIFA t     ON t.id_tarifa = r.id_tarifa
                 WHERE r.id_vehiculo = :vehiculo
                   AND r.id_parking = :parking
                   AND r.estado = 'ACTIVA'
                   AND r.fecha_hora_entrada_prevista <= DATE_ADD(NOW(), INTERVAL {$margen} MINUTE)
                   AND r.fecha_hora_salida_prevista >= NOW()
                 ORDER BY r.fecha_hora_entrada_prevista
                 LIMIT 1
                 FOR UPDATE";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['vehiculo' => $idVehiculo, 'parking' => $idParking]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function reservaPendiente(PDO $pdo, int $idVehiculo, int $idParking): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM RESERVA
              WHERE id_vehiculo = :vehiculo AND id_parking = :parking
                AND estado = 'PENDIENTE_PAGO'
                AND fecha_hora_salida_prevista >= NOW()
              ORDER BY fecha_hora_entrada_prevista
              LIMIT 1"
        );
        $stmt->execute(['vehiculo' => $idVehiculo, 'parking' => $idParking]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Reserva pagada cuya franja todavía no ha empezado. Sirve para explicar
     * al cliente que su plaza existe y cuándo podrá entrar, en lugar de
     * contestar «no tienes reserva», que sería mentira.
     */
    private static function reservaFutura(PDO $pdo, int $idVehiculo, int $idParking): ?array
    {
        // El margen es una constante entera de la clase, no un dato del usuario:
        // se interpola como en reservaParaEntrada() para que la consulta no
        // lleve un marcador que no aporta nada.
        $margen = self::MARGEN_ENTRADA;

        $stmt = $pdo->prepare(
            "SELECT * FROM RESERVA
              WHERE id_vehiculo = :vehiculo AND id_parking = :parking
                AND estado = 'ACTIVA'
                AND fecha_hora_entrada_prevista > DATE_ADD(NOW(), INTERVAL {$margen} MINUTE)
                AND fecha_hora_salida_prevista >= NOW()
              ORDER BY fecha_hora_entrada_prevista
              LIMIT 1"
        );
        $stmt->execute(['vehiculo' => $idVehiculo, 'parking' => $idParking]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function metodoDeReserva(PDO $pdo, int $idReserva, int $idCliente): int
    {
        $stmt = $pdo->prepare(
            "SELECT id_metodo_pago FROM PAGO
              WHERE id_reserva = :id AND tipo_pago = 'RESERVA'
              ORDER BY id_pago DESC LIMIT 1"
        );
        $stmt->execute(['id' => $idReserva]);
        $metodo = $stmt->fetchColumn();
        if ($metodo !== false) {
            return (int) $metodo;
        }

        $defecto = Payment::metodoPorDefecto($idCliente);
        if ($defecto === null) {
            throw new DomainException('El cliente no tiene ningún método de pago activo.');
        }

        return (int) $defecto['id_metodo_pago'];
    }

    private static function registrar(
        PDO $pdo,
        int $idParking,
        ?int $idVehiculo,
        ?int $idReserva,
        string $matricula,
        string $movimiento,
        bool $autorizado,
        ?string $motivo,
        ?string $observaciones = null
    ): int {
        $stmt = $pdo->prepare(
            'INSERT INTO REGISTRO_ACCESO
                (id_parking, id_vehiculo, id_reserva, matricula_leida, tipo_movimiento,
                 fecha_hora, autorizado, motivo_denegacion, observaciones)
             VALUES
                (:parking, :vehiculo, :reserva, :matricula, :movimiento,
                 NOW(), :autorizado, :motivo, :observaciones)'
        );
        $stmt->execute([
            'parking'       => $idParking,
            'vehiculo'      => $idVehiculo,
            'reserva'       => $idReserva,
            'matricula'     => $matricula,
            'movimiento'    => $movimiento,
            'autorizado'    => $autorizado ? 1 : 0,
            'motivo'        => $motivo,
            'observaciones' => $observaciones,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
