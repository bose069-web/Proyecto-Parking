<?php
declare(strict_types=1);

/**
 * Cálculo económico y registro de pagos.
 *
 * Regla de negocio: importe_base = horas * precio_hora, e importe_gestion es
 * ese importe base multiplicado por el porcentaje de gestión (0.10 = 10 %).
 * El exceso de tiempo se cobra por horas completas o fracción, sin gestión.
 *
 * Las funciones de cálculo son estáticas y no tocan la base de datos, para
 * poder probarlas sin MariaDB (tests/pricing_test.php).
 */
final class Payment
{
    /** Horas facturables: se cobra la hora empezada, con un mínimo de una. */
    public static function horasFacturables(string $entrada, string $salida): int
    {
        $in  = new DateTimeImmutable($entrada);
        $out = new DateTimeImmutable($salida);

        $minutos = (int) ceil(($out->getTimestamp() - $in->getTimestamp()) / 60);
        if ($minutos <= 0) {
            throw new InvalidArgumentException('La salida debe ser posterior a la entrada.');
        }

        return max(1, (int) ceil($minutos / 60));
    }

    /**
     * @return array{horas:int, base:float, gestion:float, total:float}
     */
    public static function calcular(float $precioHora, float $porcentajeGestion, int $horas): array
    {
        if ($precioHora < 0) {
            throw new InvalidArgumentException('El precio por hora no puede ser negativo.');
        }
        if ($porcentajeGestion < 0 || $porcentajeGestion > 1) {
            throw new InvalidArgumentException('El porcentaje de gestión debe estar entre 0 y 1.');
        }
        if ($horas < 1) {
            throw new InvalidArgumentException('La reserva debe durar al menos una hora.');
        }

        $base    = round($precioHora * $horas, 2);
        $gestion = round($base * $porcentajeGestion, 2);

        return [
            'horas'   => $horas,
            'base'    => $base,
            'gestion' => $gestion,
            'total'   => round($base + $gestion, 2),
        ];
    }

    /**
     * Exceso de tiempo sobre la salida prevista.
     * Los primeros $margenMin minutos no se cobran.
     *
     * @return array{minutos:int, horas:int, importe:float}
     */
    public static function exceso(
        float $precioHora,
        string $salidaPrevista,
        string $salidaReal,
        int $margenMin = 0
    ): array {
        $prevista = new DateTimeImmutable($salidaPrevista);
        $real     = new DateTimeImmutable($salidaReal);

        $minutos = (int) ceil(($real->getTimestamp() - $prevista->getTimestamp()) / 60) - $margenMin;
        if ($minutos <= 0) {
            return ['minutos' => 0, 'horas' => 0, 'importe' => 0.0];
        }

        $horas = (int) ceil($minutos / 60);

        return [
            'minutos' => $minutos,
            'horas'   => $horas,
            'importe' => round($precioHora * $horas, 2),
        ];
    }

    /** Registra un movimiento económico y devuelve su identificador. */
    public static function registrar(
        int $idReserva,
        int $idMetodoPago,
        string $tipoPago,
        float $importe,
        string $estado = 'CONFIRMADO'
    ): int {
        $referencia = sprintf('TX-%s-%s', date('YmdHis'), strtoupper(bin2hex(random_bytes(4))));

        $stmt = Database::connection()->prepare(
            'INSERT INTO PAGO
                (id_reserva, id_metodo_pago, tipo_pago, importe, estado, fecha_hora, referencia_transaccion)
             VALUES
                (:reserva, :metodo, :tipo, :importe, :estado, NOW(), :referencia)'
        );
        $stmt->execute([
            'reserva'    => $idReserva,
            'metodo'     => $idMetodoPago,
            'tipo'       => $tipoPago,
            'importe'    => $importe,
            'estado'     => $estado,
            'referencia' => $referencia,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    public static function metodosPorCliente(int $idCliente): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM METODO_PAGO WHERE id_cliente = :id AND activo = 1 ORDER BY por_defecto DESC, id_metodo_pago'
        );
        $stmt->execute(['id' => $idCliente]);

        return $stmt->fetchAll();
    }

    /** Movimientos económicos de una reserva, del más antiguo al más nuevo. */
    public static function porReserva(int $idReserva): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.*, m.tipo AS metodo_tipo, m.descripcion AS metodo_descripcion
               FROM PAGO p
               JOIN METODO_PAGO m ON m.id_metodo_pago = p.id_metodo_pago
              WHERE p.id_reserva = :id
              ORDER BY p.fecha_hora'
        );
        $stmt->execute(['id' => $idReserva]);

        return $stmt->fetchAll();
    }

    public static function metodoPorDefecto(int $idCliente): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM METODO_PAGO
              WHERE id_cliente = :id AND activo = 1
              ORDER BY por_defecto DESC, id_metodo_pago
              LIMIT 1'
        );
        $stmt->execute(['id' => $idCliente]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function totalCobradoPorCliente(int $idCliente): float
    {
        $stmt = Database::connection()->prepare(
            "SELECT COALESCE(SUM(p.importe), 0)
               FROM PAGO p
               JOIN RESERVA r ON r.id_reserva = p.id_reserva
              WHERE r.id_cliente = :id AND p.estado = 'CONFIRMADO'"
        );
        $stmt->execute(['id' => $idCliente]);

        return (float) $stmt->fetchColumn();
    }
}
