# Presentación del Sprint 2 · guion

**Duración:** 10 minutos, 18 diapositivas.
**Formato:** 16:9. Una idea por diapositiva, sin párrafos largos.
**Ficheros:** `docs/PRESENTACION.pptx` y `docs/PRESENTACION.pdf`, generados con `tools/gen-presentacion.py`.
**Regla de la casa:** el documento va en castellano porque el módulo 0379 se imparte en castellano.

Este guion es la fuente del deck: cada bloque `Diapositiva` es una diapositiva y el texto de `Nota` es lo que se dice en voz alta, no lo que se proyecta.

---

## Diapositiva 1 · Portada

**Título:** Tarraco Parkings · Sprint 2
**Subtítulo:** De la planificación a la aplicación que se despliega y se prueba
**Pie:** ASIX2 0379 · Grupo 6 · Pere Brau, Dhruva Mehta, Boubacar Seck

**Nota:** Somos el grupo 6. En el Sprint 1 presentamos el diseño funcional y el modelo de datos. En el Sprint 2 lo hemos convertido en una aplicación que se despliega con Docker y se puede probar.

---

## Diapositiva 2 · Qué cambia respecto al Sprint 1

- El Sprint 1 respondía «qué tiene que hacer el sistema».
- El Sprint 2 responde «cómo está construido y cómo se ejecuta».
- Se pasa de documentos y diagramas a código, base de datos y contenedores.
- Se mantiene el modelo aprobado, con dos correcciones que explicamos después.

**Nota:** No hemos empezado de cero: el modelo E/R del Sprint 1 es la base. Lo que cambia es el nivel de detalle y que ahora hay una implementación real.

---

## Diapositiva 3 · Alcance: solo software

- Reserva de plazas, pagos simulados y control de acceso simulado.
- Sin hardware: ni lectores de matrícula, ni barreras, ni red del parking.
- El control de acceso se teclea en un formulario, con sesión iniciada, y el sistema decide.
- Fuera del alcance: pasarela de pago real, correo, app móvil.

**Nota:** Esta diapositiva responde a la indicación de los profesores después del Sprint 1. Eliminamos todo lo de hardware y lo explicamos aquí para que no parezca que falta.

---

## Diapositiva 4 · Tres roles, tres permisos

- **Cliente:** se registra, reserva, descarga el resguardo y mueve sus matrículas.
- **Administrador:** ve y modifica todas las reservas, crea parkings, cancela sin borrar.
- **Operador:** consulta reservas, clientes y auditoría; no escribe nada.
- La sesión de cliente y la de administrador son independientes.

**Nota:** No son etiquetas del menú: cada permiso se comprueba en el servidor con `requireRole()`. Si el operador fabrica el POST a mano, la acción se rechaza en el servidor y no cambia nada.

---

## Diapositiva 5 · Arquitectura

- Docker Compose con tres servicios: `app`, `db` y `phpmyadmin`.
- PHP 8.2 sobre Apache, MariaDB 10.11, HTTPS con certificado autofirmado.
- Segundo compose para monitorización: Prometheus, Grafana, node-exporter y cAdvisor.
- La aplicación expone `/metrics.php` en formato Prometheus.
- Servidor de demostración: VM Ubuntu 24.04.5. Copias con `scripts/backup.sh`, restauración documentada.

**Nota:** Todo se levanta con `docker compose up -d`, sin instalar PHP ni MySQL a mano. En el aula se probó dentro de una VM Ubuntu porque los equipos no tienen Docker; en un equipo con Docker no hace falta.

---

## Diapositiva 6 · Modelo lógico: 11 entidades

- Cuentas: `CLIENTE`, `VEHICULO`, `METODO_PAGO`.
- Oferta: `PARKING`, `PLAZA`, `TARIFA`.
- Operación: `RESERVA`, `PAGO`, `REGISTRO_ACCESO`.
- Gestión: `ADMINISTRADOR`, `REGISTRO_AUDITORIA`.

**Figura:** `docs/img/modelo-er.svg`

**Nota:** El modelo no es un listado de tablas. Cada bloque tiene una responsabilidad y se conecta con los demás por claves foráneas.

---

## Diapositiva 7 · Las relaciones son el modelo

- 15 relaciones y 15 claves foráneas.
- `RESERVA` concentra cinco claves: cliente, vehículo, parking, plaza y tarifa.
- `PAGO` separa el cobro de la reserva del cobro del exceso.
- `REGISTRO_ACCESO` se conecta con parking, vehículo y reserva.

**Figura:** `docs/img/modelo-er.svg`

**Nota:** Esta es la diapositiva que responde a la segunda indicación: no basta con el modelo relacional, hay que enseñar las conexiones y las cardinalidades.

---

## Diapositiva 8 · Decisiones del modelo

- Fuera `DISPOSITIVO`: no hay equipo que identificar.
- Fuera `barrera_abierta`: no hay barrera que controlar.
- `PLAZA.estado` solo refleja la situación física; la disponibilidad se calcula.
- Se añaden `ADMINISTRADOR`, `REGISTRO_AUDITORIA` y `condiciones_aceptadas`.
- Los importes se congelan al crear la reserva.

**Nota:** Cada decisión tiene un motivo. La disponibilidad calculada evita tener dos verdades que se contradigan. Los importes congelados evitan que una subida de tarifa reescriba el pasado.

---

## Diapositiva 9 · Integridad

- Claves foráneas con `ON DELETE RESTRICT`.
- `CHECK` para fechas, importes, precios y capacidades.
- Trigger que impide borrar una reserva.
- Trigger que impide saltarse el ciclo de vida (siete transiciones).
- La garantía está en la base de datos, no solo en PHP.

