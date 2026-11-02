# Verificación del Sprint 2 · 28 y 29 de octubre de 2026

Este documento no explica cómo está hecho el sistema: eso está en el manual técnico.
Aquí se dice **qué se ejecutó, qué devolvió y qué no se ha podido comprobar**. Cada
fila de la tabla final se puede reproducir con el comando que aparece al principio.

Todo lo que sigue se ejecutó el 28/10/2026 sobre la máquina virtual `sprint2-ubuntu`,
con el stack levantado desde `/opt/tarraco-parkings`. Es la pasada que incluye el
resguardo PDF y las condiciones añadidos ese mismo día: por eso el smoke test son 19
comprobaciones y la prueba de extremo a extremo, 53. Después se corrigieron cuatro cosas
en el código y las dos suites se repitieron el 29/10 con la base recreada desde cero, sin
que bajara ningún total (apartado 4.1).

---

## 1. Dónde se ejecutó

| Qué | Valor |
|---|---|
| Máquina | `sprint2-ubuntu`, Ubuntu 24.04.5, 4 vCPU y 8 GB |
| Despliegue | `/opt/tarraco-parkings`, `docker compose up -d` |
| Contenedores | 7: `app`, `db`, `phpmyadmin`, `prometheus`, `grafana`, `node-exporter`, `cadvisor` |
| Aplicación | `https://127.0.0.1:8443` (443 dentro de la VM) |
| Panel de gestión | `/admin/login.php` |
| Prometheus | `http://127.0.0.1:9090` |
| Grafana | `http://127.0.0.1:3000`, versión 11.2.0 |
| phpMyAdmin | `http://127.0.0.1:8081` (dentro de la VM, 8080) |
| Base de datos | MariaDB 10.11, `tarraco_parkings`, 11 tablas |

Antes de medir nada se ha partido de cero: `docker compose down -v` y `up -d`. La base
se recrea desde `db/init.sql`, así que los números de abajo no arrastran pruebas
anteriores.

---

## 2. Las dos suites

### 2.1 Dentro de la VM · `scripts/smoke-test.sh`

```
RESULTADO: 19/19 pruebas correctas.
```

Cubre cuatro bloques: contenedores en marcha, HTTPS y redirección, base de datos y
monitorización. Lo interesante de este bloque es la parte de integridad: comprueba que
hay 11 tablas, que los importes de **todas** las reservas cuadran con su tarifa, que
ninguna salida tardía se quedó sin cobrar el exceso y que **el trigger rechaza un
`DELETE` sobre `RESERVA`**.

### 2.2 Desde el portátil · `tests/e2e_client.py`

```
python3 tests/e2e_client.py --host https://127.0.0.1:8443
RESULTADO: 53/53 comprobaciones correctas.
```

Nueve bloques: páginas públicas, alta de cuenta y sesión, reserva con precio, control
de acceso de entrada y salida, aislamiento entre clientes, panel de administración,
alta de un parking desde el panel, retirada de una plaza y métricas.

La prueba crea datos propios (`matricula_unica()`, `dni_unico()`), de modo que se puede
repetir sin limpiar la base. La ejecución de hoy es la tercera pasada consecutiva.

---

## 3. Los doce criterios de aceptación

