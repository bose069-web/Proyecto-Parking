# Manual técnico · Tarraco Parkings Sprint 2

Documento de despliegue, operación y mantenimiento. Para el modelo de datos,
la referencia completa es [MODELO_LOGICO.md](MODELO_LOGICO.md).

---

## 1. Arquitectura

La aplicación se ejecuta en tres contenedores del stack principal y cuatro
más en el de monitorización (siete en total).

| Contenedor | Imagen | Puerto | Función |
|---|---|---|---|
| `tarraco-app` | `php:8.2-apache` (construida) | 80, 443 | Aplicación PHP y API de métricas |
| `tarraco-db` | `mariadb:10.11` | interno 3306 | Base de datos |
| `tarraco-phpmyadmin` | `phpmyadmin:5.2` | 8080 | Administración de la base de datos |
| `tarraco-prometheus` | `prom/prometheus:v2.54.1` | 9090 | Recolección de métricas |
| `tarraco-grafana` | `grafana/grafana:11.2.0` | 3000 | Paneles |
| `tarraco-node-exporter` | `prom/node-exporter:v1.8.2` | 9100 | Métricas del host |
| `tarraco-cadvisor` | `gcr.io/cadvisor/cadvisor:v0.55.1` | 8082 (interno 8080) | Métricas de contenedores |

El flujo de una petición es siempre el mismo:

```
Navegador → Apache (443) → public/*.php → src/bootstrap.php
                                              ├── config/config.php
                                              ├── config/database.php → PDO → MariaDB
                                              └── templates/header.php + nav.php
```

`public/` es el único directorio expuesto. `config/`, `src/` y `templates/`
quedan fuera del alcance de Apache por la directiva `<Directory>` del
VirtualHost.

---

## 2. Requisitos

- Docker Engine 24 o superior, o Docker Desktop, con Compose v2.
- 2 GB de RAM libres para el stack principal; 1 GB más para monitorización.
- Puertos 80, 443, 8080, 3000, 8082, 9090 y 9100 libres.
- No hace falta PHP, MariaDB ni Node en el host.

Para las comprobaciones sin Docker: PHP 8.2 o superior y Python 3.10 o
superior. Node 20 solo es necesario para regenerar los diagramas.

---

## 3. Despliegue

### 3.1 Preparar el entorno

```bash
cd tarraco-parkings
cp .env.example .env
```

Edita `.env` si cambias puertos o credenciales. En un entorno real, cambia
`DB_PASSWORD`, `DB_ROOT_PASSWORD` y `GRAFANA_PASSWORD`.

### 3.2 Certificado

El contenedor genera el certificado al arrancar si `certs/` está vacío. Para
tenerlo antes:

```bash
bash scripts/gen-certs.sh
```

El certificado cubre `tarraco.local`, `localhost` y `127.0.0.1`.

### 3.3 Arrancar

```bash
docker compose up -d --build
docker compose ps
```

La base de datos tarda unos segundos en aceptar conexiones. El servicio `app`
espera a que el healthcheck de MariaDB pase, así que no hay carrera entre
contenedores.

### 3.4 Resolución de nombres

Añade a `/etc/hosts` (Linux/macOS) o
`C:\Windows\System32\drivers\etc\hosts` (Windows):

```
127.0.0.1 tarraco.local
```

Sin esta línea, `https://tarraco.local` no resuelve. `https://localhost`
funciona igual porque el VirtualHost acepta los dos nombres.

### 3.5 Comprobar

```bash
bash scripts/smoke-test.sh

# Prueba de extremo a extremo desde una máquina cliente distinta:
PYTHONPATH=Maquinas/_pylib python3 tests/e2e_client.py --host https://127.0.0.1:8443
```

Debe terminar con `RESULTADO: n/n pruebas correctas`. Si algo falla, el propio
script dice qué bloque.

---

## 4. Variables de entorno

| Variable | Por defecto | Para qué |
|---|---|---|
| `APP_ENV` | `development` | `development` muestra el detalle de los errores; `production` lo oculta |
| `APP_URL` | `https://tarraco.local` | URL pública |
| `APP_TIMEZONE` | `Europe/Madrid` | Zona horaria de PHP y MariaDB |
| `DB_HOST` | `db` | Nombre del servicio de base de datos |
| `DB_PORT` | `3306` | Puerto |
| `DB_NAME` | `tarraco_parkings` | Base de datos. Debe coincidir con la de `db/init.sql` |
| `DB_USER` | `tarraco` | Usuario de la aplicación |
| `DB_PASSWORD` | vacío | Contraseña de la aplicación |
| `DB_ROOT_PASSWORD` | vacío | Contraseña de root, solo para tareas de administración |
| `HTTP_PORT` | `80` | Puerto HTTP del host |
| `HTTPS_PORT` | `443` | Puerto HTTPS del host |
| `PMA_PORT` | `8080` | Puerto de phpMyAdmin |
| `GRAFANA_USER` | `admin` | Usuario de Grafana |
| `GRAFANA_PASSWORD` | `admin` | Contraseña de Grafana |

