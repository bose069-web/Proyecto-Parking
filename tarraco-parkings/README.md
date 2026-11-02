# Tarraco Parkings · Sprint 2

Aplicación web de reserva de plazas de aparcamiento en Tarragona. Proyecto de
**ASIX2 0379, Grupo 6**, Sprint 2.

El proyecto es **solo software**. No se modela ni se controla hardware de los
aparcamientos: ni lectores de matrícula, ni barreras, ni estructura de red. El
control de acceso es una simulación que se maneja desde la propia aplicación.

---

## Arranque rápido

Requisitos: Docker Engine o Docker Desktop con Compose v2. Nada más.

```bash
cd tarraco-parkings
cp .env.example .env          # cambia las contraseñas si vas a publicarlo
docker compose up -d --build
```

La primera vez tarda un par de minutos: descarga las imágenes, crea la base de
datos con las 11 tablas y carga los datos de prueba.

| Servicio | URL | Credenciales |
|---|---|---|
| Aplicación | https://localhost/ | `pere.brau@example.com` / `Client1234` |
| Panel de gestión | https://localhost/admin/login.php | `admin@tarraco.local` / `Admin1234` |
| phpMyAdmin | http://localhost:8080/ | usuario `tarraco`, contraseña de `.env` |
| Prometheus | http://localhost:9090/ | sin autenticación |
| Grafana | http://localhost:3000/ | `admin` / la de `.env` |

El certificado es autofirmado. El navegador avisará la primera vez: es lo
esperado. Se puede aceptar la excepción o confiar en él:

```bash
# Linux
sudo cp certs/tarraco.crt /usr/local/share/ca-certificates/tarraco.crt
sudo update-ca-certificates

# Windows: doble clic en certs\tarraco.crt > Instalar certificado > Equipo local
```

Si `tarraco.local` no resuelve, añade esta línea al archivo `hosts`:

```
127.0.0.1 tarraco.local
```

---

## Monitorización

El stack de monitorización es un segundo archivo de composición. Se une a la
red del primero, así que **el orden importa**:

```bash
docker compose up -d
docker compose -f docker-compose.monitor.yml up -d
```

Grafana se aprovisiona sola con el datasource de Prometheus y el panel
«Tarraco Parkings · Sprint 2»: reservas activas, reservas por estado, plazas
libres, peticiones HTTP por clase, errores 5xx, ingresos, CPU y memoria.

La aplicación expone sus métricas en `https://localhost/metrics.php`. Los
contadores HTTP se escriben en el arranque de cada petición y las métricas de
negocio se leen de MariaDB en el momento.

---

## Estructura

```
tarraco-parkings/
├── docker-compose.yml            app + MariaDB + phpMyAdmin
├── docker-compose.monitor.yml    Prometheus + Grafana + node-exporter + cAdvisor
├── .env.example                  plantilla de variables
├── certs/                        certificado autofirmado (se genera solo)
├── db/init.sql                   esquema completo + datos de prueba + triggers
├── app/
│   ├── Dockerfile                PHP 8.2 + Apache
│   ├── docker/                   VirtualHost, php.ini, entrypoint
│   ├── config/                   configuración y conexión
│   ├── src/                      clases del dominio
│   ├── templates/                cabecera, navegación y pie
│   └── public/                   DocumentRoot
├── monitor/
│   ├── prometheus/prometheus.yml
│   └── grafana/provisioning/ + dashboards/
├── docs/
│   ├── MODELO_LOGICO.md              el modelo completo, con relaciones y cardinalidades
│   ├── DECISIONES-SPRINT2.md         por qué cada cosa es como es
│   ├── INCIDENCIAS.md                los problemas reales y cómo se resolvieron
│   ├── MANUAL_TECNICO.md
│   ├── MANUAL_USUARIO.md
│   ├── DOCUMENTO-PRESENTACION.pdf    el documento que acompaña a la presentación
│   ├── PRESENTACION.pptx / .pdf      las 18 diapositivas
│   └── VIDEO-DEMO.mp4                el recorrido grabado contra la aplicación
├── scripts/                      certificados, copia de seguridad, pruebas
├── tests/                        validador del esquema y cálculo de precios
└── tools/                        diagramas E/R, presentación, vídeo, documento y ZIP
```

---

## El paquete de entrega

El ZIP se genera con un guion, no a mano, para que salga siempre igual y para
que lo que no debe entregarse no se cuele:

```bash
python3 tools/empaqueta.py          # escribe ../tarraco-parkings-sprint2.zip
```

Dentro del ZIP todo cuelga de `tarraco-parkings/`, así que al descomprimirlo
aparece una carpeta y no veinte archivos sueltos. Quedan fuera, a propósito:

- **`.env`**, que lleva credenciales aunque sean de laboratorio. Se entrega
  `.env.example`, que es la plantilla.
- **Los certificados**, que el contenedor genera al arrancar.
- **La caché de Python y las copias de `backups/`**, que son estado de una
  máquina concreta.

## Cómo se comprueba

Sin Docker, lo que se puede comprobar de verdad:

```bash
php tests/pricing_test.php          # 22 comprobaciones del cálculo económico
php tests/check_sql_params.php      # marcadores SQL y parámetros cuadran
python3 tests/check_schema.py       # 11 tablas, FKs e índices coherentes
python3 tests/check_links.py        # enlaces y assets internos
python3 tools/verifica-sintaxis.py  # estructura de los PHP, si no hay php -l
node tools/gen-modelo.mjs           # regenera los diagramas del modelo
```