| # | Criterio de la guía | Cómo se ha comprobado | Resultado |
|---|---|---|---|
| 1 | `docker compose up -d` levanta todo | `docker compose ps` + smoke test | **Cumple.** 7 contenedores, 19/19 |
| 2 | HTTPS con certificado autofirmado | `curl -k https://127.0.0.1:8443/` y redirección 301 desde HTTP | **Cumple.** 200 en HTTPS, 301 desde HTTP |
| 3 | Registro, login y gestión de vehículos | e2e, bloques 2 y 3 | **Cumple.** 53/53 |
| 4 | Reserva con cálculo automático + 10 % | e2e bloque 3 y `tests/pricing_test.php` | **Cumple.** 22 comprobaciones de cálculo |
| 5 | El admin ve, modifica y cancela sin borrar | `admin/reservations.php` + trigger de borrado | **Cumple.** El `DELETE` aborta con SQLSTATE 45000 |
| 6 | Simulación de entrada y salida por matrícula | `access-control.php`, e2e bloque 4 | **Cumple.** Entrada autorizada y salida registradas |
| 7 | Detección y cobro del exceso | `AccessControl.php` + `pricing_test.php` | **Cumple.** 15 minutos de gracia, horas completas |
| 8 | Prometheus scrapea y Grafana muestra el panel | `/api/v1/targets` y `/api/health` | **Cumple.** 4 objetivos `up`, Grafana `database ok` |
| 9 | phpMyAdmin accesible | `curl http://127.0.0.1:8081/` | **Cumple.** 200, con las 11 tablas |
| 10 | Alta de un parking completa desde el panel | e2e bloque 7 | **Cumple.** Crea plazas y tarifa en una transacción |
| 11 | Código comentado y estructurado | `php -l` sobre los 37 archivos | **Cumple.** 0 errores de sintaxis |
| 12 | README y manuales | `tests/check_links.py` | **Cumple.** 96 referencias, todas resuelven |

Los que la guía llama «criterios» y aquí aparecen como extra, también verificados:

- **Esquema**: `tests/check_schema.py` → 11 tablas, 15 claves foráneas, 131 filas de semilla.
- **Consultas**: `tests/check_sql_params.php` → 99 consultas, ningún marcador huérfano.
- **Referencias internas**: `tests/check_links.py` → 96, todas resuelven.

> Los puertos de las tablas son los del reenvío de la VM: desde el portátil, el 8443
> lleva al 443 y el 8081 al 8080. En un despliegue nuevo, `docker compose up -d`
> publica los de `.env`, que son 80, 443 y 8080.

---

## 4. Lo que se ha añadido en esta sesión

| Pieza | Dónde | Estado |
|---|---|---|
| Presentación, 18 diapositivas 16:9 | `docs/PRESENTACION.pptx` y `docs/PRESENTACION.pdf` | Generada con `tools/gen-presentacion.py` |
| Guion de la presentación | `docs/PRESENTACION.md` | Corregido: el e2e son 53 comprobaciones, no 41 |
| Vídeo de demostración, 5–10 min | `docs/VIDEO-DEMO.mp4` | Grabado contra la aplicación en marcha |
| Grabador y montador del vídeo | `tools/gen-video.mjs`, `tools/montar-video.mjs` | Reproducibles |
| Generador de la presentación | `tools/gen-presentacion.py` | Reproducible |
| Empaquetado del ZIP | `tools/empaqueta.py` | Reproducible; raíz `tarraco-parkings/`, sin `.env` ni certificados |
| Comprobación estructural de PHP | `tools/verifica-sintaxis.py` | Sustituye a `php -l` donde no hay intérprete |

El vídeo no está montado con capturas sueltas: se graba la pestaña con el screencast de
Chrome DevTools mientras un guion recorre la aplicación de verdad. Los fotogramas se
guardan con su instante y el montaje respeta esos huecos, de modo que los rótulos caen
donde toca.

### 4.1 Cambios posteriores a la pasada del 28/10, ya repetidos el 29/10

Después de la verificación de arriba se corrigieron cuatro cosas en el código. Las dos
suites **se han vuelto a ejecutar el 29/10/2026 con la base recreada desde cero**
(`docker compose down -v` y `up -d`, más el segundo compose de monitorización):

```
smoke-test.sh    ->  RESULTADO: 19/19 pruebas correctas.
e2e_client.py    ->  RESULTADO: 53/53 comprobaciones correctas.
```

Los siete contenedores arrancaron desde el volumen vacío y los datos de semilla se
crearon solos. Es decir, el resultado ya no es de la pasada anterior: los cuatro cambios
de la tabla de abajo están cubiertos y no han bajado ningún total.

