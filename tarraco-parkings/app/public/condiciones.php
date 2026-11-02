<?php
declare(strict_types=1);

/**
 * Condiciones de la reserva. Página pública: se enlaza desde el formulario y
 * desde el resguardo PDF, y hay que poder leerla antes de registrarse.
 */
require_once __DIR__ . '/../src/bootstrap.php';

render_header('Condiciones de la reserva', 'reservas');
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Información legal</p>
        <h1>Condiciones de la reserva</h1>
        <p>Lo que aceptas al confirmar una plaza. Sin sorpresas después.</p>
    </div>
</div>

<div class="detail-grid">
    <section class="card">
        <div class="card-head"><h2>1. Qué contrata la reserva</h2></div>
        <p>La reserva da derecho a ocupar una plaza concreta en un parking, entre la hora
            de entrada y la hora de salida indicadas. La plaza se asigna al confirmar y queda
            vinculada a la matrícula del vehículo elegido.</p>

        <div class="card-head" style="margin-top:1.4rem;"><h2>2. Precio</h2></div>
        <p>Se facturan las horas completas o la fracción empezada, con un mínimo de una hora.
            El importe base es el número de horas por la tarifa vigente del parking. A ese
            importe se le suma un <strong>10 % de gastos de gestión</strong>. El total se
            congela al crear la reserva: una subida posterior de tarifa no cambia lo que ya
            has pagado.</p>

        <div class="card-head" style="margin-top:1.4rem;"><h2>3. Entrada</h2></div>
        <p>Puedes entrar desde <strong>30 minutos antes</strong> de la hora prevista y hasta
            30 minutos después. Pasado ese margen, la reserva puede marcarse como
            <em>no presentada</em> y la plaza vuelve a quedar libre.</p>

        <div class="card-head" style="margin-top:1.4rem;"><h2>4. Salida y exceso</h2></div>
        <p>Si sales más de <strong>15 minutos</strong> después de la hora prevista, se cobra
            el tiempo extra por horas completas o fracción, sin el 10 % de gestión, con el
            mismo método de pago de la reserva. El importe queda registrado como un cobro
            aparte.</p>

        <div class="card-head" style="margin-top:1.4rem;"><h2>5. Cancelación</h2></div>
        <p>Puedes cancelar sin coste mientras la reserva no haya empezado. Se reembolsa el
            importe pagado y la reserva queda como <em>cancelada</em>. Una reserva no se
            borra nunca: el histórico se conserva para que las cuentas cuadren.</p>

        <div class="card-head" style="margin-top:1.4rem;"><h2>6. Datos personales</h2></div>
        <p>Se guardan los datos necesarios para gestionar la reserva: nombre, documento,
            correo, teléfono y matrícula. No se guarda ningún número de tarjeta: el método de
            pago solo contiene una referencia de pasarela simulada. Este es un entorno de
            demostración académica.</p>
    </section>

    <aside>
        <div class="note">
            <strong>Aceptación.</strong> La casilla del formulario deja constancia con fecha
            y hora en la propia reserva. No es un texto decorativo: si no se marca, el sistema
            no crea la reserva.
        </div>
        <div class="card" style="margin-top:1rem;">
            <p class="parking-meta">Puedes volver al formulario cuando quieras.</p>
            <a class="btn btn-primary btn-block" href="/reservation.php">Volver a la reserva</a>
        </div>
    </aside>
</div>
<?php render_footer(); ?>
