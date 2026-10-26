# Guía para enseñar el Sprint 2 en vivo

Recorrido de siete minutos por la aplicación, pensado para hacerlo delante de la
clase con un proyector. Cada paso dice qué se hace y qué se dice mientras se
hace.

---

## 1. Antes de empezar

### Si la VM está encendida

Comprueba que responde:

```bash
curl -sk -o /dev/null -w "%{http_code}\n" https://127.0.0.1:8443/
```

Si devuelve `200`, ya está.

### Si la VM está apagada

En el aula no hay Docker, así que todo va dentro de `sprint2-ubuntu`. Hay que
arrancarla **desde tu sesión**, no desde un agente: `VirtualBox` es setuid y un
proceso con `NoNewPrivs` no puede lanzar máquinas (TRAMPA 61).

```bash
VBoxManage startvm sprint2-ubuntu --type gui
```

Cuando pida la contraseña de sesión: `pere` / `pere1234`.

Los siete contenedores vuelven solos, porque están en `restart: unless-stopped`.

Para comprobarlo, lo más rápido es abrir un terminal **dentro de la ventana de la
VM** y teclear:

```bash
cd /opt/tarraco-parkings && sudo docker compose ps
```

Si prefieres hacerlo desde el portátil por SSH, la clave del SSD no vale tal
cual: está en exFAT y OpenSSH la rechaza por permisos abiertos. Hay que hacer una
copia de trabajo y borrarla al terminar, como dice el AGENTS.md:

```bash
cp Maquinas/_keys/id_ed25519_lab ~/.ssh/id_ed25519_lab_ssd
chmod 600 ~/.ssh/id_ed25519_lab_ssd
ssh -i ~/.ssh/id_ed25519_lab_ssd -p 2222 pere@127.0.0.1 \
  'cd /opt/tarraco-parkings && sudo docker compose ps'
shred -u ~/.ssh/id_ed25519_lab_ssd
```

La copia no se queda en casa: se borra en cuanto acabas.

### Las cinco direcciones

| Qué | Dirección | Credenciales |
|---|---|---|
| Aplicación | `https://127.0.0.1:8443` | `pere.brau@example.com` / `Client1234` |
| Panel de gestión | `https://127.0.0.1:8443/admin/login.php` | `admin@tarraco.local` / `Admin1234` |
| Grafana | `http://127.0.0.1:3000` | `admin` / `tarraco_grafana_2026` |
| Prometheus | `http://127.0.0.1:9090/targets` | sin contraseña |
| phpMyAdmin | `http://127.0.0.1:8081` | `tarraco` / `tarraco_dev_2026` |

Ten las cinco pestañas abiertas antes de empezar. Cambiar de pestaña en directo
estorba menos que teclear direcciones.

**El certificado es autofirmado.** El primer navegador avisará. Hay que aceptar
la excepción una vez, y mejor hacerlo antes de que entre la gente.

### Deja los datos limpios

Si has estado probando, la portada puede tener parkings de prueba que no quieres
enseñar. Para volver a los datos de ejemplo, dentro de la VM:

```bash
cd /opt/tarraco-parkings
sudo docker compose -f docker-compose.monitor.yml down
sudo docker compose down -v
sudo docker compose up -d
sudo docker compose -f docker-compose.monitor.yml up -d
```

Tarda unos cuarenta segundos. Vuelven los tres parkings, los cinco clientes y las
veinte reservas del principio. **Hazlo antes de la demo, nunca durante.**

---

## 2. El recorrido

### Paso 1 · La portada (30 s)

Abre `https://127.0.0.1:8443`.

> «Esto es una aplicación web real, no una maqueta. Tres parkings en Tarragona
> con sus plazas y su disponibilidad en vivo. El número de plazas libres no está
> escrito: se calcula contra la base de datos cada vez que carga la página.»

Enseña que cada tarjeta trae su barra de ocupación y su contador.

### Paso 2 · Cuenta nueva (45 s)

**Crear cuenta** y date de alta con un correo inventado. Por ejemplo
`demo@example.com`, documento `12345678Z`, contraseña `Clave1234`.