`.env` está en `.gitignore`. No se versiona.

---

## 5. Base de datos

### 5.1 Esquema

Once tablas: `CLIENTE`, `VEHICULO`, `METODO_PAGO`, `PARKING`, `PLAZA`,
`TARIFA`, `RESERVA`, `PAGO`, `REGISTRO_ACCESO`, `ADMINISTRADOR` y
`REGISTRO_AUDITORIA`. Quince claves foráneas. El detalle está en
[MODELO_LOGICO.md](MODELO_LOGICO.md).

### 5.2 Inventario y reglas que dependen de él

El parking, sus plazas y su tarifa se crean juntos. `Parking::create()` abre una
transacción y hace las tres cosas; si algo falla no queda nada a medias. La
capacidad del parking y el número de plazas creadas son la misma cifra: al
ampliar la capacidad se añaden plazas, y no se puede reducir por debajo de las
que ya existen.

Una plaza solo se puede poner en `FUERA_SERVICIO` si no tiene reservas
pendientes, activas o en curso. La comprobación está en el modelo y no en la
vista, para que valga también si algún día se llama desde otro sitio.

Una tarifa nueva no edita la anterior: se inserta con una fecha de inicio
posterior y `tarifaVigente()` se queda con la más reciente. Así los importes ya
cobrados no cambian nunca.

### 5.3 Inicialización

`db/init.sql` se ejecuta una sola vez, cuando el volumen de datos está vacío.
Si se cambia el esquema, hay que recrear el volumen:

```bash
docker compose down -v
docker compose up -d
```

`down -v` borra todos los datos. En producción, la vía es una migración
controlada, no este comando.

### 5.4 Copia de seguridad

```bash
bash scripts/backup.sh
```

Genera `backups/tarraco_AAAAMMDD_HHMMSS.sql.gz` con `--single-transaction`,
rutinas y triggers. Restaurar:

```bash
gunzip -c backups/tarraco_20261122_180000.sql.gz \
  | docker compose exec -T db mariadb -u root -p"$DB_ROOT_PASSWORD" tarraco_parkings
```

### 5.5 Datos de prueba

| Tabla | Filas | Contenido |
|---|---|---|
| PARKING | 3 | Parc Central, Port Esportiu, Estació de Trens |
| PLAZA | 36 | 12 por parking, con tipos estándar, adaptada, eléctrica y moto |
| TARIFA | 7 | Una estándar por parking y las especiales de Parc Central |
| CLIENTE | 5 | Cuentas con contraseña `Client1234` |
| VEHICULO | 10 | Dos por cliente |
| METODO_PAGO | 10 | Uno por vehículo; solo tokens |
| RESERVA | 20 | Repartidas por los siete estados |
| PAGO | 21 | Uno por reserva y un exceso |
| REGISTRO_ACCESO | 14 | Entradas, salidas y dos denegaciones |
| ADMINISTRADOR | 2 | `Admin1234` y `Oper1234` |
| REGISTRO_AUDITORIA | 3 | Tres acciones de ejemplo |

---

## 6. Seguridad

- **Contraseñas:** `password_hash()` con bcrypt. Nunca se guarda la contraseña
  en claro ni se escribe en los logs.
- **Sesiones:** cookie `HttpOnly`, `Secure` cuando hay HTTPS, `SameSite=Lax`,
  `use_strict_mode` y regeneración del identificador al iniciar sesión.
- **CSRF:** todos los formularios que modifican datos llevan token y lo
  verifican con `hash_equals`.
- **SQL:** PDO con sentencias preparadas reales (`ATTR_EMULATE_PREPARES` en
  `false`). No se concatenan valores en las consultas.
- **XSS:** toda salida pasa por `e()`, que aplica `htmlspecialchars` con
  `ENT_QUOTES`.
- **Autorización:** las páginas de cliente llaman a `Auth::checkAuth()` y las
  de administración a `Auth::checkAdmin()`. Son dos sesiones separadas.
- **Roles:** `Auth::requireRole()` exige `SUPERADMIN` o `ADMIN` para modificar
  parkings, cancelar reservas y activar o desactivar clientes. Un `OPERADOR`
  solo consulta.
- **Control de acceso:** el simulador exige sesión iniciada, porque escribe en
  `RESERVA`, `PLAZA` y `PAGO`. Un cliente solo puede mover matrículas de su
  cuenta; el administrador, cualquiera.
