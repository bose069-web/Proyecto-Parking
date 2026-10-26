# Decisiones del Sprint 2

Este archivo deja por escrito qué se decidió y por qué. Sirve para que la siguiente sesión no vuelva a discutir lo que ya está resuelto y para que el tribunal pueda seguir el rastro.

---

## 1. Las dos indicaciones de los profesores

Texto original de `Projecte/comentariosdelosprofessores.txt`:

> «Tras la presentacion de el sprint 1, los professores comentaron que desechassemos todo lo de hardware. que no intentasemos controlar el hardware usado por los parkings ni su estructura de red. tambien comentaron que debiamos no solo presentar el modelo relacional, tambien las conexiones entidad relacion, es decir, toda la base de datos a nivel logico en la planificacion.»

Se adoptan como requisitos imperativos, por encima de la guía interna `instruccions.txt`.

### 1.1 Fuera el hardware

Se elimina del Sprint 2 todo lo que implique controlar equipos o red:

- La entidad `DISPOSITIVO` del Sprint 1 (lectores de matrícula, controladores de barrera, terminales de pago, switches) **no existe** en el modelo.
- `REGISTRO_ACCESO` pierde `id_dispositivo` y `barrera_abierta`. Quedan la matrícula tecleada, el parking, el momento y la decisión del sistema.
- No hay diagramas de red, racks, servidores ni RAID. El Sprint 1 los tenía en la sección 4 y en `infra_*.svg`; el Sprint 2 no los incluye.
- El control de acceso es una **simulación de software**: un formulario donde se teclea la matrícula y el sistema decide.

Consecuencia práctica: el proyecto se puede desplegar en cualquier máquina con Docker, sin laboratorio de VirtualBox ni equipos físicos.

### 1.2 El modelo lógico completo en la planificación

La planificación del Sprint 2 incluye `docs/MODELO_LOGICO.md`, que no es un listado de tablas:

- 11 entidades con todos sus atributos, tipos, claves y nulabilidad.
- 15 relaciones con cardinalidad mínima y máxima.
- Las 15 claves foráneas que materializan esas relaciones.
- Reglas de integridad, normalización y ciclo de vida de la reserva.
- Dos diagramas: relaciones y atributos.

El DDL de `db/init.sql` es la traducción física de ese documento, no la única fuente.

---

## 2. Decisiones de diseño

| Decisión | Motivo |
|---|---|
| Nombres en español (`CLIENTE`, `VEHICULO`, `RESERVA`) | El Sprint 1 está aprobado con esos nombres. La guía interna los escribe en catalán, pero cambiar el modelo aprobado rompería la continuidad |
| 11 tablas | Son las 10 del Sprint 1 menos `DISPOSITIVO`, más `ADMINISTRADOR` y `REGISTRO_AUDITORIA` |
| `RESERVA.id_tarifa` | La guía lo pide y además deja el precio vinculado a la tarifa aplicada. Los importes siguen congelados |
| `PLAZA.estado` sin `RESERVADA` | La reserva ya dice qué plaza está ocupada en cada franja. Guardar dos verdades acaba en contradicción |
| `RESERVA` con 7 estados | La guía añade `EN_CURS` y `NO_PRESENTADA`, que el Sprint 1 no tenía |
| Borrado lógico de clientes y vehículos | Hay reservas y pagos que los referencian. Un `DELETE` rompería el histórico |
| Triggers de no borrado y de transición | La garantía tiene que estar en la base de datos, no solo en PHP |
| Contraseñas con `password_hash` (bcrypt) | Nunca se guarda la contraseña en claro |
| Métodos de pago con token | No se guarda ningún número de tarjeta; el pago está simulado |
| Control de acceso con sesión obligatoria | Escribe en `RESERVA`, `PLAZA` y `PAGO`. Abierto a anónimos permitía forzar entradas y cobros ajenos. Un cliente solo mueve sus matrículas |
| CSS propio, sin Bootstrap ni CDN | El aula puede no tener red. La guía pide Bootstrap, pero no hay plantilla ni CDN disponible |

---

## 3. Contradicciones de la guía interna

La guía `Projecte/Projecte Sprint2/instruccions.txt` no es el enunciado de Moodle. Se han encontrado estas inconsistencias:

1. **«10 tablas» pero enumera 11.** Cuenta: CLIENT, VEHICLE, PARKING, PLACA, TARIFA, RESERVA, METODE_PAGAMENT, PAGAMENT, REGISTRE_ACCES, ADMINISTRADOR y REGISTRE_AUDITORIA. Son 11. En este proyecto hay 11 y el modelo lo justifica.
2. **Nombres en catalán y columnas distintas del Sprint 1.** La guía dice «usar exactamente estas entidades del Sprint 1 ya aprobado» y a la vez escribe `nom`, `cognoms`, `actiu`. El Sprint 1 aprobado usa `nombre`, `apellidos`, `activo`. Manda el modelo aprobado.
3. **Seis plantillas del SSD que no existen.** PHP-MVC-base, Docker-LAMP, Bootstrap-5-min, Prom-Grafana-Stack, SQL-ddl-standard y README-template no están en el SSD. El proyecto se ha escrito de cero. Queda pendiente comprobar si están en Moodle o en el repositorio del centro.
4. **«UC-01 a UC-22».** El Sprint 1 documenta 31 casos de uso, no 22. No se ha inventado una renumeración; el Sprint 2 se prueba por flujos funcionales.
5. **Estados mezclados y una errata.** La guía escribe `PENDENT_PAGAMENT`, `CANCEL_LADA`, `AMB_EXCES` y `NO_PRESENTADA`. `CANCEL_LADA` es `CANCELADA` con un guion bajo de más. En el proyecto los estados van en español y sin erratas.
6. **`barrera_oberta` en `REGISTRE_ACCES`.** Contradice la indicación de los profesores. Se elimina.
7. **Vencimiento del 22/11/2026 sin confirmar.** La guía lo da como fecha, pero no se ha podido leer Moodle desde este entorno. **No se debe tratar como confirmado** hasta abrirlo en el curso.
8. **Orden de arranque de la monitorización.** La guía propone `docker-compose -f docker-compose.monitor.yml up -d` solo. El archivo de monitorización se une a la red del stack principal, así que primero hay que levantar `docker compose up -d`.

---

## 4. Revisión del 21/10/2026

Se hizo una revisión estática completa del código. Encontró un defecto que habría roto el flujo principal y varias mejoras de seguridad. Esto es lo que se corrigió:

| Hallazgo | Corrección |
|---|---|
| La consulta de plaza libre tenía los parámetros posicionales desordenados: `id_parking` recibía el tipo de plaza y ninguna reserva se habría podido crear | Marcadores con nombre (`:parking`, `:tipo0…n`, `:desde`, `:hasta`). El orden ya no puede descolocarse, y `tests/check_sql_params.php` vigila que no vuelva a pasar |
| El simulador de acceso era público y permitía forzar entradas, salidas y cobros de reservas ajenas | Exige sesión. Un cliente solo mueve sus matrículas; el administrador, cualquiera |
| El rol `OPERADOR` podía cancelar reservas y modificar parkings y clientes | `Auth::requireRole(['SUPERADMIN','ADMIN'])` en todas las acciones de escritura |
| La base de datos fijaba `+01:00` y PHP usaba `Europe/Madrid`: una hora de desfase entre marzo y octubre | MariaDB hereda la zona del contenedor, igual que PHP |
| Un vehículo dado de baja podía reservar y pagar, y luego no podía entrar | `Vehicle::find()` filtra `activo = 1` y la baja se bloquea si hay reservas activas |
| Un vehículo podía tener dos entradas simultáneas en parkings distintos | La entrada se deniega si ya consta `EN_CURSO` |
| El administrador podía asignar una plaza fuera de servicio | Se valida el estado y no se ofrece en el desplegable |
| Los campos de texto no tenían límite y podían provocar errores de base de datos | Validación de longitud en todas las entradas, y contraseñas limitadas a 72 bytes (límite de bcrypt) |

## 5. Lo que se ha verificado y lo que sigue abierto

- **El stack se ha arrancado de verdad en una VM Ubuntu 24.04.5** (`sprint2-ubuntu`, VirtualBox), porque el equipo del aula no tiene Docker ni MariaDB. Dentro de la VM, `scripts/smoke-test.sh` pasa **18/18**. Desde el portátil, contra el puerto reenviado 8443, `tests/e2e_client.py` pasó **37/37** el 21/10/2026 y **41/41** el 23/10/2026, tres veces seguidas: registro de una cuenta nueva, alta de vehículo, login, CSRF, reserva con precio, entrada, salida, aislamiento entre clientes y panel de administración.
- **Las capturas son framebuffer real de la VM.** Hay siete en `docs/img/`; faltan las de Grafana, Prometheus y phpMyAdmin.
- **La fecha de entrega y el identificador de la tarea en Moodle siguen sin confirmar.** Sin sesión del curso no se han podido leer.
- **El Sprint 1 sigue pendiente de la captura de Trello.** Es un asunto distinto y no se ha tocado aquí.

---

## 6. Qué falta para cerrar el Sprint 2

1. Completar las capturas que faltan: Grafana con datos, Prometheus con los targets en `UP` y phpMyAdmin con las tablas.
2. Incorporar las capturas a los manuales y a la presentación.
3. Confirmar en Moodle la fecha, el identificador de tarea y los adjuntos del Sprint 2.
4. Revisar si el profesorado quiere el repositorio Git o un ZIP.
5. Preparar el vídeo de 5 a 10 minutos, si se pide.