> «Me registro delante de vosotros. Y esto es importante: al crear la cuenta se
> crea también su método de pago. Sin él no se puede reservar, y hasta hoy la
> aplicación te dejaba registrado pero sin forma de pagar. Se arregló esta
> semana porque lo encontramos probando el flujo entero, no el de los datos de
> ejemplo.»

### Paso 3 · Vehículo (30 s)

**Vehículos → matrícula `1234ABC`, tipo coche.**

> «La matrícula es única en todo el sistema. Si alguien intenta dar de alta una
> que ya existe, la aplicación lo dice. Y si el vehículo tiene una reserva en
> curso, no se puede dar de baja: se quedaría un coche dentro de un parking sin
> poder salir del sistema.»

### Paso 4 · Reserva con el precio calculado (60 s)

**Nueva reserva.** Elige el parking, el vehículo y una franja de 3 horas.

> «El precio se recalcula mientras cambio las horas. Son horas completas o
> fracción, por la tarifa del parking, más un 10 % de gestión. Y ojo: el importe
> se congela al confirmar. Si mañana sube la tarifa, esta reserva sigue costando
> lo mismo.»

Espera a que aparezca el desglose antes de confirmar. Es el momento de la
pantalla que más convence.

### Paso 5 · Matrícula desconocida (30 s)

**Control de acceso.** Teclea `9999ZZZ` y pulsa **Registrar entrada**.

> «Denegado, y dice por qué: la matrícula no está registrada. Fíjate en que el
> intento queda igualmente en el histórico de la derecha. Un acceso denegado
> también es información: interesa saber quién lo intentó.»

### Paso 6 · Entrada autorizada (45 s)

Teclea tu propia matrícula y pulsa **Registrar entrada**.

> «No hay barrera ni cámara. Lo que hay es la decisión: el sistema mira si este
> vehículo tiene una reserva activa para ahora, y si la tiene, le asigna plaza y
> la pone en curso. El estado cambia en la misma pantalla y queda registrada la
> hora real de entrada.»

### Paso 7 · Salida y exceso (45 s)

Pulsa **Registrar salida** con la misma matrícula.

> «Al salir se cobra el exceso: horas completas de retraso sobre lo previsto, con
> quince minutos de gracia y sin el 10 % de gestión. El importe queda en la
> reserva y se registra un pago aparte del tipo `EXCESO`, para poder auditarlo
> separado del de la reserva.»

Si quieres que salga exceso en directo sin esperar dos horas, antes de la demo
atrasa la hora prevista de esa reserva desde el panel de administración. Si no,
la salida saldrá «dentro del tiempo previsto», que también sirve: enseña el caso
normal y el mensaje es igual de claro.

### Paso 7b · Una reserva que todavía no toca (40 s)

Crea una segunda reserva para dentro de cinco horas, con el mismo vehículo, y
vuelve al control de acceso. Teclea la matrícula y pulsa entrada.

> «Denegado otra vez, pero con otro motivo: la reserva existe, solo que aún no
> toca. Antes de esta semana el sistema decía “no tienes ninguna reserva”, que
> era mentira. Ahora dice a qué hora puedes entrar.»

Este es el detalle que demuestra que se ha probado de verdad y no solo el camino
feliz. Va en este orden a propósito: si el vehículo estuviera dentro del parking,
la negativa sería otra y el mensaje no saldría.

### Paso 8 · Panel de administración (60 s)

Abre `/admin/login.php` y entra con `admin@tarraco.local` / `Admin1234`.

> «Reservas activas, entradas de hoy, clientes e ingresos. Y aquí abajo, por
> parking, cuántas plazas hay ocupadas.»

Luego **Parkings**: crea uno nuevo, `Parking Demo`, capacidad 4, precio 2,25 €.

> «Mira lo que pasa: el parking nace con sus cuatro plazas y su tarifa. Se puede
> reservar en él en el acto. Esto no funcionaba: creaba la ficha con capacidad
> cuatro y cero plazas, y luego la web decía que no quedaban plazas libres. Lo
> arreglamos esta semana.»