- **Tarjetas:** no se guarda ningún número. `METODO_PAGO` solo contiene un
  token de pasarela simulado.
- **Cabeceras:** `X-Content-Type-Options`, `X-Frame-Options` y
  `Referrer-Policy`.
- **Directorio raíz:** Apache solo sirve `public/`; el resto está denegado.

---

## 7. Monitorización

### 7.1 Métricas expuestas

`GET https://localhost/metrics.php` devuelve texto en formato Prometheus:

| Métrica | Tipo | Significado |
|---|---|---|
| `tarraco_http_requests_total{class}` | counter | Peticiones por clase 2xx, 3xx, 4xx, 5xx |
| `tarraco_http_requests_all_total` | counter | Total de peticiones |
| `tarraco_http_5xx_total` | counter | Errores de servidor |
| `tarraco_reservas_activas` | gauge | Reservas ACTIVA o EN_CURSO |
| `tarraco_reservas_estado{estado}` | gauge | Reservas por estado |
| `tarraco_plazas_libres{parking}` | gauge | Plazas libres por parking |
| `tarraco_ingresos_confirmados` | gauge | Euros cobrados |

Los contadores HTTP viven en `/tmp/tarraco_http_counters.json`, con bloqueo
`flock`, porque Apache atiende peticiones en paralelo y no hay APCu. Se
reinician cuando se recrea el contenedor, que es el comportamiento esperado de
un contador de Prometheus.

### 7.2 Consultas útiles

```promql
# Peticiones por segundo y por clase
sum by (class) (rate(tarraco_http_requests_total[$__rate_interval]))

# Porcentaje de errores 5xx
sum(rate(tarraco_http_requests_total{class="5xx"}[5m]))
  / sum(rate(tarraco_http_requests_all_total[5m]))

# Reservas activas ahora
tarraco_reservas_activas

# Plazas libres por parking
tarraco_plazas_libres
```

### 7.3 Panel de Grafana

Se aprovisiona solo al arrancar. Si no aparece, comprueba:

```bash
docker compose -f docker-compose.monitor.yml logs grafana | tail -20
```

El panel está en la carpeta «Tarraco Parkings» y se llama «Tarraco Parkings ·
Sprint 2». Enseña reservas activas, ingresos, errores 5xx, peticiones por clase,
reservas por estado, plazas libres por parking, y CPU y memoria del contenedor
de la aplicación.

**Credenciales.** El usuario es `admin` y la contraseña es la de
`GRAFANA_PASSWORD` en el `.env`; en este proyecto, `tarraco_grafana_2026`.
`admin`/`admin` **no** vale, porque el `.env` la cambia y el volumen conserva la
primera que se creó. Si no entras:

```bash
grep GRAFANA_PASSWORD .env
```

**cAdvisor y las métricas por contenedor.** Los dos últimos paneles necesitan
cAdvisor. La versión v0.49.1 no arranca contra el Docker de la VM: su cliente
habla la API 1.41, el daemon exige la 1.44 como mínimo y no sabe leer el
almacén del containerd snapshotter. El contenedor arrancaba igualmente pero sin
detectar ningún contenedor, así que los paneles salían vacíos sin dar error.
Desde la v0.55 los detecta. Para comprobarlo:

```bash
docker compose -f docker-compose.monitor.yml logs cadvisor | grep "docker container factory"
curl -s "http://localhost:9090/api/v1/query?query=count(container_last_seen)"
```

Si `docker container factory` dice `failed`, los paneles de CPU y memoria
estarán vacíos aunque Prometheus marque el objetivo como `UP`.

### 7.4 Auditoría

`REGISTRO_AUDITORIA` guarda una fila por cada acción de escritura del panel:
alta y modificación de parkings, cambios de estado de plaza, tarifas nuevas,
modificación y cancelación de reservas, cambios de estado de clientes, y el
inicio y el cierre de sesión de los administradores.

La tabla la escribe `Audit::log()` desde diez puntos del código y se consulta en
`/admin/audit.php`, con filtros por tabla y acción. La puede ver también el rol
`OPERADOR`.

Cada fila guarda los valores anteriores y los nuevos en dos columnas JSON. No se
guardan contraseñas ni tokens: `Audit::log()` recibe los arrays ya preparados y
ninguna llamada le pasa esos campos.

---

## 8. Operación diaria

```bash
# Ver estado
docker compose ps
docker compose -f docker-compose.monitor.yml ps

# Logs
docker compose logs -f app
docker compose logs -f db

# Reiniciar solo la aplicación
docker compose restart app

# Parar sin borrar datos
docker compose down
docker compose -f docker-compose.monitor.yml down

# Recrear desde cero (borra datos)
docker compose down -v
docker compose up -d --build
```

### Cierre de reservas no presentadas

