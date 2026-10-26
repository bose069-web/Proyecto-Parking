# Registro de incidencias · Sprint 2

Documento de entrega. Recoge los problemas reales que aparecieron durante la
construcción y las pruebas del Sprint 2, con el síntoma, la causa y la
solución aplicada. No es una lista de buenas prácticas: son cosas que
fallaron de verdad y que costaron tiempo.

**Fechas de las pruebas:** 21 y 23 de octubre de 2026.
**Entorno:** VM Ubuntu 24.04.5 con Docker Compose, y el portátil como cliente
por el puerto reenviado 8443.

---

## 1. Incidencias de la aplicación

### 1.1 La consulta de plaza libre tenía los parámetros desordenados

| | |
|---|---|
| **Síntoma** | Ninguna reserva se podía crear. Siempre respondía «No quedan plazas libres de este tipo». |
| **Causa** | La consulta usaba marcadores posicionales (`?`) y el array de parámetros los pasaba en otro orden: el primer `?` era `id_parking` y recibía el tipo de plaza (`ESTANDAR`, `MOTO`…). La comparación nunca podía coincidir. |
| **Solución** | Se reescribió con **marcadores con nombre** (`:parking`, `:tipo0…n`, `:desde`, `:hasta`). Así el orden de los parámetros deja de importar y el error es imposible de repetir. |
| **Prevención** | `tests/check_sql_params.php` recorre las consultas y avisa si un marcador no tiene su parámetro. |
| **Dónde** | `app/src/Parking.php`, `plazaDisponible()` |

### 1.2 El simulador de acceso era público

| | |
|---|---|
| **Síntoma** | Cualquier visitante sin cuenta podía escribir en `RESERVA`, `PLAZA` y `PAGO`. |
| **Causa** | La página no comprobaba sesión, y el enlace estaba en el menú público. Con una matrícula conocida se podía forzar la entrada o la salida de una reserva ajena y generar cobros de exceso. |
| **Solución** | Exige sesión iniciada. Un cliente solo puede mover matrículas de su propia cuenta; el administrador, cualquiera. |
| **Dónde** | `app/public/access-control.php`, `app/src/AccessControl.php` |

### 1.3 El rol de administrador no se aplicaba

| | |
|---|---|
| **Síntoma** | Un usuario con rol `OPERADOR` podía cancelar reservas, modificar parkings y desactivar clientes. |
| **Causa** | Solo se comprobaba que la cuenta estuviera activa, nunca el campo `rol`. |
| **Solución** | `Auth::requireRole(['SUPERADMIN','ADMIN'])` en todas las acciones de escritura. `OPERADOR` queda en consulta. |
| **Dónde** | `app/src/Auth.php` y las cuatro páginas de `app/public/admin/` |

### 1.4 Una hora de desfase entre PHP y la base de datos

| | |
|---|---|
| **Síntoma** | El margen de 30 minutos de la entrada se convertía en 90, y una reserva podía marcarse como no presentada una hora antes de tiempo. |
| **Causa** | MariaDB fijaba `time_zone = '+01:00'` mientras PHP usaba `Europe/Madrid`, que en horario de verano es +02:00. |
| **Solución** | Se quitó el desfase literal: MariaDB hereda la zona del contenedor (`TZ=Europe/Madrid`), igual que PHP. Además, todas las comparaciones de negocio usan `NOW()` de la base de datos y no el reloj de PHP. |
| **Dónde** | `db/init.sql`, `docker-compose.yml`, `Database::now()` |

### 1.5 Un vehículo dado de baja podía reservar y pagar

| | |
|---|---|
| **Síntoma** | Se creaba la reserva y se cobraba, pero al llegar al parking la entrada se denegaba porque el vehículo ya no estaba activo. |
| **Causa** | `Vehicle::find()` no filtraba por `activo = 1`, mientras que la búsqueda por matrícula sí. |
| **Solución** | `find()` filtra por activo, y la baja se bloquea si el vehículo tiene reservas pendientes o en curso. |
| **Dónde** | `app/src/Vehicle.php` |