Si te queda tiempo, retira una plaza: **Retirar** en una de ellas, y vuelve a la
portada para enseñar que la disponibilidad baja.

### Paso 9 · Auditoría (30 s)

**Auditoría** en el menú del panel.

> «Cada cosa que acabo de hacer como administrador está aquí: quién, qué tabla,
> qué acción, cuándo y desde qué IP. La traza se escribía desde el primer día y
> no había forma de leerla desde la aplicación. Ahora sí, y el operador también
> puede consultarla, que es lo lógico: quien no puede cambiar nada sí debería
> poder comprobar quién cambió qué.»

### Paso 10 · Monitorización (60 s)

**Prometheus** en `/targets`: los cuatro objetivos en `UP`.

> «Cuatro objetivos: la aplicación, Prometheus, el exporter del sistema y el de
> contenedores. Todos en verde y con la última recogida reciente.»

**Grafana**: el panel `Tarraco Parkings · Sprint 2`.

> «Este es el panel con datos reales. Reservas activas, ingresos, errores 5xx,
> peticiones por clase, reservas por estado, plazas libres y el consumo de CPU y
> memoria del contenedor de la aplicación.»

Aquí merece la pena contar lo de cAdvisor:

> «Los dos últimos paneles estuvieron vacíos sin dar ningún error. Prometheus
> decía que el objetivo estaba arriba, pero cAdvisor no veía ni un contenedor:
> hablaba una versión antigua de la API de Docker y no entendía el almacén de
> imágenes. Se arregló subiéndolo de versión. Lo cuento porque un panel vacío
> que parece sano es peor que un error.»

### Paso 11 · Cierre (20 s)

> «Todo esto son contenedores: la aplicación, la base de datos, phpMyAdmin,
> Prometheus, Grafana y dos exporters. Se levanta con `docker compose up -d` y se
> para con `docker compose down`. No hay nada instalado a mano en el sistema.»

---

## 3. Las preguntas que te van a hacer

**¿Esto funciona sin internet?**
Sí. Sin CDN, sin frameworks, sin Bootstrap. El CSS es un archivo propio y el JS
son 127 líneas sin dependencias. Funciona en el aula sin red.

**¿Se puede borrar una reserva?**
No. Ni desde la aplicación ni con un `DELETE` a mano: hay un trigger que lo
rechaza. Se cancelan, y quedan en el histórico.

**¿Y si dos personas reservan la misma plaza a la vez?**
La plaza se bloquea con `FOR UPDATE` dentro de una transacción. La segunda
petición ve la plaza ocupada y recibe otra, o ninguna.

**¿El pago es real?**
No, está simulado y se dice en todas partes. No se guarda ningún número de
tarjeta, solo una referencia. La estructura de `PAGO` sí es la real: tipo,
importe, estado y referencia de transacción.

**¿Cuántas pruebas tenéis?**
Cincuenta automáticas de extremo a extremo contra la aplicación en marcha,
dieciocho de humo dentro de la máquina, veintidós del cálculo de precios, y la
validación del esquema y de las consultas. Y la prueba de extremo a extremo crea
su propia cuenta y su propio parking para poder repetirse.

**¿Esto lo ha hecho una IA?**
Sí, con dirección y revisión. Está explicado en el apartado de uso de IA del
documento de presentación, y las decisiones están en `DECISIONES-SPRINT2.md`.

---

## 4. Si algo falla en directo

| Qué pasa | Qué hacer |
|---|---|
| La página no carga | `docker compose ps` dentro de la VM; si `app` no está arriba, `docker compose up -d` |
| Aviso de certificado | Aceptar la excepción; el certificado es autofirmado |
| «No quedan plazas libres» | El parking elegido está lleno o sus plazas están fuera de servicio: usa otro |
| Grafana no acepta la contraseña | `grep GRAFANA_PASSWORD .env` dentro de la VM. `admin`/`admin` no vale |
| Se ha quedado sin datos | `sudo docker compose down -v && sudo docker compose up -d` vuelve a los datos de ejemplo. Tarda unos treinta segundos |

**Nunca hagas `down -v` delante de la clase** si has creado datos que quieres
enseñar: borra el volumen entero.