El administrador tiene el botón «Cerrar reservas caducadas» en el panel. Hace
dos cosas: marca como `NO_PRESENTADA` toda reserva `ACTIVA` cuya entrada
prevista haya pasado hace más de 30 minutos y no tenga entrada real, y cancela
las reservas `PENDIENTE_PAGO` abandonadas hace más de 15 minutos, marcando su
pago como fallido. No se ejecuta solo: es una acción explícita y queda auditada.

---

## 9. Diagnóstico

| Síntoma | Causa probable | Solución |
|---|---|---|
| El navegador avisa del certificado | Es autofirmado | Aceptar la excepción o instalar `certs/tarraco.crt` |
| `tarraco.local` no resuelve | Falta la línea en `hosts` | Añadir `127.0.0.1 tarraco.local` |
| Error 500 al abrir | La base de datos no está lista o faltan credenciales | `docker compose logs app db`; revisar `.env` |
| `SQLSTATE[HY000] [2002]` | `DB_HOST` incorrecto o MariaDB caída | Debe ser `db`; comprobar `docker compose ps` |
| «No quedan plazas libres» en una franja vacía | Todas las plazas del tipo compatible están ocupadas o fuera de servicio | Probar otro parking u otro tipo de vehículo |
| El panel de Grafana está vacío | Prometheus no puede leer `/metrics.php` | Comprobar `https://localhost/metrics.php` y los targets en `:9090/targets` |
| phpMyAdmin no conecta | Contraseña de `.env` distinta de la del volumen | Recrear el volumen o ajustar `.env` |
| Los cambios en `init.sql` no se aplican | El volumen ya existe y el script solo corre al crearlo | `docker compose down -v` |
| Un parking nuevo aparece con «0 libres» | Se creó antes del 23/10/2026, cuando el alta no generaba plazas | Crear otro: el alta actual las genera. O añadir las plazas a mano |
| Entrar en Grafana da «Invalid username or password» | Se está probando `admin`/`admin`; la contraseña la fija `GRAFANA_PASSWORD` | `grep GRAFANA_PASSWORD .env` |
| `cadvisor` en `UP` pero CPU y memoria vacías | cAdvisor no ve los contenedores, aunque el objetivo esté sano | Ver §7.3: `docker container factory` en el log |

Para ver el detalle de un error en desarrollo, `APP_ENV=development` muestra el
mensaje en pantalla. En producción solo va al log.

---

## 10. Verificación

Sin Docker:

```bash
php tests/pricing_test.php
php tests/check_sql_params.php
python3 tests/check_schema.py
python3 tests/check_links.py
node tools/gen-modelo.mjs
```

Con Docker:

```bash
bash scripts/smoke-test.sh

# Prueba de extremo a extremo desde una máquina cliente distinta:
PYTHONPATH=Maquinas/_pylib python3 tests/e2e_client.py --host https://127.0.0.1:8443
```

Resultados esperados, los del 23/10/2026:

| Prueba | Resultado |
|---|---|
| `pricing_test.php` | 22 comprobaciones correctas |
| `check_schema.py` | esquema estructuralmente válido |
| `check_sql_params.php` | 96 consultas, sin marcadores huérfanos |
| `check_links.py` | 85 referencias, todas resuelven |
| `smoke-test.sh` | 18 de 18 |
| `e2e_client.py` | 50 de 50 |

La prueba de extremo a extremo se puede repetir: crea su propia cuenta, su
vehículo y su parking, con datos distintos en cada pasada.

---

## 11. Límites conocidos

- El pago está simulado: no hay pasarela real, solo un registro con referencia.
- Modificar las fechas de una reserva desde el panel recalcula el importe, pero
  no ajusta automáticamente los pagos ya registrados. Queda documentado y es
  una decisión consciente para no reescribir la contabilidad.
- El control de acceso exige sesión iniciada porque escribe en `RESERVA`,
  `PLAZA` y `PAGO`. En producción lo usaría un operador autenticado; aquí lo
  puede usar un cliente para sus propios vehículos y el administrador para
  cualquiera.
- No hay recuperación de contraseña por correo: no hay servidor de correo.
- Las plazas se crean con el parking según su capacidad y se pueden retirar y
  volver a poner en servicio, pero no se pueden añadir de una en una ni cambiar
  el tipo de una plaza desde el panel. Para eso hay que tocar la base.
- La zona horaria no se fija con un desfase literal: MariaDB hereda la del
  contenedor (`TZ=Europe/Madrid`), que aplica el horario de verano, y PHP usa
  la misma. Fijarla a `+01:00` desincronizaba una hora la base de datos de la
  aplicación entre marzo y octubre. Las comprobaciones de negocio usan `NOW()`
  de la base de datos, no el reloj de PHP.