### 1.6 Un vehículo podía estar dentro de dos parkings a la vez

| | |
|---|---|
| **Síntoma** | Dos entradas simultáneas con la misma matrícula en parkings distintos. |
| **Causa** | La búsqueda de reserva para entrar solo miraba el parking indicado. |
| **Solución** | Antes de autorizar una entrada se comprueba que el vehículo no tenga ya una reserva `EN_CURSO`. |
| **Dónde** | `app/src/AccessControl.php` |

### 1.7 El administrador podía asignar una plaza fuera de servicio

| | |
|---|---|
| **Síntoma** | El desplegable ofrecía plazas averiadas y el cambio se aceptaba. |
| **Causa** | Solo se comprobaba que la plaza perteneciera al parking. |
| **Solución** | Se valida el estado y las plazas `FUERA_SERVICIO` no aparecen en el desplegable. |
| **Dónde** | `app/src/Reservation.php`, `app/public/admin/reservations.php` |

### 1.8 Textos sin límite de longitud

| | |
|---|---|
| **Síntoma** | Un POST manipulado con un texto largo provocaba un error de base de datos en lugar de un mensaje de validación. |
| **Causa** | Los formularios tenían `maxlength` en el HTML, pero el servidor no comprobaba la longitud. |
| **Solución** | Validación de longitud en todas las entradas y contraseñas limitadas a 72 bytes, que es el límite de bcrypt. |
| **Dónde** | `app/src/User.php`, `Vehicle.php`, `Parking.php`, `Reservation.php` |

### 1.9 cAdvisor y phpMyAdmin se peleaban por el puerto 8080

| | |
|---|---|
| **Síntoma** | El contenedor de métricas arrancaba y moría; Prometheus lo marcaba `down` con un error de resolución de nombre. |
| **Causa** | Los dos publicaban el 8080 del host. |
| **Solución** | cAdvisor pasa al 8082. |
| **Dónde** | `docker-compose.monitor.yml` |

### 1.10 El desplegable de plazas del administrador salía vacío

| | |
|---|---|
| **Síntoma** | Al editar una reserva, el desplegable de plazas mostraba entradas en blanco: no se podía saber qué plaza se estaba eligiendo. |
| **Causa** | La etiqueta `<option>` no cerraba el atributo `selected` con un `>`: el código de la plaza y su estado quedaban dentro de la etiqueta como atributos inventados, y el navegador los descartaba. |
| **Solución** | Se cerró la etiqueta antes del texto: `<option value="…" selected>` y el contenido en la línea siguiente. |
| **Prevención** | Una comprobación sobre el HTML generado detecta opciones sin texto; se pasó sobre todas las vistas. |
| **Dónde** | `app/public/admin/reservations.php` |

### 1.11 La tarifa dejaba de aplicarse el último día de vigencia

| | |
|---|---|
| **Síntoma** | Una tarifa con `fecha_fin_vigencia` igual al día de la reserva no se encontraba a partir de las 00:00 de ese día. |
| **Causa** | La columna es `DATE` y se comparaba con un `DATETIME` completo: `2026-11-30 >= '2026-11-30 10:00:00'` es falso. |
| **Solución** | La comparación se hace contra `DATE(:fecha)`, de modo que el último día cuenta entero. |
| **Dónde** | `app/src/Parking.php`, `tarifaVigente()` |

### 1.12 Un cliente nuevo no podía reservar nunca