`verifica-sintaxis.py` no sustituye a `php -l`: comprueba que paréntesis,
corchetes y llaves cierran en orden y que no hay cadenas sin terminar, que es lo
que sí se puede mirar sin intérprete. `gen-modelo.mjs` reutiliza el motor
Graphviz del Sprint 1, así que necesita el árbol completo del SSD; los diagramas
ya están en `docs/img/` y no hace falta ejecutarlo para arrancar la aplicación.

Con Docker y el stack levantado:

```bash
bash scripts/smoke-test.sh          # HTTPS, base de datos, triggers y monitorización

# Desde otra máquina (el cliente), contra el puerto publicado:
python3 tests/e2e_client.py --host https://<servidor>:8443
```

El `smoke-test.sh` comprueba, entre otras cosas, que los importes de las 20
reservas cuadran con su tarifa y que el trigger de borrado rechaza un `DELETE`.

---

## Criterios de aceptación

Las filas marcadas «verificado» o «probado» se midieron en la pasada completa sobre la
VM. Después de esa pasada se corrigieron cuatro cosas en el código
(`access-control.php`, `reservation-pdf.php`, el solape de reservas por vehículo y la
lectura de `business.*` en `config.php`), y **las dos suites se volvieron a ejecutar el
29/10/2026 con la base recreada desde cero**: `smoke-test.sh` 19/19 y
`e2e_client.py` 53/53. El detalle está en `docs/VERIFICACION-SPRINT2.md`, §4.1.

| Criterio | Dónde está | Estado |
|---|---|---|
| `docker compose up -d` levanta todo | `docker-compose.yml` + `docker-compose.monitor.yml` | Verificado el 29/10/2026 en la VM: 7 servicios, `smoke-test.sh` 19/19 |
| HTTPS con certificado autofirmado | `app/docker/apache-ssl.conf` + entrypoint | Verificado dentro de la VM |
| Registro, login y vehículos | `register.php`, `login.php`, `vehicles.php` | Probado en la e2e: 53/53 el 29/10/2026 |
| Reserva con precio automático + 10 % | `reservation.php`, `src/Reservation.php` | Implementado, probado en cálculo y en la e2e |
| Admin ve, modifica y cancela sin borrar | `admin/reservations.php` + triggers | Implementado; el trigger de borrado se prueba en el smoke test |
| Simulación de entrada y salida | `access-control.php`, con sesión obligatoria | Implementado y probado en la e2e |
| Detección y cobro de exceso | `src/AccessControl.php` | Implementado y probado en la e2e |
| Prometheus y Grafana con panel | `monitor/` | Verificado dentro de la VM |
| phpMyAdmin en 8080 | `docker-compose.yml` | Verificado dentro de la VM |
| Alta de parking completa desde el panel | `admin/parkings.php`, `Parking::create()` | Crea el parking con sus plazas y su tarifa; probado en la e2e |
| Gestión de plazas y tarifas | `admin/parkings.php` | Retirada y vuelta a servicio, y tarifas con vigencia |
| Traza de auditoría consultable | `admin/audit.php` | Con filtros; la puede leer también el operador |
| Código comentado y estructurado | `app/src/` | Hecho |
| README y manuales | `README.md`, `docs/` | Hecho; 22 capturas reales en `docs/img/`. Presentación, vídeo y documento de presentación incluidos |

---

## Documentación

- [Modelo lógico completo](docs/MODELO_LOGICO.md): las 11 entidades, las 15 relaciones y las reglas de integridad.
- [Decisiones del Sprint 2](docs/DECISIONES-SPRINT2.md): las indicaciones del profesorado y las contradicciones de la guía.
- [Registro de incidencias](docs/INCIDENCIAS.md): los problemas reales que aparecieron y cómo se resolvieron.
- [Manual técnico](docs/MANUAL_TECNICO.md): despliegue, operación, copias y diagnóstico.
- [Manual de usuario](docs/MANUAL_USUARIO.md): los flujos de cliente y de administración.
- [Guía para enseñarlo en vivo](docs/GUIA-DEMO.md): el recorrido de siete minutos, con las credenciales y lo que hay que decir en cada paso.
- [Documento de presentación](docs/DOCUMENTO-PRESENTACION.pdf): 31 páginas con el alcance, la arquitectura, el modelo, el código, el Gantt, la verificación, la bibliografía y la declaración de IA.
- [Presentación](docs/PRESENTACION.pptx) y [su PDF](docs/PRESENTACION.pdf): las 18 diapositivas, listas para proyectar.
- [Guion de la presentación](docs/PRESENTACION.md): qué se dice en cada diapositiva.
- [Vídeo de demostración](docs/VIDEO-DEMO.mp4): el recorrido completo contra la aplicación en marcha.
- [Verificación del Sprint 2](docs/VERIFICACION-SPRINT2.md): qué se ejecutó el 28 y el 29/10/2026, qué devolvió y qué no se pudo comprobar.

---

## Alcance y límites

Lo que la aplicación **hace**: registrar clientes y vehículos, calcular y cobrar
reservas simuladas, asignar plazas sin solapamientos, simular entradas y
salidas por matrícula, cobrar excesos, y dar al administrador panel, filtros,
modificación, cancelación y auditoría.

Lo que la aplicación **no hace**, a propósito: controlar hardware, abrir
barreras, leer matrículas con cámara, gestionar la red del parking, ni cobrar
de verdad. El pago está simulado y no se guarda ningún dato de tarjeta.
