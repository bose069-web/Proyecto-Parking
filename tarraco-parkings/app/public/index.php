<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$parkings = Parking::allActive();

render_header('Reserva de plazas de parking en Tarragona', 'inicio');
?>

<section class="hero">
    <div>
        <p class="eyebrow">Tarraco Parkings · Tarragona</p>
        <h1>Tu plaza, reservada antes de llegar.</h1>
        <p>
            Consulta la disponibilidad de los tres aparcamientos, reserva por horas y
            gestiona la entrada y la salida con la matrícula. El precio se calcula al
            instante: horas por tarifa más un 10 % de gestión.
        </p>
        <div class="hero-actions">
            <?php if (Auth::cliente()): ?>
                <a class="btn btn-light" href="/reservation.php">Reservar una plaza</a>
                <a class="btn btn-accent" href="/dashboard.php">Ir a mi panel</a>
            <?php else: ?>
                <a class="btn btn-light" href="/register.php">Crear cuenta</a>
                <a class="btn btn-accent" href="/login.php">Ya tengo cuenta</a>
            <?php endif; ?>
        </div>
    </div>

    <aside class="hero-card">
        <h2>Estado ahora mismo</h2>
        <dl>
            <?php foreach ($parkings as $parking): ?>
                <div>
                    <dt><?= e($parking['nombre']) ?></dt>
                    <dd><?= plural(Parking::plazasLibres((int) $parking['id_parking']), 'plaza libre', 'plazas libres') ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </aside>
</section>

<div class="page-head">
    <div>
        <h2>Nuestros aparcamientos</h2>
        <p><?= plural(count($parkings), 'recinto', 'recintos') ?> en Tarragona con plazas estándar, adaptadas, eléctricas y de moto.</p>
    </div>
</div>

<div class="grid grid-3">
    <?php foreach ($parkings as $parking): ?>
        <?php
        $idParking = (int) $parking['id_parking'];
        $libres    = Parking::plazasLibres($idParking);
        $ocupacion = Parking::ocupacion($idParking);
        $clase     = $ocupacion['porcentaje'] >= 90 ? 'is-full' : ($ocupacion['porcentaje'] >= 65 ? 'is-high' : '');
        ?>
        <article class="parking-card">
            <h3><?= e($parking['nombre']) ?></h3>
            <p class="parking-meta"><?= e($parking['direccion']) ?> · <?= e($parking['ciudad']) ?></p>
            <p class="parking-meta"><?= e($parking['horario']) ?> · <?= plural((int) $parking['capacidad_total'], 'plaza', 'plazas') ?></p>

            <div class="meter <?= $clase ?>" aria-hidden="true">
                <span style="width: <?= min(100, $ocupacion['porcentaje']) ?>%"></span>
            </div>
            <p class="parking-meta">
                <?= plural($libres, 'libre', 'libres') ?> · <?= plural($ocupacion['ocupadas'], 'ocupada', 'ocupadas') ?>
            </p>

            <div class="parking-foot">
                <span class="badge badge-<?= $libres > 0 ? 'LIBRE' : 'OCUPADA' ?>">
                    <?= $libres > 0 ? 'Disponible' : 'Completo' ?>
                </span>
                <?php if ($libres > 0): ?>
                    <a class="btn btn-ghost btn-small" href="/reservation.php?parking=<?= $idParking ?>">Reservar</a>
                <?php else: ?>
                    <span class="parking-meta">Sin plazas ahora mismo</span>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<section class="card" style="margin-top: 1.6rem;">
    <div class="card-head">
        <h2>Cómo funciona</h2>
    </div>
    <div class="grid grid-3">
        <div>
            <h3>1. Reserva</h3>
            <p class="parking-meta">Elige parking, vehículo y franja horaria. El sistema asigna una plaza libre y congela el precio.</p>
        </div>
        <div>
            <h3>2. Entra</h3>
            <p class="parking-meta">En el control de acceso, teclea la matrícula. Si hay una reserva activa, la entrada queda registrada.</p>
        </div>
        <div>
            <h3>3. Sal</h3>
            <p class="parking-meta">Al salir se compara la hora real con la prevista. Si hay exceso, se cobra aparte y queda en el historial.</p>
        </div>
    </div>
    <div class="note" style="margin-top: 1rem;">
        <strong>Control de acceso simulado.</strong> El proyecto es solo software: no se
        controla ninguna barrera ni ningún lector físico. La matrícula se introduce a mano
        y el sistema decide si el paso está autorizado.
    </div>
</section>

<?php render_footer(); ?>