| | |
|---|---|
| **Síntoma** | Al registrarse y confirmar la primera reserva: «Antes de reservar necesitas un método de pago activo». No había ninguna pantalla para darlo de alta. |
| **Causa** | `User::register()` creaba la cuenta en `CLIENTE` y nada más. Los cinco clientes de ejemplo tenían su método porque venía en `init.sql`, así que el fallo no se veía en las pruebas con datos semilla. |
| **Solución** | El alta crea también un método `TARJETA` simulado, dentro de la misma transacción: o quedan los dos registros, o no queda ninguno. |
| **Prevención** | `tests/e2e_client.py` ya no usa el cliente de ejemplo: se registra, da de alta su vehículo y reserva. Así el camino del usuario nuevo se prueba en cada pasada. |
| **Dónde** | `app/src/User.php`, `register()` |

### 1.13 El estado de la reserva no cambiaba en pantalla tras la entrada

| | |
|---|---|
| **Síntoma** | Al autorizar una entrada, el aviso decía «Plaza A-03 asignada» pero justo debajo la reserva seguía apareciendo como `ACTIVA`, cuando en la base ya era `EN_CURSO`. Lo mismo al registrar la salida con exceso. |
| **Causa** | `AccessControl::entrada()` y `salida()` devolvían la fila leída **antes** del `UPDATE`. |
| **Solución** | Después de actualizar se relee la reserva y es esa la que se devuelve a la vista. |
| **Dónde** | `app/src/AccessControl.php` |

### 1.14 El aviso de acceso repetía la misma frase dos veces

| | |
|---|---|
| **Síntoma** | «Acceso denegado. Acceso denegado: la matrícula no está registrada.» |
| **Causa** | La vista escribe el encabezado «Acceso denegado» y el mensaje de `AccessControl` volvía a empezar por las mismas palabras. |
| **Solución** | Los mensajes del modelo dicen solo el motivo («La matrícula no está registrada») y la vista pone el encabezado. |
| **Dónde** | `app/src/AccessControl.php`, `app/public/access-control.php` |

### 1.15 Los estados se mostraban con el nombre del ENUM

| | |
|---|---|
| **Síntoma** | La interfaz enseñaba `PENDIENTE_PAGO`, `EN_CURSO` y `CON_EXCESO` en crudo, en mayúsculas y con guion bajo. En el panel de Grafana, `PENDIENTE_PAGO` ni siquiera cabía y salía cortado. |
| **Causa** | El valor de la base se imprimía directamente. |
| **Solución** | `etiqueta_estado()` traduce el ENUM a castellano y se usa en todas las insignias y filtros. En Grafana se hace lo mismo con `label_replace` en la consulta. |
| **Dónde** | `app/src/bootstrap.php`, `app/public/*.php`, `monitor/grafana/dashboards/tarraco.json` |

---

### 1.16 Un parking creado desde el panel no servía para reservar

| | |
|---|---|
| **Síntoma** | Se creaba el parking en el panel, aparecía en el desplegable del cliente con «0 libres», y cualquier intento de reservar respondía «No quedan plazas libres de este tipo en esa franja». |
| **Causa** | `Parking::create()` insertaba solo la fila de `PARKING`, con su capacidad, y nada más. No generaba ni una plaza ni la tarifa. El contador de plazas libres cuenta filas de `PLAZA`, así que daba cero, y sin tarifa tampoco había precio. Los tres parkings de ejemplo funcionaban porque su inventario viene en `init.sql`. |
| **Solución** | El alta crea el parking, sus plazas y su tarifa general en una sola transacción, y el formulario pide el precio por hora. El número de plazas y el de la capacidad ya no pueden separarse. |
| **Prevención** | `tests/e2e_client.py` crea un parking y comprueba que aparece con sus plazas, que cotiza con su tarifa y que después se le puede retirar una plaza. |
| **Dónde** | `app/src/Parking.php`, `app/public/admin/parkings.php` |

### 1.17 Las plazas fuera de servicio no se podían poner desde ninguna pantalla

| | |
|---|---|
| **Síntoma** | El modelo define el estado `FUERA_SERVICIO` y el código lo valida en tres sitios, pero no había forma de llegar a él sin escribir SQL a mano. |
| **Causa** | La pantalla de parkings decía, literalmente, que «las plazas se gestionan en la base de datos». |
| **Solución** | Botón por plaza para retirarla y volver a ponerla en servicio. La aplicación rechaza retirar una plaza con reservas pendientes, activas o en curso, porque el cliente se quedaría sin sitio al llegar. |
| **Dónde** | `app/src/Parking.php`, `app/public/admin/parkings.php` |

