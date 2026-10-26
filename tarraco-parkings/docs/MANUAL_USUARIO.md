# Manual de usuario · Tarraco Parkings Sprint 2

Guía de uso de la aplicación. No hace falta saber nada de programación para
seguirla.

---

## 1. Qué es

Tarraco Parkings permite reservar una plaza en tres aparcamientos de
Tarragona, pagar la estancia y registrar la entrada y la salida del vehículo
con la matrícula.

Hay dos tipos de usuario:

- **Cliente:** se registra, da de alta sus vehículos, reserva plazas y consulta
  su historial.
- **Administrador:** gestiona parkings, reservas y clientes desde un panel
  separado.

El pago está simulado y el control de acceso también. No hay barreras físicas:
la aplicación decide si el paso está autorizado y deja constancia.

---

## 2. Entrar por primera vez

### 2.1 Crear una cuenta

1. Abre `https://localhost/`.
2. Pulsa **Crear cuenta**.
3. Rellena nombre, apellidos, correo, teléfono (opcional), DNI o NIE y
   contraseña.
4. La contraseña debe tener al menos 8 caracteres e incluir letras y números.
5. Pulsa **Crear cuenta**. Si todo está bien, vuelves a la pantalla de acceso.

### 2.2 Iniciar sesión

1. Pulsa **Entrar**.
2. Escribe tu correo y tu contraseña.
3. Pulsa **Entrar**.

Si has olvidado la contraseña, no hay recuperación automática: al no haber
servidor de correo, hay que pedir al administrador que restablezca la cuenta.

### 2.3 Cuentas de prueba

| Tipo | Correo | Contraseña |
|---|---|---|
| Cliente | `pere.brau@example.com` | `Client1234` |
| Cliente | `dhruva.mehta@example.com` | `Client1234` |
| Administrador | `admin@tarraco.local` | `Admin1234` |
| Operador | `operador@tarraco.local` | `Oper1234` |

---

## 3. Uso como cliente

### 3.1 Mi panel

Después de entrar ves el resumen de tu cuenta:

- Reservas activas y cuántas están en curso.
- Número de vehículos.
- Total pagado.
- Reservas finalizadas y cuántas tuvieron exceso.
- Tu próxima reserva y tus vehículos.

Desde aquí puedes empezar una reserva nueva o ir a cualquiera de las secciones.

### 3.2 Añadir un vehículo

Antes de reservar necesitas al menos un vehículo.

1. Entra en **Vehículos**.
2. Rellena la matrícula, el tipo (coche, moto, adaptado o carga), marca, modelo
   y color.
3. Pulsa **Añadir vehículo**.

La matrícula se guarda en mayúsculas y sin espacios ni guiones. Si ya existe en
otra cuenta, la aplicación lo rechaza: una matrícula solo puede pertenecer a un
cliente.

Para corregir un dato, pulsa **Editar** en la fila del vehículo. Para retirarlo,
pulsa **Baja**. La baja es lógica: el vehículo deja de estar disponible, pero
sus reservas antiguas se conservan.

### 3.3 Reservar una plaza

1. Entra en **Nueva reserva**.
2. Elige el parking. Verás cuántas plazas libres tiene en ese momento.
3. Elige el vehículo.
4. Indica la entrada y la salida. Por defecto se propone dentro de una hora,
   para tres horas.
5. A la derecha aparece el precio calculado en tiempo real.
6. Pulsa **Confirmar y pagar**.

La aplicación asigna la primera plaza libre compatible con el tipo de vehículo.
El pago se confirma en el acto y la reserva pasa a **ACTIVA**. Si no queda
ninguna plaza del tipo adecuado, la reserva no se crea y se explica el motivo.

**Cómo se calcula el precio:**

- Se cobran las horas completas o la fracción que se haya empezado. Una
  reserva de 8:00 a 10:30 son 3 horas.
- El importe base es `horas × precio por hora`.
- La gestión es el 10 % del importe base.
- El total es la suma de los dos.

Ejemplo en Parc Central, tarifa estándar de 1,80 €/h, 4 horas:

| Concepto | Importe |
|---|---|
| 4 h × 1,80 € | 7,20 € |
| Gestión (10 %) | 0,72 € |
| **Total** | **7,92 €** |

### 3.4 Consultar y cancelar

En **Mis reservas** tienes el historial completo. Puedes filtrar por estado.

- **Ver** abre el detalle: parking, plaza, vehículo, horas previstas y reales,
  movimientos económicos y observaciones.
- **Cancelar** solo aparece si la reserva está pendiente o activa y todavía no
  ha empezado. Al cancelar se reembolsa el pago confirmado y la reserva queda
  como **CANCELADA**.

Una reserva no se borra nunca. Si no llegas a entrar, queda como
**NO_PRESENTADA**.

