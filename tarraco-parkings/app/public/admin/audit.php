<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

Auth::checkAdmin();

// Consulta, no escritura: el OPERADOR también puede mirarla. Que la traza sea
// legible por quien no puede modificarla es justamente lo que la hace útil.
$filtros = [
    'tabla'  => (string) ($_GET['tabla'] ?? ''),
    'accion' => (string) ($_GET['accion'] ?? ''),
];

$registros = Audit::recientes(100, $filtros);
$valores   = Audit::valores();

/** Los JSON guardados son largos: se muestran recortados y completos al abrir. */
function recorte(?string $json): string
{
    if ($json === null || $json === '') {
        return '—';
    }
    $datos = json_decode($json, true);
    if (!is_array($datos)) {
        return $json;
    }

    return implode(', ', array_map(
        static fn (string $k, mixed $v): string => $k . '=' . (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)),
        array_keys($datos),
        array_values($datos)
    ));
}

render_header('Auditoría', 'auditoria', true);
?>

<div class="page-head">
    <div>
        <p class="eyebrow">Panel de gestión</p>
        <h1>Auditoría</h1>
        <p>Quién cambió qué y cuándo. Las 100 últimas acciones, la más reciente primero.</p>
    </div>
</div>

<section class="card">
    <div class="card-head">
        <h2><?= plural(count($registros), 'registro', 'registros') ?></h2>
        <form method="get" action="/admin/audit.php" class="inline-form">
            <label class="sr-only" for="a-tabla">Tabla afectada</label>
            <select id="a-tabla" name="tabla">
                <option value="">Todas las tablas</option>
                <?php foreach ($valores['tablas'] as $t): ?>
                    <option value="<?= e($t) ?>" <?= $filtros['tabla'] === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="sr-only" for="a-accion">Acción</label>
            <select id="a-accion" name="accion">
                <option value="">Todas las acciones</option>
                <?php foreach ($valores['acciones'] as $ac): ?>
                    <option value="<?= e($ac) ?>" <?= $filtros['accion'] === $ac ? 'selected' : '' ?>><?= e($ac) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-ghost btn-small" type="submit">Filtrar</button>
            <?php if ($filtros['tabla'] !== '' || $filtros['accion'] !== ''): ?>
                <a class="btn btn-ghost btn-small" href="/admin/audit.php">Quitar filtros</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($registros === []): ?>
        <div class="empty">
            No hay acciones registradas con este filtro.
            <?php if ($filtros['tabla'] !== '' || $filtros['accion'] !== ''): ?>
                <a href="/admin/audit.php">Ver todas</a>.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data compacta">
                <caption><span class="sr-only">Registro de auditoría</span></caption>
                <thead>
                    <tr>
                        <th scope="col">Fecha</th>
                        <th scope="col">Administrador</th>
                        <th scope="col">Tabla</th>
                        <th scope="col">Acción</th>
                        <th class="num" scope="col">Registro</th>
                        <th scope="col">Detalle</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($registros as $r): ?>
                    <tr>
                        <td><?= fecha($r['fecha_hora'], 'd/m/Y H:i') ?></td>
                        <td>
                            <?= e($r['admin_nombre'] ?? 'Sistema') ?>
                            <?php if (!empty($r['direccion_ip'])): ?>
                                <br><span class="hint mono"><?= e($r['direccion_ip']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="mono"><?= e($r['tabla_afectada']) ?></td>
                        <td><span class="badge badge-LIBRE"><?= e($r['accion']) ?></span></td>
                        <td class="num"><?= $r['registro_id'] !== null ? (int) $r['registro_id'] : '—' ?></td>
                        <td>
                            <?php $antes = recorte($r['datos_anteriores']); ?>
                            <?php $despues = recorte($r['datos_nuevos']); ?>
                            <?php if ($antes === '—' && $despues === '—'): ?>
                                <span class="hint">—</span>
                            <?php else: ?>
                                <details>
                                    <summary class="hint">Ver</summary>
                                    <div class="hint mono" style="margin-top:.35rem;">
                                        <?php if ($despues !== '—'): ?>
                                            <div><strong>Nuevo:</strong> <?= e($despues) ?></div>
                                        <?php endif; ?>
                                        <?php if ($antes !== '—'): ?>
                                            <div><strong>Anterior:</strong> <?= e($antes) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php render_footer(); ?>