### 1.18 Las tarifas no existían en la interfaz

| | |
|---|---|
| **Síntoma** | `TARIFA` tiene vigencia por fechas y el código la respeta, pero el administrador no podía consultarla ni añadir una. |
| **Solución** | La ficha del parking lista sus tarifas, marca cuál está en vigor y permite añadir una nueva. Una tarifa no se edita: se añade otra con fecha posterior, para que el precio ya cobrado no cambie hacia atrás. |
| **Dónde** | `app/src/Parking.php`, `app/public/admin/parkings.php` |

### 1.19 La auditoría se escribía, pero no se podía leer

| | |
|---|---|
| **Síntoma** | `REGISTRO_AUDITORIA` recibía una fila por cada acción del administrador desde diez puntos distintos del código. Ninguna página la mostraba, y el README prometía «auditoría» entre lo que el panel ofrece. |
| **Solución** | Página `/admin/audit.php` con las 100 últimas acciones, filtros por tabla y por acción, y el detalle de los valores anteriores y nuevos. La puede consultar también el `OPERADOR`, que no puede modificar: que la traza sea legible por quien no escribe es lo que la hace útil. |
| **Detalle** | La consulta ordena por fecha y luego por identificador. Ordenar solo por identificador daba un orden falso cuando los datos de ejemplo insertan filas con fechas anteriores. |
| **Dónde** | `app/src/Audit.php`, `app/public/admin/audit.php` |

### 1.20 El mensaje de acceso negado decía algo que no era cierto

| | |
|---|---|
| **Síntoma** | Un cliente con una reserva pagada para dentro de cinco horas tecleaba su matrícula y recibía «No hay ninguna reserva activa para este vehículo en este parking». La reserva existía. |
| **Causa** | Cuando no se encontraba una reserva con la franja ya abierta, se respondía siempre con el mismo texto, sin distinguir entre «no hay reserva», «el pago no está confirmado» y «todavía no toca». |
| **Solución** | Se busca también una reserva activa cuya franja no ha empezado y se responde con las horas: «Tu reserva empieza a las 23:09; podrás entrar a partir de las 22:39». |
| **Dónde** | `app/src/AccessControl.php` |

### 1.21 Los avisos no concordaban en número

| | |
|---|---|
| **Síntoma** | «1 pagos pendientes caducados». |
| **Causa** | El texto se componía concatenando el número con un plural fijo, en once sitios distintos. |
| **Solución** | Una función `plural()` y su uso en todos los contadores de la interfaz: plazas, reservas, clientes, recintos, registros. |
| **Dónde** | `app/src/bootstrap.php` y las vistas |

## 2. Incidencias del entorno de pruebas

### 2.1 La instalación desatendida de Ubuntu Server no se aplicaba

| | |
|---|---|
| **Síntoma** | La VM arrancaba en el menú de idioma del instalador. Había que instalarlo todo a mano. |
| **Causa** | `VBoxManage unattended install` parchea `/boot/grub/grub.cfg` dentro de una ISO auxiliar, pero el arranque de esa ISO no usaba ese `grub.cfg`. El `user-data` con `autoinstall` nunca se leyó. |
| **Solución** | Se cambió de estrategia: **imagen cloud de Ubuntu** (`noble-server-cloudimg-amd64.vmdk`), que arranca ya con cloud-init y no tiene instalador interactivo. El seed se genera con `make_seed_cloud.py` en formato `#cloud-config`. |
| **Dónde** | `Maquinas/_tools/crea_vm_sprint2.sh`, `Maquinas/_work/sprint2-vm/make_seed_cloud.py` |

