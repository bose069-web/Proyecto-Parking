# Proyecto-Parking
Tarraco Parkings · Sprint 2
Aplicación web de reserva de plazas de aparcamiento en Tarragona. Proyecto de ASIX2 0379, Grupo 6, Sprint 2.
El proyecto es solo software. No se modela ni se controla hardware de los aparcamientos: ni lectores de matrícula, ni barreras, ni estructura de red. El control de acceso es una simulación que se maneja desde la propia aplicación.

Arranque rápido
Requisitos: Docker Engine o Docker Desktop con Compose v2. Nada más.
cd tarraco-parkings
cp .env.example .env          # cambia las contraseñas si vas a publicarlo
docker compose up -d --build
La primera vez tarda un par de minutos: descarga las imágenes, crea la base de datos con las 11 tablas y carga los datos de prueba.
Servicio
URL
Credenciales
Aplicación
https://localhost/
pere.brau@example.com / Client1234
Panel de gestión
https://localhost/admin/login.php
admin@tarraco.local / Admin1234
phpMyAdmin
http://localhost:8080/
usuario tarraco, contraseña de .env
Prometheus
http://localhost:9090/
sin autenticación
Grafana
http://localhost:3000/
admin / la de .env

El certificado es autofirmado. El navegador avisará la primera vez: es lo esperado. Se puede aceptar la excepción o confiar en él:
# Linux
sudo cp certs/tarraco.crt /usr/local/share/ca-certificates/tarraco.crt
sudo update-ca-certificates

# Windows: doble clic en certs\tarraco.crt > Instalar certificado > Equipo local
Si tarraco.local no resuelve, añade esta línea al archivo hosts:
127.0.0.1 tarraco.local

Monitorización
El stack de monitorización es un segundo archivo de composición. Se une a la red del primero, así que el orden importa:
docker compose up -d
docker compose -f docker-compose.monitor.yml up -d
Grafana se aprovisiona sola con el datasource de Prometheus y el panel «Tarraco Parkings · Sprint 2»: reservas activas, reservas por estado, plazas libres, peticiones HTTP por clase, errores 5xx, ingresos, CPU y memoria.
La aplicación expone sus métricas en https://localhost/metrics.php. Los contadores HTTP se escriben en el arranque de cada petición y las métricas de negocio se leen de MariaDB en el momento.

Estructura
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
│   ├── MODELO_LOGICO.md          el modelo completo, con relaciones y cardinalidades
│   ├── DECISIONES-SPRINT2.md     por qué cada cosa es como es
│   ├── MANUAL_TECNICO.md
│   └── MANUAL_USUARIO.md
├── scripts/                      certificados, copia de seguridad, pruebas
├── tests/                        validador del esquema y cálculo de precios
└── tools/gen-modelo.mjs          genera los diagramas E/R

Cómo se comprueba
Sin Docker, lo que se puede comprobar de verdad:
php tests/pricing_test.php          # 22 comprobaciones del cálculo económico
php tests/check_sql_params.php      # marcadores SQL y parámetros cuadran
python3 tests/check_schema.py       # 11 tablas, FKs e índices coherentes
python3 tests/check_links.py        # enlaces y assets internos
node tools/gen-modelo.mjs           # regenera los diagramas del modelo
Con Docker y el stack levantado:
bash scripts/smoke-test.sh          # HTTPS, base de datos, triggers y monitorización

# Desde otra máquina (el cliente), contra el puerto publicado:
python3 tests/e2e_client.py --host https://<servidor>:8443
El smoke-test.sh comprueba, entre otras cosas, que los importes de las 20 reservas cuadran con su tarifa y que el trigger de borrado rechaza un DELETE.

Criterios de aceptación
Criterio
Dónde está
Estado
docker compose up -d levanta todo
docker-compose.yml + docker-compose.monitor.yml
Verificado en la VM Ubuntu: 7 servicios, smoke-test.sh 18/18
HTTPS con certificado autofirmado
app/docker/apache-ssl.conf + entrypoint
Verificado dentro de la VM
Registro, login y vehículos
register.php, login.php, vehicles.php
Implementado y probado en la prueba e2e
Reserva con precio automático + 10 %
reservation.php, src/Reservation.php
Implementado, probado en cálculo y en la e2e
Admin ve, modifica y cancela sin borrar
admin/reservations.php + triggers
Implementado; el trigger de borrado se prueba en el smoke test
Simulación de entrada y salida
access-control.php, con sesión obligatoria
Implementado y probado en la e2e
Detección y cobro de exceso
src/AccessControl.php
Implementado y probado en la e2e
Prometheus y Grafana con panel
monitor/
Verificado dentro de la VM
phpMyAdmin en 8080
docker-compose.yml
Verificado dentro de la VM
Alta de parking completa desde el panel
admin/parkings.php, Parking::create()
Crea el parking con sus plazas y su tarifa; probado en la e2e
Gestión de plazas y tarifas
admin/parkings.php
Retirada y vuelta a servicio, y tarifas con vigencia
Traza de auditoría consultable
admin/audit.php
Con filtros; la puede leer también el operador
Código comentado y estructurado
app/src/
Hecho
README y manuales
README.md, docs/
Hecho; 19 capturas reales en docs/img/


Documentación
Modelo lógico completo: las 11 entidades, las 15 relaciones y las reglas de integridad.
Decisiones del Sprint 2: las indicaciones del profesorado y las contradicciones de la guía.
Registro de incidencias: los problemas reales que aparecieron y cómo se resolvieron.
Manual técnico: despliegue, operación, copias y diagnóstico.
Manual de usuario: los flujos de cliente y de administración.
Guía para enseñarlo en vivo: el recorrido de siete minutos, con las credenciales y lo que hay que decir en cada paso.
Guion de la presentación: las 15 diapositivas.

Alcance y límites
Lo que la aplicación hace: registrar clientes y vehículos, calcular y cobrar reservas simuladas, asignar plazas sin solapamientos, simular entradas y salidas por matrícula, cobrar excesos, y dar al administrador panel, filtros, modificación, cancelación y auditoría.
Lo que la aplicación no hace, a propósito: controlar hardware, abrir barreras, leer matrículas con cámara, gestionar la red del parking, ni cobrar de verdad. El pago está simulado y no se guarda ningún dato de tarjeta.
