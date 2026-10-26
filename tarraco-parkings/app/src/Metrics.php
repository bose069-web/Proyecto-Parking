<?php
declare(strict_types=1);

/**
 * Métricas para Prometheus.
 *
 * Las peticiones HTTP se cuentan en un archivo con bloqueo, porque el
 * contenedor de Apache atiende varias peticiones en paralelo y no hay APCu.
 * Las métricas de negocio se leen de la base de datos en el momento.
 */
final class Metrics
{
    private const FILE = '/tmp/tarraco_http_counters.json';

    /** Suma una petición al contador de su clase de estado. */
    public static function record(int $status): void
    {
        $class = match (true) {
            $status >= 500 => '5xx',
            $status >= 400 => '4xx',
            $status >= 300 => '3xx',
            default        => '2xx',
        };

        $handle = @fopen(self::FILE, 'c+');
        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }

            $raw = stream_get_contents($handle);
            $data = json_decode($raw ?: '[]', true);
            if (!is_array($data)) {
                $data = [];
            }

            $data['all'] = (int) ($data['all'] ?? 0) + 1;
            $data[$class] = (int) ($data[$class] ?? 0) + 1;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string,int> */
    public static function httpSnapshot(): array
    {
        $raw = @file_get_contents(self::FILE);
        $data = $raw === false ? [] : json_decode($raw, true);
        if (!is_array($data)) {
            $data = [];
        }

        return [
            'all' => (int) ($data['all'] ?? 0),
            '2xx' => (int) ($data['2xx'] ?? 0),
            '3xx' => (int) ($data['3xx'] ?? 0),
            '4xx' => (int) ($data['4xx'] ?? 0),
            '5xx' => (int) ($data['5xx'] ?? 0),
        ];
    }

    /** Formato de exposición de Prometheus (text/plain; version=0.0.4). */
    public static function render(): string
    {
        $lines = [];
        $http  = self::httpSnapshot();

        $lines[] = '# HELP tarraco_http_requests_total Peticiones HTTP atendidas por clase de estado.';
        $lines[] = '# TYPE tarraco_http_requests_total counter';
        foreach (['2xx', '3xx', '4xx', '5xx'] as $class) {
            $lines[] = sprintf('tarraco_http_requests_total{class="%s"} %d', $class, $http[$class]);
        }

        $lines[] = '# HELP tarraco_http_requests_all_total Peticiones HTTP atendidas, sin desglose.';
        $lines[] = '# TYPE tarraco_http_requests_all_total counter';
        $lines[] = sprintf('tarraco_http_requests_all_total %d', $http['all']);

        $lines[] = '# HELP tarraco_http_5xx_total Respuestas 5xx acumuladas.';
        $lines[] = '# TYPE tarraco_http_5xx_total counter';
        $lines[] = sprintf('tarraco_http_5xx_total %d', $http['5xx']);

        try {
            $pdo = Database::connection();

            $lines[] = '# HELP tarraco_reservas_activas Reservas en estado ACTIVA o EN_CURSO.';
            $lines[] = '# TYPE tarraco_reservas_activas gauge';
            $activas = (int) $pdo->query(
                "SELECT COUNT(*) FROM RESERVA WHERE estado IN ('ACTIVA','EN_CURSO')"
            )->fetchColumn();
            $lines[] = sprintf('tarraco_reservas_activas %d', $activas);

            $lines[] = '# HELP tarraco_reservas_estado Reservas agrupadas por estado.';
            $lines[] = '# TYPE tarraco_reservas_estado gauge';
            foreach ($pdo->query('SELECT estado, COUNT(*) AS total FROM RESERVA GROUP BY estado') as $row) {
                $lines[] = sprintf(
                    'tarraco_reservas_estado{estado="%s"} %d',
                    $row['estado'],
                    (int) $row['total']
                );
            }

            $lines[] = '# HELP tarraco_plazas_libres Plazas en estado LIBRE por parking.';
            $lines[] = '# TYPE tarraco_plazas_libres gauge';
            $sql = "SELECT p.nombre, COUNT(pl.id_plaza) AS libres
                      FROM PARKING p
                      LEFT JOIN PLAZA pl ON pl.id_parking = p.id_parking AND pl.estado = 'LIBRE'
                     GROUP BY p.id_parking, p.nombre";
            foreach ($pdo->query($sql) as $row) {
                $lines[] = sprintf(
                    'tarraco_plazas_libres{parking="%s"} %d',
                    addcslashes($row['nombre'], '"\\'),
                    (int) $row['libres']
                );
            }

            $lines[] = '# HELP tarraco_ingresos_confirmados Importe cobrado con pagos confirmados.';
            $lines[] = '# TYPE tarraco_ingresos_confirmados gauge';
            $ingresos = (float) $pdo->query(
                "SELECT COALESCE(SUM(importe), 0) FROM PAGO WHERE estado = 'CONFIRMADO'"
            )->fetchColumn();
            $lines[] = sprintf('tarraco_ingresos_confirmados %.2f', $ingresos);
        } catch (Throwable $e) {
            $lines[] = '# La base de datos no responde; solo se exponen las métricas HTTP.';
        }

        return implode("\n", $lines) . "\n";
    }
}