### 3.5 Entrada y salida

La pantalla **Control de acceso** simula el mostrador del parking. Hay que
tener la sesión iniciada: el simulador escribe movimientos reales. Un cliente
solo puede mover las matrículas de sus propios vehículos; el administrador
puede mover cualquiera.

**Para entrar:**

1. Elige el parking.
2. Teclea la matrícula.
3. Pulsa **Registrar entrada**.

La entrada se autoriza solo si el vehículo existe, tiene una reserva **ACTIVA**
en ese parking y la hora actual está dentro de la franja, con hasta 30 minutos
de margen antes de la entrada prevista.

**Para salir:**

1. Elige el parking.
2. Teclea la matrícula.
3. Pulsa **Registrar salida**.

La salida se autoriza si hay una reserva **EN_CURSO**. Si sales más de 15
minutos después de la hora prevista, se cobra un exceso por horas completas o
fracción, sin el 10 % de gestión. La reserva pasa a **CON_EXCESO** y el cobro
queda como un pago aparte.

Ejemplo: salida prevista a las 12:00, salida real a las 14:20. Con 15 minutos
de gracia son 105 minutos, es decir, 2 horas de exceso. A 1,20 €/h se cobran
2,40 €.

Cada intento, autorizado o no, queda registrado con su motivo. Si la matrícula
no existe, también.

### 3.6 Mi perfil

En **Perfil** puedes cambiar nombre, apellidos, correo y teléfono. En el mismo
panel puedes cambiar la contraseña: hace falta la actual y la nueva, con un
mínimo de 8 caracteres y al menos una letra y un número.

A la derecha se ve el método de pago con el que se cobran tus reservas. Al
crear la cuenta se da de alta uno de prueba, porque sin método no se puede
reservar. El pago está simulado: de la tarjeta solo se guarda una referencia
terminada en cuatro cifras, nunca el número completo.

---

## 4. Uso como administrador

### 4.1 Acceso

1. Abre `https://localhost/admin/login.php`.
2. Entra con `admin@tarraco.local` y `Admin1234`.

El panel de administración es independiente del área de cliente. Una sesión de
cliente no da acceso aquí.

### 4.2 Panel

Muestra reservas activas, entradas previstas para hoy, clientes activos e
ingresos confirmados. Debajo están los tres parkings con su ocupación, las
últimas reservas y los últimos accesos.

El botón **Cerrar reservas caducadas** marca como `NO_PRESENTADA` las reservas
activas que ya deberían haber entrado hace más de 30 minutos y no lo han hecho.
También cancela las reservas que se quedaron sin pagar hace más de 15 minutos.

### 4.3 Parkings

En **Parkings** puedes crear un parking o editar los existentes: nombre,
dirección, ciudad, capacidad, horario, coordenadas, descripción y si está
activo.

Al crear uno se le piden también el **precio por hora**, y el sistema genera en
el acto sus plazas y su tarifa general. Así el parking nuevo se puede reservar
sin tocar la base de datos.

Al editar uno, debajo aparecen tres bloques:

- **Plazas.** Cada una con su código, planta, tipo y estado. El botón
  **Retirar** la pone fuera de servicio: deja de ofrecerse, pero conserva su
  historial y las reservas que ya tenía no se pierden. **Poner en servicio** la
  devuelve. Una plaza con reservas pendientes, activas o en curso no se puede
  retirar, porque el cliente se quedaría sin sitio al llegar.
- **Tarifas.** Las del parking, con la que está en vigor marcada. Una tarifa no
  se edita: se añade otra con fecha posterior. El precio que ya se cobró no
  cambia nunca.
- **Capacidad.** Al ampliarla se añaden plazas. No se puede reducir por debajo
  de las plazas que ya existen: para quitar una, se retira.

### 4.4 Reservas

En **Reservas** puedes filtrar por estado, parking o texto (código, matrícula o
correo).

- **Editar** permite cambiar fechas, plaza y observaciones. El importe se
  recalcula al guardar.
- **Cancelar** pide confirmación, reembolsa el pago y deja la reserva como
  `CANCELADA`.

No hay ninguna opción de borrar. La base de datos rechaza un `DELETE` sobre una
reserva aunque se intente por otra vía.

### 4.5 Clientes

En **Clientes** ves nombre, correo, documento, número de vehículos, reservas,
total pagado, fecha de alta y estado. Puedes buscar por nombre, correo o
documento, y activar o desactivar una cuenta. Desactivar no borra nada.

### 4.6 Auditoría

En **Auditoría** ves las últimas cien acciones del panel: quién, sobre qué
tabla, qué hizo y cuándo, con la dirección IP. Se puede filtrar por tabla y por
acción, y el detalle enseña los valores anteriores y los nuevos.

El operador también puede consultarla, aunque no pueda modificar nada.


---