| Qué se cambió | Dónde | Por qué |
|---|---|---|
| El control de acceso revalida la cuenta con `checkAuth()` y no solo que haya sesión | `app/public/access-control.php` | Un cliente dado de baja seguía escribiendo en `RESERVA`, `PLAZA` y `PAGO` hasta cerrar sesión |
| El resguardo PDF revalida la sesión y acepta también al administrador | `app/public/reservation-pdf.php` | Servía el documento sin comprobar que la cuenta siguiera activa |
| Al reservar se comprueba que el vehículo no tenga ya una reserva viva en esa franja | `app/src/Reservation.php` | El mismo coche podía tener dos reservas solapadas y cobrarse dos veces. Se bloquea la fila del vehículo con `FOR UPDATE` antes de comprobar, porque comprobar antes de insertar no basta |
| Los tres valores de `business` en `config.php` se leen de verdad | `app/src/AccessControl.php`, `Parking.php`, `Reservation.php` | Estaban definidos y no los usaba nadie: cambiarlos no hacía nada |

Por si hay que repetirlo, en la VM:

```bash
cd /opt/tarraco-parkings && sudo bash scripts/smoke-test.sh        # 19/19
PYTHONPATH=$SSD/Maquinas/_pylib python3 tests/e2e_client.py \
  --host https://127.0.0.1:8443                                     # 53/53
```

No se ha añadido ni quitado ninguna comprobación: los totales son los mismos de siempre.

### 4.2 Las comprobaciones estáticas, el 29/10

Ejecutadas en el portátil, que sí tiene PHP 8.4, contra el mismo árbol que se copió a la VM:

| Prueba | Resultado |
|---|---|
| `php -l` sobre los 37 archivos PHP | 0 errores de sintaxis |
| `tests/pricing_test.php` | 22 comprobaciones correctas |
| `tests/check_schema.py` | 11 tablas, esquema estructuralmente válido |
| `tests/check_sql_params.php` | **99** consultas en 12 archivos, ningún marcador huérfano |
| `tests/check_links.py` | 96 referencias, todas resuelven |
| `tools/verifica-sintaxis.py` | 39 archivos, 0 con problemas estructurales |

Las consultas SQL son **99 y no 96** desde los cuatro arreglos de la sección 4.1: las tres
consultas nuevas son el bloqueo del vehículo y las dos comprobaciones de solape. El resto de la
documentación ya dice 99.

---

## 5. Lo que no se ha podido comprobar

- **El arranque desde cero en un equipo limpio.** Aquí se ha probado dentro de la VM,
  que ya tenía las imágenes descargadas. Un `docker compose up -d --build` en un equipo
  sin caché tardará varios minutos y no se ha medido.
- **El correo y la pasarela de pago** no existen: están fuera del alcance declarado.
- **La auditoría no se ha probado con dos administradores a la vez.** El modelo lo
  permite (`ADMINISTRADOR` tiene rol), pero no hay escenario de concurrencia probado.
- **El vídeo se grabó con datos de demostración**, no con la base recién sembrada: las
  reservas que se crean durante la grabación se quedan en la base de la VM. La base de
  un despliegue nuevo sale de `db/init.sql` y no las tiene.

---

## 6. Cómo repetirlo

```bash
# 1. Arrancar la VM desde una sesión de escritorio
VBoxManage startvm sprint2-ubuntu --type gui

# 2. Desde el portátil, contra la aplicación
PYTHONPATH=$SSD/Maquinas/_pylib python3 tests/e2e_client.py --host https://127.0.0.1:8443

# 3. Dentro de la VM
cd /opt/tarraco-parkings && sudo bash scripts/smoke-test.sh

# 4. Las pruebas estáticas, sin necesidad de contenedores
python3 tests/check_schema.py
php tests/pricing_test.php
php tests/check_sql_params.php
python3 tests/check_links.py
```

Si el vídeo hay que rehacerlo, `tools/gen-video.mjs` graba y monta de una pasada, y
`tools/montar-video.mjs` vuelve a montar desde los fotogramas guardados sin grabar otra
vez.
