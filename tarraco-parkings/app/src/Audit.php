<?php
declare(strict_types=1);

/**
 * Traza de las acciones administrativas.
 * No se registra el contenido de las contraseñas ni tokens.
 */
final class Audit
{
    public static function log(
        ?int $idAdmin,
        string $tabla,
        ?int $registroId,
        string $accion,
        ?array $antes = null,
        ?array $despues = null
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO REGISTRO_AUDITORIA
                (id_admin, tabla_afectada, registro_id, accion, datos_anteriores, datos_nuevos, direccion_ip)
             VALUES
                (:id_admin, :tabla, :registro, :accion, :antes, :despues, :ip)'
        );

        $stmt->execute([
            'id_admin' => $idAdmin,
            'tabla'    => $tabla,
            'registro' => $registroId,
            'accion'   => $accion,
            'antes'    => $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE),
            'despues'  => $despues === null ? null : json_encode($despues, JSON_UNESCAPED_UNICODE),
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    /**
     * Últimas entradas de la traza, con el nombre del administrador.
     * La tabla se escribía desde el primer día y no había forma de leerla.
     */
    public static function recientes(int $limite = 100, array $filtros = []): array
    {
        $sql = 'SELECT a.*, ad.nombre AS admin_nombre, ad.email AS admin_email
                  FROM REGISTRO_AUDITORIA a
                  LEFT JOIN ADMINISTRADOR ad ON ad.id_admin = a.id_admin
                 WHERE 1 = 1';
        $params = [];

        if (($filtros['tabla'] ?? '') !== '') {
            $sql .= ' AND a.tabla_afectada = :tabla';
            $params['tabla'] = (string) $filtros['tabla'];
        }
        if (($filtros['accion'] ?? '') !== '') {
            $sql .= ' AND a.accion = :accion';
            $params['accion'] = (string) $filtros['accion'];
        }

        // Por fecha y no por id: los datos de ejemplo y las cargas masivas
        // pueden insertar filas con fechas anteriores a otras ya guardadas,
        // y entonces el id no refleja el orden real de los hechos.
        $limite = max(1, min(500, $limite));
        $sql .= " ORDER BY a.fecha_hora DESC, a.id_auditoria DESC LIMIT {$limite}";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Valores distintos de tabla y acción, para los desplegables de filtro. */
    public static function valores(): array
    {
        $tablas = Database::connection()
            ->query('SELECT DISTINCT tabla_afectada FROM REGISTRO_AUDITORIA ORDER BY tabla_afectada')
            ->fetchAll(PDO::FETCH_COLUMN);
        $acciones = Database::connection()
            ->query('SELECT DISTINCT accion FROM REGISTRO_AUDITORIA ORDER BY accion')
            ->fetchAll(PDO::FETCH_COLUMN);

        return ['tablas' => $tablas, 'acciones' => $acciones];
    }
}