## 5. Estados de una reserva

| Estado | Significado |
|---|---|
| PENDIENTE_PAGO | Creada, el pago no está confirmado |
| ACTIVA | Pagada y con plaza; autoriza la entrada |
| EN_CURSO | El vehículo ha entrado y está dentro |
| FINALIZADA | Salida dentro del tiempo previsto |
| CON_EXCESO | Salida tardía; el exceso se cobra aparte |
| CANCELADA | Anulada antes de empezar, con reembolso |
| NO_PRESENTADA | Pasada la hora de entrada más 30 minutos sin entrar |

---

## 6. Preguntas frecuentes

**¿Puedo reservar para una hora que ya ha pasado?**
No. La entrada debe ser futura, con 5 minutos de margen técnico.

**¿Puedo reservar dos plazas a la vez?**
Sí, si son franjas distintas o parkings distintos. La misma plaza no se asigna
dos veces en la misma franja.

**¿Qué pasa si llego tarde?**
Tienes 30 minutos de margen para entrar. Pasado ese tiempo, la reserva puede
marcarse como no presentada.

**¿Qué pasa si salgo tarde?**
Se cobra el exceso por horas completas o fracción, con 15 minutos de gracia.

**¿Se guardan mis datos de tarjeta?**
No. El pago está simulado y el método solo guarda un token de ejemplo.

**¿Puedo recuperar una reserva cancelada?**
No. Hay que crear una nueva.

**¿Por qué no me deja cancelar?**
Porque la reserva ya ha empezado o porque está en un estado terminal.

**¿Por qué no veo plazas libres?**
Puede que no queden plazas del tipo compatible con tu vehículo, o que estén
fuera de servicio.

---

## 7. Capturas para la entrega

Las capturas son reales, hechas sobre la aplicación en marcha en la VM. No se
fabrican. Las diecinueve están en `docs/img/` con el nombre
`captura-NN-descripcion.png`, y cada una se ha revisado contra la imagen.

Todas se tomaron el 23/10/2026 con el mismo procedimiento: Chrome dentro de la
VM, el framebuffer capturado con `VBoxManage controlvm screenshotpng`. Las del
panorama de cliente son las mismas escenas que ya existían, rehechas después de
los cambios de color y de texto, para que el documento y la aplicación no se
contradigan.

| # | Qué enseña | Archivo |
|---|---|---|
| 1 | Portada con los tres parkings y la disponibilidad | `captura-01-portada.png` |
| 2 | Inicio de sesión de cliente | `captura-02-login.png` |
| 3 | Panel del cliente con una reserva activa | `captura-03-panel-cliente.png` |
| 4 | Mis reservas con los distintos estados | `captura-04-mis-reservas.png` |
| 5 | Formulario de reserva, sin parking elegido | `captura-05-nueva-reserva.png` |
| 6 | Control de acceso con la matrícula escrita | `captura-06-control-acceso.png` |
| 7 | Portada con la sesión de cliente iniciada | `captura-07-portada-sesion-cliente.png` |
| 8 | Formulario con el precio ya calculado: 3 h, 1,80 €/h, 10 % de gestión | `captura-08-precio-calculado.png` |
| 9 | Entrada autorizada, con la plaza asignada y la reserva en curso | `captura-09-acceso-autorizado.png` |
| 10 | Entrada denegada por matrícula desconocida, con el motivo | `captura-10-acceso-denegado.png` |
| 11 | Salida con dos horas de exceso y su importe | `captura-11-salida-exceso.png` |
| 12 | Panel del administrador con los indicadores del día | `captura-12-panel-admin.png` |
| 13 | Listado de reservas del administrador, con filtros y acciones | `captura-13-reservas-admin.png` |
| 14 | Panel de Grafana con reservas, ingresos, plazas y CPU | `captura-14-grafana.png` |
| 15 | Los cuatro objetivos de Prometheus en `UP` | `captura-15-prometheus.png` |
| 16 | phpMyAdmin con las once tablas del modelo | `captura-16-phpmyadmin.png` |
| 17 | Ficha de parking con sus plazas y sus tarifas | `captura-17-admin-parkings.png` |
| 18 | Traza de auditoría con filtros y detalle | `captura-18-auditoria.png` |
| 19 | Perfil del cliente con el método de pago | `captura-19-perfil.png` |

La captura 14 se tomó con el panel aprovisionado y datos reales de la
aplicación. La 15 demuestra que Prometheus recoge de los cuatro objetivos:
`cadvisor`, `node-exporter`, `prometheus` y `tarraco-app`.

Para repetirlas, el guion está en `Maquinas/_work/sprint2-vm/`, fuera del
repositorio del proyecto: `capturas_sprint2.py` conduce Chrome por CDP y
`_chrome_fresh.sh` lo arranca sin los avisos del navegador.