### 2.2 La interfaz de red quedaba caída tras instalar el escritorio

| | |
|---|---|
| **Síntoma** | `enp0s3` aparecía `DOWN`, sin IP, y `netplan apply` terminaba con error. |
| **Causa** | Netplan intentaba arrancar `NetworkManager`, que no está instalado en la imagen cloud, y la interfaz se quedaba sin gestión. |
| **Solución** | Un archivo propio `/etc/netplan/99-tarraco.yaml` con `renderer: networkd`, más un servicio `tarraco-linkup.service` que hace `ip link set enp0s3 up` en cada arranque. |
| **Dónde** | `Maquinas/_work/sprint2-vm/provision_vm.sh` |

### 2.3 `tarraco.local` dejaba de resolver tras reiniciar

| | |
|---|---|
| **Síntoma** | El navegador daba `NXDOMAIN` después de cada reinicio de la VM. |
| **Causa** | Cloud-init tenía `manage_etc_hosts: true` y reescribía `/etc/hosts` en cada arranque, borrando la línea añadida a mano. |
| **Solución** | La línea se añade también a la plantilla `/etc/cloud/templates/hosts.debian.tmpl` y se pone `manage_etc_hosts: false`. |
| **Dónde** | `provision_vm.sh` |

### 2.4 Chrome rechaza el certificado autofirmado

| | |
|---|---|
| **Síntoma** | `ERR_CERT_AUTHORITY_INVALID`, aunque el certificado estaba en el almacén del sistema (`update-ca-certificates`) y en el de NSS (`certutil`). |
| **Causa** | Chrome no usa el almacén del sistema para validar esta CA en Linux. |
| **Solución** | Excepción **acotada a ese certificado**, por su clave pública: `--ignore-certificate-errors-spki-list=<sha256>`, junto a `--test-type` para que no aparezca el aviso de *flag* no soportado. No se desactiva la verificación TLS de forma global. |
| **Dónde** | `provision_vm.sh`, autostart del escritorio |

### 2.5 Un `VBoxSVC` arrancado desde un sandbox no puede lanzar VMs

| | |
|---|---|
| **Síntoma** | «Error -10 in SUPR3HardenedMain!» al arrancar cualquier VM. |
| **Causa** | El proceso tenía `NoNewPrivs: 1`, y con esa bandera los binarios setuid (`VBoxHeadless`, `VirtualBoxVM`) no pueden tomar el privilegio de root. |
| **Solución** | El script de creación comprueba `NoNewPrivs` del `VBoxSVC` y se niega a continuar si viene de un sandbox. La VM se crea desde una sesión normal, y a partir de ahí el agente trabaja por SSH sin problemas. |
| **Dónde** | `Maquinas/_tools/crea_vm_sprint2.sh` |

### 2.6 El contenedor podía morir al generar el certificado en un bind mount de Windows

| | |
|---|---|
| **Síntoma** | En Docker Desktop sobre Windows, con el proyecto en una unidad exFAT, el contenedor `app` podía terminar nada más arrancar. |
| **Causa** | El `entrypoint` hace `chmod 600` sobre la clave del certificado, dentro del bind mount de `certs/`. En exFAT y en algunos montajes de Docker Desktop el `chmod` no está permitido; con `set -e`, el fallo mataba el contenedor. |
| **Solución** | El `chmod` es best-effort (`2>/dev/null || true`). Apache lee la clave como root, así que el arranque no depende de esos permisos. |
| **Dónde** | `app/docker/entrypoint.sh` |

### 2.7 cAdvisor arrancaba pero no veía ningún contenedor