**Nota:** Si alguien intenta borrar una reserva con un `DELETE`, la base de datos lo rechaza. No depende de que la aplicación se porte bien.

---

## Diapositiva 10 · Flujo de reserva

- El cliente elige parking, vehículo y franja.
- El sistema busca una plaza libre compatible dentro de una transacción.
- Calcula el precio y congela los importes.
- Registra el pago simulado y deja la reserva en `ACTIVA`.
- Si no hay plaza, no se crea nada.

**Nota:** La plaza se elige con `SELECT ... FOR UPDATE`. Dos clientes simultáneos no pueden recibir la misma plaza en la misma franja.

---

## Diapositiva 11 · Precio, exceso y resguardo

- Horas completas o fracción, mínimo una.
- `base = horas × precio_hora`.
- `gestión = base × 10 %`.
- Exceso: horas de retraso sobre la salida prevista, con 15 minutos de gracia, sin gestión.
- Resguardo PDF con el código y la plaza, servido solo al dueño.

**Ejemplo:** 4 h × 1,80 € = 7,20 € · gestión 0,72 € · total 7,92 €.

**Nota:** El cálculo es el mismo en la web, en el PDF y en las pruebas. Tenemos 22 comprobaciones automáticas del cálculo.

---

## Diapositiva 12 · Control de acceso simulado

- Se teclea la matrícula y se elige entrada o salida.
- Entrada: vehículo registrado, reserva `ACTIVA`, dentro de la franja y con 30 minutos de margen.
- Salida: reserva `EN_CURSO`; si hay retraso, se cobra el exceso.
- Todo intento se registra, autorizado o denegado, con su motivo.

**Nota:** No hay barrera física. Lo que sí hay es una traza completa de quién intentó entrar, cuándo y qué decidió el sistema.

---

## Diapositiva 13 · Panel de administración

- Reservas: filtrar, editar fechas y plaza, cancelar sin borrar.
- Parkings: crear y editar. Al crear, se generan sus plazas y su tarifa.
- Plazas: retirar y volver a poner en servicio.
- Tarifas: consultar la vigente y añadir una nueva con fecha posterior.
- Clientes: consultar, buscar y dar de baja lógica.
- Auditoría: las últimas cien acciones, con filtros y detalle.

**Figura:** `docs/img/captura-17-admin-parkings.png` y `docs/img/captura-18-auditoria.png`

**Nota:** El administrador no puede borrar reservas. Puede modificar y cancelar, que es lo que pide el criterio de aceptación. La traza se escribe desde diez puntos del código y se consulta desde el panel.

---

## Diapositiva 14 · Monitorización

- Prometheus recoge métricas de la aplicación, del host y de los contenedores.
- Grafana muestra reservas activas, reservas por estado, plazas libres, peticiones por clase, errores 5xx, ingresos, CPU y memoria.
- Las métricas de negocio se leen de MariaDB en cada scrape.
- Los contadores HTTP se guardan con bloqueo, sin APCu.

**Nota:** La monitorización no son capturas inventadas: el panel lee métricas reales de la aplicación y de los contenedores.

---

## Diapositiva 15 · Planificación y reparto

- 16 tareas en siete semanas, del 9 de octubre al 22 de noviembre.
- Tres responsables: PB arquitectura y servidor, DM modelo y precios, BS interfaz y reservas.
- La tarea 7.2 del plan original (inventario de dispositivos) se eliminó con el hardware.
- Dos tareas añadidas el 28 de octubre: resguardo PDF y condiciones.

**Figura:** el Gantt de la propia diapositiva.

**Nota:** El camino crítico pasa por el modelo y por el servidor, y por eso esas dos tareas se cerraron la primera semana. Es lo que permitió empezar a probar el 20 de octubre y no en noviembre.

---

## Diapositiva 16 · Verificación y límites

- Stack arrancado en una VM Ubuntu con Docker Compose: `smoke-test.sh` 19/19.
- Prueba de extremo a extremo desde el portátil contra la VM: 53/53.
- También verificado: sintaxis PHP de 37 archivos, esquema SQL, 22 pruebas de cálculo, 96 enlaces internos.
- El pago es simulado y no se guarda ningún dato de tarjeta.

**Nota:** Decimos dónde se ha probado cada cosa: dentro de la VM el stack completo, y desde el portátil el flujo de cliente contra el puerto reenviado.

---

## Diapositiva 17 · Bibliografía y uso de IA

- Fuentes: PHP, MariaDB, Docker, Apache, Prometheus y Grafana, OWASP e ISO 32000-1.
- Agente y modelo: `codewhale` con `deepseek-v4-flash` en el cierre del 28 de octubre.
- El desarrollo previo (21 a 26 de octubre) no dejó el modelo registrado, y así se dice.
- Cinco prompts transcritos en el apartado 9 del documento de presentación.

**Nota:** La declaración es un registro de lo que pasó, no una plantilla. Lo que no sabemos, no lo inventamos.

---

## Diapositiva 18 · Cierre

- Sprint 2 entrega una aplicación completa, no solo un diseño.
- El modelo lógico está documentado con entidades, relaciones y cardinalidades.
- El hardware queda fuera, como pidieron los profesores.
- La demo grabada está en `docs/VIDEO-DEMO.mp4` y el documento en `docs/DOCUMENTO-PRESENTACION.pdf`.

**Nota:** Con esto cerramos. La aplicación está construida, arrancada y probada; el vídeo enseña el recorrido completo por si falla la red el día de la exposición.