| | |
|---|---|
| **Síntoma** | Prometheus marcaba `cadvisor` como `UP` y el panel de Grafana enseñaba reservas, ingresos y peticiones, pero los paneles de CPU y memoria del contenedor salían vacíos, sin ningún error a la vista. |
| **Causa** | Dos problemas encadenados. El cliente de Docker que lleva cAdvisor v0.49.1 habla la API 1.41 y el daemon de la VM exige la 1.44 como mínimo, así que la fábrica de Docker no se registraba. Además, este Docker usa el almacén de imágenes de containerd y cAdvisor buscaba las capas en `/var/lib/docker/image/overlayfs`, que no existe. El contenedor seguía arrancando y exportaba solo los cgroups del host, de ahí que el objetivo pareciera sano. |
| **Solución** | cAdvisor pasa a la v0.55.1, que soporta esa API y ese almacén. Con ella aparecen los siete contenedores y la etiqueta `com_docker_compose_service`, que es la que usan las consultas del panel. |
| **Prevención** | El manual técnico explica cómo comprobarlo (`docker container factory` en el log) porque `UP` no basta como señal. |
| **Dónde** | `docker-compose.monitor.yml` |

---

## 3. Lo que se verificó de verdad

No todo lo que hay en el proyecto se ha podido ejecutar, y conviene decirlo con
precisión.

| Prueba | Dónde | Resultado |
|---|---|---|
| `scripts/smoke-test.sh` | Dentro de la VM | **18/18** |
| `tests/e2e_client.py` | Portátil → VM por HTTPS (8443) | **50/50**, varias pasadas seguidas |
| `tests/pricing_test.php` | Sin base de datos | **22/22** |
| `tests/check_schema.py` | Sobre `db/init.sql` | 11 tablas, 15 FK, sin errores |
| `tests/check_sql_params.php` | Sobre `app/src/*.php` | 96 consultas, sin marcadores huérfanos |
| `tests/check_links.py` | Sobre la app y la documentación | 85 referencias, todas resuelven |

**La prueba de extremo a extremo es la que demuestra el lado cliente.** Se
ejecuta desde el portátil, no desde la VM, y contra `https://<vm>:8443`. Cubre
registro de una cuenta nueva, alta de vehículo, login, rechazo de contraseña
incorrecta, CSRF, reserva con cálculo de precio, rechazo de fechas pasadas,
entrada autorizada, matrícula desconocida denegada, salida, aislamiento entre
dos clientes, acceso al panel de administración y métricas.

Además del flujo del cliente, comprueba el alta de un parking desde el panel con sus
plazas y su tarifa, la retirada de una plaza, la auditoría y el aislamiento entre
clientes. El 23/10/2026 la prueba pasa a crear su propia cuenta y su propio vehículo, con
matrícula distinta en cada ejecución. Antes reutilizaba el cliente de ejemplo y
la matrícula `1234JKL`, que en los datos semilla ya consta dentro de un parking:
la segunda pasada encontraba la reserva `EN_CURSO` y la entrada se denegaba. El
resultado era una prueba que solo funcionaba una vez, y así no sirve para
comprobar una corrección. Ahora se puede repetir.

**No se ha probado:** el envío real de correo (no hay servidor de correo), la
pasarela de pago real (está simulada a propósito) y el comportamiento con dos
máquinas en redes separadas.

**Las capturas.** Las dieciséis de `docs/img/` son el framebuffer de la VM y se
han revisado una a una contra la imagen. La del panel de Grafana se tomó con el
panel aprovisionado y datos reales, y la de Prometheus con los cuatro objetivos
en `UP`. Una captura de la pantalla de acceso no demuestra monitorización, así
que el guion rechaza guardar si la URL es la de login; el 23/10/2026 evitó así
que se colara una imagen de «Welcome to Grafana» como si fuera el panel.

---

## 4. Cómo reproducir las pruebas

```bash
# Dentro de la VM, con el stack levantado
bash /opt/tarraco-parkings/scripts/smoke-test.sh

# Desde el portátil, contra el puerto reenviado
PYTHONPATH=Maquinas/_pylib python3 \
  "Projecte/Projecte Sprint2/tarraco-parkings/tests/e2e_client.py" \
  --host https://127.0.0.1:8443
```

Los dos devuelven código de salida 0 si todo pasa.
