-- =====================================================================
--  TARRACO PARKINGS · SPRINT 2 (ASIX2 0379, Grupo 6)
--  Esquema lógico completo y datos de prueba.
--
--  Motor: MariaDB 10.11 (compatible con MySQL 8).
--
--  Este archivo es la única fuente del esquema. Docker lo monta en
--  /docker-entrypoint-initdb.d/ y se ejecuta una sola vez, cuando el
--  volumen de datos está vacío. Para volver a aplicarlo:
--      docker compose down -v && docker compose up -d
--
--  El nombre de la base está fijado aquí (tarraco_parkings) y en la
--  variable DB_NAME del .env. Si se cambia uno, hay que cambiar el otro:
--  el contenedor crea la base de DB_NAME y este script usa la suya.
--
--  Criterio de diseño (nota de los profesores del Sprint 1):
--  el proyecto es SOLO SOFTWARE. No hay entidad de equipamiento, ni
--  lectores de matrícula, ni controladores de barrera, ni estructura
--  de red. El control de acceso es una simulación de software que
--  registra la matrícula y decide si el paso está autorizado.
-- =====================================================================

SET NAMES utf8mb4;
-- La zona horaria NO se fija aquí. MariaDB usa la del sistema del contenedor
-- (TZ=Europe/Madrid en docker-compose.yml), que aplica el horario de verano.
-- Fijarla a un desfase literal desincronizaba la base de datos de PHP.
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';

CREATE DATABASE IF NOT EXISTS tarraco_parkings
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE tarraco_parkings;

-- Se borran en orden inverso a las dependencias.
DROP TABLE IF EXISTS REGISTRO_AUDITORIA;
DROP TABLE IF EXISTS PAGO;
DROP TABLE IF EXISTS REGISTRO_ACCESO;
DROP TABLE IF EXISTS RESERVA;
DROP TABLE IF EXISTS TARIFA;
DROP TABLE IF EXISTS PLAZA;
DROP TABLE IF EXISTS PARKING;
DROP TABLE IF EXISTS METODO_PAGO;
DROP TABLE IF EXISTS VEHICULO;
DROP TABLE IF EXISTS CLIENTE;
DROP TABLE IF EXISTS ADMINISTRADOR;

-- ---------------------------------------------------------------------
-- 1. CLIENTE · cuenta de la persona que reserva
-- ---------------------------------------------------------------------
CREATE TABLE CLIENTE (
  id_cliente       INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  nombre           VARCHAR(50)      NOT NULL,
  apellidos        VARCHAR(100)     NOT NULL,
  email            VARCHAR(120)     NOT NULL,
  telefono         VARCHAR(20)          NULL,
  documento_ident  VARCHAR(20)      NOT NULL,
  password_hash    VARCHAR(255)     NOT NULL,
  fecha_registro   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  estado           ENUM('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO',
  PRIMARY KEY (id_cliente),
  UNIQUE KEY uq_cliente_email (email),
  UNIQUE KEY uq_cliente_documento (documento_ident),
  KEY idx_cliente_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Clientes registrados. La baja es lógica (estado), nunca un DELETE.';

-- ---------------------------------------------------------------------
-- 2. VEHICULO · vehículos de cada cliente
-- ---------------------------------------------------------------------
CREATE TABLE VEHICULO (
  id_vehiculo    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  id_cliente     INT UNSIGNED  NOT NULL,
  matricula      VARCHAR(10)   NOT NULL,
  marca          VARCHAR(50)       NULL,
  modelo         VARCHAR(50)       NULL,
  color          VARCHAR(30)       NULL,
  tipo_vehiculo  ENUM('COCHE','MOTO','ADAPTADO','CARGA') NOT NULL DEFAULT 'COCHE',
  activo         TINYINT(1)    NOT NULL DEFAULT 1,
  PRIMARY KEY (id_vehiculo),
  UNIQUE KEY uq_vehiculo_matricula (matricula),
  KEY idx_vehiculo_cliente (id_cliente, activo),
  CONSTRAINT fk_vehiculo_cliente
    FOREIGN KEY (id_cliente) REFERENCES CLIENTE (id_cliente)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_vehiculo_matricula CHECK (CHAR_LENGTH(matricula) BETWEEN 4 AND 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Un vehículo pertenece a un único cliente. La matrícula es única en el sistema.';

-- ---------------------------------------------------------------------
-- 3. PARKING · recintos gestionados
-- ---------------------------------------------------------------------
CREATE TABLE PARKING (
  id_parking       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  nombre           VARCHAR(80)   NOT NULL,
  direccion        VARCHAR(150)  NOT NULL,
  ciudad           VARCHAR(50)   NOT NULL DEFAULT 'Tarragona',
  descripcion      VARCHAR(255)      NULL,
  capacidad_total  INT UNSIGNED  NOT NULL,
  horario          VARCHAR(80)   NOT NULL DEFAULT '24 h',
  latitud          DECIMAL(10,7)     NULL,
  longitud         DECIMAL(10,7)     NULL,
  activo           TINYINT(1)    NOT NULL DEFAULT 1,
  PRIMARY KEY (id_parking),
  KEY idx_parking_ciudad (ciudad, activo),
  CONSTRAINT chk_parking_capacidad CHECK (capacidad_total >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Datos descriptivos del recinto. No se modela su infraestructura física.';

-- ---------------------------------------------------------------------
-- 4. PLAZA · unidad reservable de un parking
-- ---------------------------------------------------------------------
CREATE TABLE PLAZA (
  id_plaza      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  id_parking    INT UNSIGNED  NOT NULL,
  codigo_plaza  VARCHAR(10)   NOT NULL,
  planta        INT           NOT NULL DEFAULT 0,
  tipo_plaza    ENUM('ESTANDAR','ADAPTADA','ELECTRICA','CARGA','MOTO') NOT NULL DEFAULT 'ESTANDAR',
  estado        ENUM('LIBRE','OCUPADA','FUERA_SERVICIO') NOT NULL DEFAULT 'LIBRE',
  PRIMARY KEY (id_plaza),
  UNIQUE KEY uq_plaza_codigo (id_parking, codigo_plaza),
  KEY idx_plaza_disponible (id_parking, tipo_plaza, estado),
  CONSTRAINT fk_plaza_parking
    FOREIGN KEY (id_parking) REFERENCES PARKING (id_parking)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='El estado solo refleja la situación física (libre, ocupada, fuera de servicio). La disponibilidad por franja se calcula con RESERVA.';

-- ---------------------------------------------------------------------
-- 5. TARIFA · precio por hora y porcentaje de gestión
-- ---------------------------------------------------------------------
CREATE TABLE TARIFA (
  id_tarifa           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  id_parking          INT UNSIGNED   NOT NULL,
  tipo_plaza          ENUM('ESTANDAR','ADAPTADA','ELECTRICA','CARGA','MOTO') NOT NULL DEFAULT 'ESTANDAR',
  descripcion         VARCHAR(80)    NOT NULL,
  precio_hora         DECIMAL(10,2)  NOT NULL,
  porcentaje_gestion  DECIMAL(5,4)   NOT NULL DEFAULT 0.1000,
  fecha_inicio_vigencia DATE         NOT NULL,
  fecha_fin_vigencia  DATE               NULL,
  activa              TINYINT(1)     NOT NULL DEFAULT 1,
  PRIMARY KEY (id_tarifa),
  KEY idx_tarifa_vigente (id_parking, tipo_plaza, activa, fecha_inicio_vigencia),
  CONSTRAINT fk_tarifa_parking
    FOREIGN KEY (id_parking) REFERENCES PARKING (id_parking)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_tarifa_precio CHECK (precio_hora >= 0),
  CONSTRAINT chk_tarifa_gestion CHECK (porcentaje_gestion >= 0 AND porcentaje_gestion <= 1),
  CONSTRAINT chk_tarifa_fechas CHECK (fecha_fin_vigencia IS NULL OR fecha_fin_vigencia >= fecha_inicio_vigencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='porcentaje_gestion se guarda como fracción: 0.1000 equivale al 10 %.';

-- ---------------------------------------------------------------------
-- 6. RESERVA · núcleo del modelo
-- ---------------------------------------------------------------------
CREATE TABLE RESERVA (
  id_reserva                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_cliente                    INT UNSIGNED    NOT NULL,
  id_vehiculo                   INT UNSIGNED    NOT NULL,
  id_parking                    INT UNSIGNED    NOT NULL,
  id_plaza                      INT UNSIGNED        NULL,
  id_tarifa                     INT UNSIGNED    NOT NULL,
  codigo_reserva                VARCHAR(16)     NOT NULL,
  fecha_hora_entrada_prevista   DATETIME        NOT NULL,
  fecha_hora_salida_prevista    DATETIME        NOT NULL,
  fecha_hora_entrada_real       DATETIME            NULL,
  fecha_hora_salida_real        DATETIME            NULL,
  estado                        ENUM('PENDIENTE_PAGO','ACTIVA','EN_CURSO','FINALIZADA','CON_EXCESO','CANCELADA','NO_PRESENTADA')
                                NOT NULL DEFAULT 'PENDIENTE_PAGO',
  importe_base                  DECIMAL(10,2)   NOT NULL,
  importe_gestion               DECIMAL(10,2)   NOT NULL,
  importe_total                 DECIMAL(10,2)   NOT NULL,
  importe_exceso                DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  observaciones                 VARCHAR(255)        NULL,
  fecha_creacion                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_reserva),
  UNIQUE KEY uq_reserva_codigo (codigo_reserva),
  KEY idx_reserva_cliente (id_cliente, estado),
  KEY idx_reserva_vehiculo (id_vehiculo, estado),
  KEY idx_reserva_plaza_franja (id_plaza, fecha_hora_entrada_prevista, fecha_hora_salida_prevista),
  KEY idx_reserva_parking_franja (id_parking, fecha_hora_entrada_prevista),
  KEY idx_reserva_estado (estado),
  CONSTRAINT fk_reserva_cliente
    FOREIGN KEY (id_cliente) REFERENCES CLIENTE (id_cliente)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_reserva_vehiculo
    FOREIGN KEY (id_vehiculo) REFERENCES VEHICULO (id_vehiculo)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_reserva_parking
    FOREIGN KEY (id_parking) REFERENCES PARKING (id_parking)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_reserva_plaza
    FOREIGN KEY (id_plaza) REFERENCES PLAZA (id_plaza)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_reserva_tarifa
    FOREIGN KEY (id_tarifa) REFERENCES TARIFA (id_tarifa)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_reserva_fechas CHECK (fecha_hora_salida_prevista > fecha_hora_entrada_prevista),
  CONSTRAINT chk_reserva_reales CHECK (
    fecha_hora_salida_real IS NULL OR fecha_hora_entrada_real IS NULL
    OR fecha_hora_salida_real >= fecha_hora_entrada_real),
  CONSTRAINT chk_reserva_importes CHECK (
    importe_base >= 0 AND importe_gestion >= 0 AND importe_total >= 0 AND importe_exceso >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Las reservas no se borran nunca: solo cambian de estado.';

-- ---------------------------------------------------------------------
-- 7. METODO_PAGO · métodos tokenizados del cliente
-- ---------------------------------------------------------------------
CREATE TABLE METODO_PAGO (
  id_metodo_pago    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  id_cliente        INT UNSIGNED  NOT NULL,
  tipo              ENUM('TARJETA','TRANSFERENCIA','PAYPAL','BIZUM') NOT NULL DEFAULT 'TARJETA',
  descripcion       VARCHAR(60)       NULL,
  token             VARCHAR(255)      NULL,
  por_defecto       TINYINT(1)    NOT NULL DEFAULT 0,
  activo            TINYINT(1)    NOT NULL DEFAULT 1,
  PRIMARY KEY (id_metodo_pago),
  KEY idx_metodo_cliente (id_cliente, activo),
  CONSTRAINT fk_metodo_cliente
    FOREIGN KEY (id_cliente) REFERENCES CLIENTE (id_cliente)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Nunca se guarda el número de tarjeta: solo un token de la pasarela.';

-- ---------------------------------------------------------------------
-- 8. PAGO · movimientos económicos de una reserva
-- ---------------------------------------------------------------------
CREATE TABLE PAGO (
  id_pago                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_reserva              BIGINT UNSIGNED NOT NULL,
  id_metodo_pago          INT UNSIGNED    NOT NULL,
  tipo_pago               ENUM('RESERVA','EXCESO','PENALIZACION') NOT NULL DEFAULT 'RESERVA',
  importe                 DECIMAL(10,2)   NOT NULL,
  estado                  ENUM('PENDIENTE','CONFIRMADO','FALLIDO','REEMBOLSADO') NOT NULL DEFAULT 'PENDIENTE',
  fecha_hora              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  referencia_transaccion  VARCHAR(64)         NULL,
  PRIMARY KEY (id_pago),
  UNIQUE KEY uq_pago_referencia (referencia_transaccion),
  KEY idx_pago_reserva (id_reserva, tipo_pago),
  KEY idx_pago_estado (estado, fecha_hora),
  CONSTRAINT fk_pago_reserva
    FOREIGN KEY (id_reserva) REFERENCES RESERVA (id_reserva)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_pago_metodo
    FOREIGN KEY (id_metodo_pago) REFERENCES METODO_PAGO (id_metodo_pago)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_pago_importe CHECK (importe >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Separar el pago de la reserva del cobro del exceso permite auditarlos por separado.';

-- ---------------------------------------------------------------------
-- 9. REGISTRO_ACCESO · simulación de entrada y salida
-- ---------------------------------------------------------------------
CREATE TABLE REGISTRO_ACCESO (
  id_registro_acceso  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_parking          INT UNSIGNED    NOT NULL,
  id_vehiculo         INT UNSIGNED        NULL,
  id_reserva          BIGINT UNSIGNED     NULL,
  matricula_leida     VARCHAR(10)     NOT NULL,
  tipo_movimiento     ENUM('ENTRADA','SALIDA') NOT NULL,
  fecha_hora          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  autorizado          TINYINT(1)      NOT NULL,
  motivo_denegacion   VARCHAR(120)        NULL,
  observaciones       VARCHAR(255)        NULL,
  PRIMARY KEY (id_registro_acceso),
  KEY idx_acceso_matricula (matricula_leida, fecha_hora),
  KEY idx_acceso_parking (id_parking, fecha_hora),
  KEY idx_acceso_reserva (id_reserva),
  CONSTRAINT fk_acceso_parking
    FOREIGN KEY (id_parking) REFERENCES PARKING (id_parking)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_acceso_vehiculo
    FOREIGN KEY (id_vehiculo) REFERENCES VEHICULO (id_vehiculo)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_acceso_reserva
    FOREIGN KEY (id_reserva) REFERENCES RESERVA (id_reserva)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registro de la simulación. No controla ninguna barrera física.';

-- ---------------------------------------------------------------------
-- 10. ADMINISTRADOR · cuentas del panel de gestión
-- ---------------------------------------------------------------------
CREATE TABLE ADMINISTRADOR (
  id_admin       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  nombre         VARCHAR(80)   NOT NULL,
  email          VARCHAR(120)  NOT NULL,
  password_hash  VARCHAR(255)  NOT NULL,
  rol            ENUM('SUPERADMIN','ADMIN','OPERADOR') NOT NULL DEFAULT 'ADMIN',
  activo         TINYINT(1)    NOT NULL DEFAULT 1,
  fecha_alta     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_admin),
  UNIQUE KEY uq_admin_email (email),
  KEY idx_admin_activo (activo, rol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tabla separada de CLIENTE: un administrador no es un cliente con permisos.';

-- ---------------------------------------------------------------------
-- 11. REGISTRO_AUDITORIA · traza de las acciones administrativas
-- ---------------------------------------------------------------------
CREATE TABLE REGISTRO_AUDITORIA (
  id_auditoria     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_admin         INT UNSIGNED        NULL,
  tabla_afectada   VARCHAR(40)     NOT NULL,
  registro_id      BIGINT UNSIGNED     NULL,
  accion           ENUM('CREAR','MODIFICAR','CANCELAR','LOGIN','LOGOUT') NOT NULL,
  datos_anteriores JSON                NULL,
  datos_nuevos     JSON                NULL,
  fecha_hora       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  direccion_ip     VARCHAR(45)         NULL,
  PRIMARY KEY (id_auditoria),
  KEY idx_auditoria_tabla (tabla_afectada, registro_id),
  KEY idx_auditoria_admin (id_admin, fecha_hora),
  CONSTRAINT fk_auditoria_admin
    FOREIGN KEY (id_admin) REFERENCES ADMINISTRADOR (id_admin)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Quién cambió qué y cuándo. ON DELETE RESTRICT: un admin con historial no se borra.';

-- =====================================================================
--  DATOS DE PRUEBA
--  Contraseñas de laboratorio:
--    clientes  -> Client1234
--    admin     -> Admin1234
--    operador  -> Oper1234
--  Son credenciales de demostración, no de producción.
-- =====================================================================

-- 3 parkings de Tarragona ---------------------------------------------
INSERT INTO PARKING
  (id_parking, nombre, direccion, ciudad, descripcion, capacidad_total, horario, latitud, longitud, activo)
VALUES
  (1, 'Parc Central', 'Av. Vidal i Barraquer, 15', 'Tarragona',
   'Aparcamiento subterráneo en el centro de la ciudad, junto al mercado.', 12, '24 h', 41.1194280, 1.2456710, 1),
  (2, 'Port Esportiu', 'Moll de Llevant, s/n', 'Tarragona',
   'Aparcamiento descubierto junto al puerto deportivo.', 12, '24 h', 41.1068440, 1.2400980, 1),
  (3, 'Estació de Trens', 'Pl. Pedrera, s/n', 'Tarragona',
   'Aparcamiento disuasorio de la estación, con plazas para moto.', 12, '06:00 - 23:00', 41.1209090, 1.2439960, 1);

-- 36 plazas: 12 por parking -------------------------------------------
INSERT INTO PLAZA (id_plaza, id_parking, codigo_plaza, planta, tipo_plaza, estado) VALUES
  ( 1, 1, 'A-01', -1, 'ESTANDAR',  'LIBRE'),
  ( 2, 1, 'A-02', -1, 'ESTANDAR',  'LIBRE'),
  ( 3, 1, 'A-03', -1, 'ESTANDAR',  'LIBRE'),
  ( 4, 1, 'A-04', -1, 'ESTANDAR',  'LIBRE'),
  ( 5, 1, 'A-05', -1, 'ESTANDAR',  'LIBRE'),
  ( 6, 1, 'A-06', -1, 'ESTANDAR',  'LIBRE'),
  ( 7, 1, 'A-07', -1, 'ESTANDAR',  'LIBRE'),
  ( 8, 1, 'A-08', -1, 'ESTANDAR',  'LIBRE'),
  ( 9, 1, 'A-09', -1, 'ADAPTADA',  'LIBRE'),
  (10, 1, 'A-10', -1, 'ADAPTADA',  'LIBRE'),
  (11, 1, 'A-11', -1, 'ELECTRICA', 'LIBRE'),
  (12, 1, 'A-12',  0, 'MOTO',      'LIBRE'),
  (13, 2, 'B-01',  0, 'ESTANDAR',  'LIBRE'),
  (14, 2, 'B-02',  0, 'ESTANDAR',  'LIBRE'),
  (15, 2, 'B-03',  0, 'ESTANDAR',  'LIBRE'),
  (16, 2, 'B-04',  0, 'ESTANDAR',  'LIBRE'),
  (17, 2, 'B-05',  0, 'ESTANDAR',  'LIBRE'),
  (18, 2, 'B-06',  0, 'ESTANDAR',  'LIBRE'),
  (19, 2, 'B-07',  0, 'ESTANDAR',  'LIBRE'),
  (20, 2, 'B-08',  0, 'ESTANDAR',  'LIBRE'),
  (21, 2, 'B-09',  0, 'ADAPTADA',  'LIBRE'),
  (22, 2, 'B-10',  0, 'ADAPTADA',  'LIBRE'),
  (23, 2, 'B-11',  0, 'ELECTRICA', 'LIBRE'),
  (24, 2, 'B-12',  0, 'MOTO',      'LIBRE'),
  (25, 3, 'C-01',  0, 'ESTANDAR',  'LIBRE'),
  (26, 3, 'C-02',  0, 'ESTANDAR',  'LIBRE'),
  (27, 3, 'C-03',  0, 'ESTANDAR',  'LIBRE'),
  (28, 3, 'C-04',  0, 'ESTANDAR',  'LIBRE'),
  (29, 3, 'C-05',  0, 'ESTANDAR',  'LIBRE'),
  (30, 3, 'C-06',  0, 'ESTANDAR',  'LIBRE'),
  (31, 3, 'C-07',  0, 'ESTANDAR',  'LIBRE'),
  (32, 3, 'C-08',  0, 'ESTANDAR',  'LIBRE'),
  (33, 3, 'C-09',  0, 'ADAPTADA',  'LIBRE'),
  (34, 3, 'C-10',  0, 'ADAPTADA',  'LIBRE'),
  (35, 3, 'C-11',  0, 'ELECTRICA', 'FUERA_SERVICIO'),
  (36, 3, 'C-12',  0, 'MOTO',      'LIBRE');

-- 7 tarifas: una estándar por parking y las especiales de Parc Central -
INSERT INTO TARIFA
  (id_tarifa, id_parking, tipo_plaza, descripcion, precio_hora, porcentaje_gestion,
   fecha_inicio_vigencia, fecha_fin_vigencia, activa)
VALUES
  (1, 1, 'ESTANDAR',  'Tarifa general Parc Central',        1.80, 0.1000, '2026-01-01', NULL, 1),
  (2, 1, 'ADAPTADA',  'Tarifa plaza adaptada Parc Central', 1.50, 0.1000, '2026-01-01', NULL, 1),
  (3, 1, 'ELECTRICA', 'Tarifa plaza eléctrica Parc Central',2.20, 0.1000, '2026-01-01', NULL, 1),
  (4, 1, 'MOTO',      'Tarifa moto Parc Central',           1.00, 0.1000, '2026-01-01', NULL, 1),
  (5, 2, 'ESTANDAR',  'Tarifa general Port Esportiu',       1.50, 0.1000, '2026-01-01', NULL, 1),
  (6, 2, 'MOTO',      'Tarifa moto Port Esportiu',          0.90, 0.1000, '2026-01-01', NULL, 1),
  (7, 3, 'ESTANDAR',  'Tarifa general Estació de Trens',    1.20, 0.1000, '2026-01-01', NULL, 1);

-- 5 clientes ----------------------------------------------------------
INSERT INTO CLIENTE
  (id_cliente, nombre, apellidos, email, telefono, documento_ident, password_hash, fecha_registro, estado)
VALUES
  (1, 'Pere',     'Brau Martínez',       'pere.brau@example.com',      '600111222', '11111111H',
   '$2y$12$nA1RS1ElAeNRQFmtVAzhPuJZ8124bBInJHLrsogioliCc7N3jYFXm', '2026-09-15 10:00:00', 'ACTIVO'),
  (2, 'Dhruva',   'Pankajkumar Mehta',   'dhruva.mehta@example.com',   '600333444', '22222222J',
   '$2y$12$nA1RS1ElAeNRQFmtVAzhPuJZ8124bBInJHLrsogioliCc7N3jYFXm', '2026-09-18 11:30:00', 'ACTIVO'),
  (3, 'Boubacar', 'Seck',                'boubacar.seck@example.com',  '600555666', '33333333K',
   '$2y$12$nA1RS1ElAeNRQFmtVAzhPuJZ8124bBInJHLrsogioliCc7N3jYFXm', '2026-09-20 09:15:00', 'ACTIVO'),
  (4, 'Laura',    'García Pons',         'laura.garcia@example.com',   '600777888', '44444444L',
   '$2y$12$nA1RS1ElAeNRQFmtVAzhPuJZ8124bBInJHLrsogioliCc7N3jYFXm', '2026-10-01 17:45:00', 'ACTIVO'),
  (5, 'Marc',     'Vidal Ferrer',        'marc.vidal@example.com',     '600999000', '55555555M',
   '$2y$12$nA1RS1ElAeNRQFmtVAzhPuJZ8124bBInJHLrsogioliCc7N3jYFXm', '2026-10-05 08:20:00', 'ACTIVO');

-- 2 administradores ---------------------------------------------------
INSERT INTO ADMINISTRADOR (id_admin, nombre, email, password_hash, rol, activo, fecha_alta) VALUES
  (1, 'Administrador Tarraco', 'admin@tarraco.local',
   '$2y$12$aJLXsIuB5ShUgWMwwkSvau.tWF2kuRHdSKnKmZVJS07LGF3agJWka', 'SUPERADMIN', 1, '2026-09-01 08:00:00'),
  (2, 'Operador de tráfico',   'operador@tarraco.local',
   '$2y$12$IUZ4PJ1StCCnVQZjWuUlpO/C47BoWth39lo/tBZpWFNzSZbQWgIwy', 'OPERADOR', 1, '2026-09-10 08:00:00');

-- 10 vehículos: dos por cliente ---------------------------------------
INSERT INTO VEHICULO (id_vehiculo, id_cliente, matricula, marca, modelo, color, tipo_vehiculo, activo) VALUES
  ( 1, 1, '1234JKL', 'Seat',      'Ibiza',   'Rojo',     'COCHE',    1),
  ( 2, 1, '5678MNP', 'Renault',   'Clio',    'Blanco',   'COCHE',    1),
  ( 3, 2, '2345JKM', 'Toyota',    'Corolla', 'Gris',     'COCHE',    1),
  ( 4, 2, '6789MNQ', 'Yamaha',    'NMAX',    'Negro',    'MOTO',     1),
  ( 5, 3, '3456JKN', 'Ford',      'Focus',   'Azul',     'COCHE',    1),
  ( 6, 3, '7890MNR', 'Volkswagen','Golf',    'Plata',    'COCHE',    1),
  ( 7, 4, '4567JKP', 'Citroën',   'C4',      'Verde',    'COCHE',    1),
  ( 8, 4, '8901MNS', 'Honda',     'PCX',     'Blanco',   'MOTO',     1),
  ( 9, 5, '5678JKQ', 'Peugeot',   '308',     'Negro',    'COCHE',    1),
  (10, 5, '9012MNT', 'Tesla',     'Model 3', 'Blanco',   'COCHE',    1);

-- 10 métodos de pago: uno por vehículo, del mismo cliente -------------
INSERT INTO METODO_PAGO (id_metodo_pago, id_cliente, tipo, descripcion, token, por_defecto, activo) VALUES
  ( 1, 1, 'TARJETA',       'Visa terminada en 4242',      'tok_demo_0001', 1, 1),
  ( 2, 2, 'TARJETA',       'Mastercard terminada en 4444','tok_demo_0002', 1, 1),
  ( 3, 3, 'PAYPAL',        'Cuenta PayPal personal',      'tok_demo_0003', 1, 1),
  ( 4, 4, 'TARJETA',       'Visa terminada en 1111',      'tok_demo_0004', 1, 1),
  ( 5, 5, 'BIZUM',         'Teléfono 600999000',         'tok_demo_0005', 1, 1),
  ( 6, 1, 'TRANSFERENCIA', 'Cuenta corriente',            'tok_demo_0006', 0, 1),
  ( 7, 2, 'TARJETA',       'Visa terminada en 9999',      'tok_demo_0007', 0, 1),
  ( 8, 3, 'TARJETA',       'Visa terminada en 3333',      'tok_demo_0008', 0, 1),
  ( 9, 4, 'PAYPAL',        'Cuenta PayPal de empresa',    'tok_demo_0009', 0, 1),
  (10, 5, 'TARJETA',       'Visa terminada en 7777',      'tok_demo_0010', 0, 1);

-- 20 reservas repartidas por todos los estados ------------------------
-- Los importes respetan horas * precio_hora + 10 % de gestión.
INSERT INTO RESERVA
  (id_reserva, id_cliente, id_vehiculo, id_parking, id_plaza, id_tarifa, codigo_reserva,
   fecha_hora_entrada_prevista, fecha_hora_salida_prevista,
   fecha_hora_entrada_real, fecha_hora_salida_real,
   estado, importe_base, importe_gestion, importe_total, importe_exceso, observaciones, fecha_creacion)
VALUES
  ( 1, 1,  1, 1,  1, 1, 'TR-2026-000001',
   NOW() - INTERVAL 1 HOUR,          NOW() + INTERVAL 3 HOUR,
   NOW() - INTERVAL 55 MINUTE,       NULL,
   'EN_CURSO',          7.20,  0.72,  7.92,  0.00, 'Vehículo dentro del parking.', NOW() - INTERVAL 2 HOUR),
  ( 2, 2,  3, 1,  2, 1, 'TR-2026-000002',
   NOW() + INTERVAL 2 HOUR,          NOW() + INTERVAL 6 HOUR,
   NULL,                             NULL,
   'ACTIVA',            7.20,  0.72,  7.92,  0.00, NULL, NOW() - INTERVAL 1 DAY),
  ( 3, 3,  5, 1,  3, 1, 'TR-2026-000003',
   NOW() - INTERVAL 3 HOUR,          NOW() - INTERVAL 1 HOUR,
   NOW() - INTERVAL 178 MINUTE,      NOW() - INTERVAL 70 MINUTE,
   'FINALIZADA',        3.60,  0.36,  3.96,  0.00, NULL, NOW() - INTERVAL 2 DAY),
  ( 4, 4,  7, 2, 13, 5, 'TR-2026-000004',
   NOW() + INTERVAL 1 HOUR,          NOW() + INTERVAL 5 HOUR,
   NULL,                             NULL,
   'ACTIVA',            6.00,  0.60,  6.60,  0.00, NULL, NOW() - INTERVAL 1 DAY),
  ( 5, 5,  9, 2, 14, 5, 'TR-2026-000005',
   NOW() - INTERVAL 1 DAY - INTERVAL 4 HOUR, NOW() - INTERVAL 1 DAY,
   NOW() - INTERVAL 1 DAY - INTERVAL 238 MINUTE, NOW() - INTERVAL 1 DAY - INTERVAL 3 MINUTE,
   'FINALIZADA',        6.00,  0.60,  6.60,  0.00, NULL, NOW() - INTERVAL 2 DAY),
  ( 6, 1,  2, 1,  4, 1, 'TR-2026-000006',
   NOW() + INTERVAL 1 DAY,           NOW() + INTERVAL 1 DAY + INTERVAL 4 HOUR,
   NULL,                             NULL,
   'ACTIVA',            7.20,  0.72,  7.92,  0.00, NULL, NOW() - INTERVAL 12 HOUR),
  ( 7, 2,  3, 1,  5, 1, 'TR-2026-000007',
   NOW() + INTERVAL 1 DAY + INTERVAL 7 HOUR, NOW() + INTERVAL 1 DAY + INTERVAL 12 HOUR,
   NULL,                             NULL,
   'ACTIVA',            9.00,  0.90,  9.90,  0.00, NULL, NOW() - INTERVAL 10 HOUR),
  ( 8, 3,  6, 2, 15, 5, 'TR-2026-000008',
   NOW() + INTERVAL 2 DAY,           NOW() + INTERVAL 2 DAY + INTERVAL 4 HOUR,
   NULL,                             NULL,
   'PENDIENTE_PAGO',    6.00,  0.60,  6.60,  0.00, 'Pago no confirmado por la pasarela.', NOW() - INTERVAL 5 MINUTE),
  ( 9, 4,  8, 3, 25, 7, 'TR-2026-000009',
   NOW() - INTERVAL 1 DAY - INTERVAL 8 HOUR, NOW() - INTERVAL 1 DAY - INTERVAL 5 HOUR,
   NULL,                             NULL,
   'NO_PRESENTADA',     3.60,  0.36,  3.96,  0.00, 'El vehículo no llegó a entrar.', NOW() - INTERVAL 3 DAY),
  (10, 5, 10, 3, 26, 7, 'TR-2026-000010',
   NOW() - INTERVAL 2 DAY - INTERVAL 6 HOUR, NOW() - INTERVAL 2 DAY - INTERVAL 2 HOUR,
   NOW() - INTERVAL 2 DAY - INTERVAL 6 HOUR + INTERVAL 2 MINUTE,
   NOW() - INTERVAL 2 DAY + INTERVAL 1 HOUR,
   'CON_EXCESO',        4.80,  0.48,  5.28,  3.60, 'Salida tres horas después de lo previsto.', NOW() - INTERVAL 4 DAY),
  (11, 1,  1, 1,  6, 1, 'TR-2026-000011',
   NOW() - INTERVAL 3 DAY,           NOW() - INTERVAL 3 DAY + INTERVAL 2 HOUR,
   NULL,                             NULL,
   'CANCELADA',         3.60,  0.36,  3.96,  0.00, 'Cancelada por el cliente con antelación.', NOW() - INTERVAL 5 DAY),
  (12, 2,  3, 2, 16, 5, 'TR-2026-000012',
   NOW() - INTERVAL 4 DAY,           NOW() - INTERVAL 4 DAY + INTERVAL 3 HOUR,
   NOW() - INTERVAL 4 DAY + INTERVAL 3 MINUTE, NOW() - INTERVAL 4 DAY + INTERVAL 3 HOUR,
   'FINALIZADA',        4.50,  0.45,  4.95,  0.00, NULL, NOW() - INTERVAL 6 DAY),
  (13, 3,  5, 3, 27, 7, 'TR-2026-000013',
   NOW() + INTERVAL 4 HOUR,          NOW() + INTERVAL 10 HOUR,
   NULL,                             NULL,
   'ACTIVA',            7.20,  0.72,  7.92,  0.00, NULL, NOW() - INTERVAL 2 HOUR),
  (14, 4,  7, 1,  7, 1, 'TR-2026-000014',
   NOW() + INTERVAL 6 HOUR,          NOW() + INTERVAL 11 HOUR,
   NULL,                             NULL,
   'ACTIVA',            9.00,  0.90,  9.90,  0.00, NULL, NOW() - INTERVAL 3 HOUR),
  (15, 5,  9, 1,  8, 1, 'TR-2026-000015',
   NOW() + INTERVAL 1 DAY + INTERVAL 2 HOUR, NOW() + INTERVAL 1 DAY + INTERVAL 4 HOUR,
   NULL,                             NULL,
   'ACTIVA',            3.60,  0.36,  3.96,  0.00, NULL, NOW() - INTERVAL 8 HOUR),
  (16, 1,  2, 3, 28, 7, 'TR-2026-000016',
   NOW() - INTERVAL 5 DAY + INTERVAL 5 HOUR, NOW() - INTERVAL 5 DAY + INTERVAL 9 HOUR,
   NOW() - INTERVAL 5 DAY + INTERVAL 5 HOUR + INTERVAL 4 MINUTE, NOW() - INTERVAL 5 DAY + INTERVAL 9 HOUR,
   'FINALIZADA',        4.80,  0.48,  5.28,  0.00, NULL, NOW() - INTERVAL 7 DAY),
  (17, 2,  4, 2, 24, 6, 'TR-2026-000017',
   NOW() - INTERVAL 6 DAY + INTERVAL 3 HOUR, NOW() - INTERVAL 6 DAY + INTERVAL 6 HOUR,
   NOW() - INTERVAL 6 DAY + INTERVAL 3 HOUR + INTERVAL 1 MINUTE, NOW() - INTERVAL 6 DAY + INTERVAL 6 HOUR,
   'FINALIZADA',        2.70,  0.27,  2.97,  0.00, NULL, NOW() - INTERVAL 8 DAY),
  (18, 3,  6, 1,  9, 2, 'TR-2026-000018',
   NOW() - INTERVAL 7 DAY + INTERVAL 2 HOUR, NOW() - INTERVAL 7 DAY + INTERVAL 4 HOUR,
   NOW() - INTERVAL 7 DAY + INTERVAL 2 HOUR + INTERVAL 2 MINUTE, NOW() - INTERVAL 7 DAY + INTERVAL 4 HOUR,
   'FINALIZADA',        3.00,  0.30,  3.30,  0.00, NULL, NOW() - INTERVAL 9 DAY),
  (19, 4,  8, 2, 18, 5, 'TR-2026-000019',
   NOW() + INTERVAL 1 DAY + INTERVAL 14 HOUR, NOW() + INTERVAL 1 DAY + INTERVAL 17 HOUR,
   NULL,                             NULL,
   'ACTIVA',            4.50,  0.45,  4.95,  0.00, NULL, NOW() - INTERVAL 6 HOUR),
  (20, 5, 10, 1, 10, 2, 'TR-2026-000020',
   NOW() - INTERVAL 8 DAY + INTERVAL 4 HOUR, NOW() - INTERVAL 8 DAY + INTERVAL 6 HOUR,
   NOW() - INTERVAL 8 DAY + INTERVAL 4 HOUR + INTERVAL 1 MINUTE, NOW() - INTERVAL 8 DAY + INTERVAL 6 HOUR,
   'FINALIZADA',        3.00,  0.30,  3.30,  0.00, NULL, NOW() - INTERVAL 10 DAY);

-- 21 pagos: uno por reserva y el exceso de la reserva 10 --------------
INSERT INTO PAGO
  (id_pago, id_reserva, id_metodo_pago, tipo_pago, importe, estado, fecha_hora, referencia_transaccion)
VALUES
  ( 1,  1,  1, 'RESERVA',  7.92, 'CONFIRMADO', NOW() - INTERVAL 2 HOUR,  'TX-0001'),
  ( 2,  2,  2, 'RESERVA', 15.84, 'CONFIRMADO', NOW() - INTERVAL 1 DAY,   'TX-0002'),
  ( 3,  3,  3, 'RESERVA',  3.96, 'CONFIRMADO', NOW() - INTERVAL 2 DAY,   'TX-0003'),
  ( 4,  4,  4, 'RESERVA',  6.60, 'CONFIRMADO', NOW() - INTERVAL 1 DAY,   'TX-0004'),
  ( 5,  5,  5, 'RESERVA',  6.60, 'CONFIRMADO', NOW() - INTERVAL 2 DAY,   'TX-0005'),
  -- La reserva 5 termina tres minutos antes de lo previsto: sin exceso.
  ( 6,  6,  1, 'RESERVA',  7.92, 'CONFIRMADO', NOW() - INTERVAL 12 HOUR, 'TX-0006'),
  ( 7,  7,  2, 'RESERVA',  9.90, 'CONFIRMADO', NOW() - INTERVAL 10 HOUR, 'TX-0007'),
  ( 8,  8,  3, 'RESERVA',  6.60, 'PENDIENTE',  NOW() - INTERVAL 5 MINUTE,'TX-0008'),
  ( 9,  9,  4, 'RESERVA',  3.96, 'CONFIRMADO', NOW() - INTERVAL 3 DAY,   'TX-0009'),
  (10, 10,  5, 'RESERVA',  5.28, 'CONFIRMADO', NOW() - INTERVAL 4 DAY,   'TX-0010'),
  (11, 10,  5, 'EXCESO',   3.60, 'CONFIRMADO', NOW() - INTERVAL 2 DAY,   'TX-0011'),
  (12, 11,  1, 'RESERVA',  3.96, 'REEMBOLSADO',NOW() - INTERVAL 5 DAY,   'TX-0012'),
  (13, 12,  2, 'RESERVA',  4.95, 'CONFIRMADO', NOW() - INTERVAL 6 DAY,   'TX-0013'),
  (14, 13,  3, 'RESERVA',  7.92, 'CONFIRMADO', NOW() - INTERVAL 2 HOUR,  'TX-0014'),
  (15, 14,  4, 'RESERVA',  9.90, 'CONFIRMADO', NOW() - INTERVAL 3 HOUR,  'TX-0015'),
  (16, 15,  5, 'RESERVA',  3.96, 'CONFIRMADO', NOW() - INTERVAL 8 HOUR,  'TX-0016'),
  (17, 16,  1, 'RESERVA',  5.28, 'CONFIRMADO', NOW() - INTERVAL 7 DAY,   'TX-0017'),
  (18, 17,  2, 'RESERVA',  2.97, 'CONFIRMADO', NOW() - INTERVAL 8 DAY,   'TX-0018'),
  (19, 18,  3, 'RESERVA',  3.30, 'CONFIRMADO', NOW() - INTERVAL 9 DAY,   'TX-0019'),
  (20, 19,  4, 'RESERVA',  4.95, 'CONFIRMADO', NOW() - INTERVAL 6 HOUR,  'TX-0020'),
  (21, 20,  5, 'RESERVA',  3.30, 'CONFIRMADO', NOW() - INTERVAL 10 DAY,  'TX-0021');

-- 14 registros de acceso: entradas, salidas y una denegación -----------
INSERT INTO REGISTRO_ACCESO
  (id_registro_acceso, id_parking, id_vehiculo, id_reserva, matricula_leida, tipo_movimiento,
   fecha_hora, autorizado, motivo_denegacion, observaciones)
VALUES
  ( 1, 1,  1,  1, '1234JKL', 'ENTRADA', NOW() - INTERVAL 55 MINUTE, 1, NULL, 'Simulación de lectura correcta.'),
  ( 2, 1,  5,  3, '3456JKN', 'ENTRADA', NOW() - INTERVAL 178 MINUTE, 1, NULL, NULL),
  ( 3, 1,  5,  3, '3456JKN', 'SALIDA',  NOW() - INTERVAL 65 MINUTE,  1, NULL, NULL),
  ( 4, 2,  9,  5, '5678JKQ', 'ENTRADA', NOW() - INTERVAL 1 DAY - INTERVAL 238 MINUTE, 1, NULL, NULL),
  ( 5, 2,  9,  5, '5678JKQ', 'SALIDA',  NOW() - INTERVAL 1 DAY + INTERVAL 5 MINUTE,   1, NULL, NULL),
  ( 6, 3, 10, 10, '9012MNT', 'ENTRADA', NOW() - INTERVAL 2 DAY - INTERVAL 6 HOUR + INTERVAL 2 MINUTE, 1, NULL, NULL),
  ( 7, 3, 10, 10, '9012MNT', 'SALIDA',  NOW() - INTERVAL 2 DAY + INTERVAL 1 HOUR,     1, NULL, 'Exceso de tres horas.'),
  ( 8, 2,  3, 12, '2345JKM', 'ENTRADA', NOW() - INTERVAL 4 DAY + INTERVAL 3 MINUTE,   1, NULL, NULL),
  ( 9, 2,  3, 12, '2345JKM', 'SALIDA',  NOW() - INTERVAL 4 DAY + INTERVAL 3 HOUR,     1, NULL, NULL),
  (10, 3,  9, NULL, '5678JKQ', 'ENTRADA', NOW() - INTERVAL 1 DAY - INTERVAL 2 HOUR, 0,
   'El vehículo no tiene ninguna reserva activa en este parking.', 'Intento de acceso sin reserva.'),
  (11, 3,  8,  9, '8901MNS', 'ENTRADA', NOW() - INTERVAL 1 DAY - INTERVAL 7 HOUR,   0,
   'La reserva no está activa (NO_PRESENTADA).', NULL),
  (12, 1,  2, 16, '5678MNP', 'ENTRADA', NOW() - INTERVAL 5 DAY + INTERVAL 5 HOUR + INTERVAL 4 MINUTE, 1, NULL, NULL),
  (13, 1,  2, 16, '5678MNP', 'SALIDA',  NOW() - INTERVAL 5 DAY + INTERVAL 9 HOUR,     1, NULL, NULL),
  (14, 1,  6, 18, '7890MNR', 'ENTRADA', NOW() - INTERVAL 7 DAY + INTERVAL 2 HOUR + INTERVAL 2 MINUTE, 1, NULL, NULL);

-- La única plaza ocupada al cargar los datos es la de la reserva en curso.
UPDATE PLAZA SET estado = 'OCUPADA' WHERE id_plaza = 1;

-- 3 acciones de auditoría de ejemplo --------------------------------
INSERT INTO REGISTRO_AUDITORIA
  (id_auditoria, id_admin, tabla_afectada, registro_id, accion, datos_anteriores, datos_nuevos, fecha_hora, direccion_ip)
VALUES
  (1, 1, 'RESERVA', 10, 'MODIFICAR',
   JSON_OBJECT('estado', 'EN_CURSO', 'importe_exceso', 0.00),
   JSON_OBJECT('estado', 'CON_EXCESO', 'importe_exceso', 3.60),
   NOW() - INTERVAL 2 DAY, '172.18.0.1'),
  (2, 1, 'TARIFA', 7, 'CREAR', NULL,
   JSON_OBJECT('id_tarifa', 7, 'precio_hora', 1.20),
   NOW() - INTERVAL 12 DAY, '172.18.0.1'),
  (3, 2, 'RESERVA', 11, 'CANCELAR',
   JSON_OBJECT('estado', 'ACTIVA'),
   JSON_OBJECT('estado', 'CANCELADA', 'motivo', 'Petición del cliente'),
   NOW() - INTERVAL 5 DAY, '172.18.0.1');

-- =====================================================================
--  GARANTÍAS EN LA BASE DE DATOS
--  No dependen del código PHP: cualquier cliente que se conecte las cumple.
-- =====================================================================
DELIMITER $$

-- Una reserva no se borra nunca. El intento falla con un error explícito.
CREATE TRIGGER trg_reserva_sin_borrado
BEFORE DELETE ON RESERVA
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Las reservas no se borran nunca: solo cambian de estado.';
END$$

-- El ciclo de vida solo avanza por las transiciones previstas.
CREATE TRIGGER trg_reserva_transicion
BEFORE UPDATE ON RESERVA
FOR EACH ROW
BEGIN
  IF OLD.estado <> NEW.estado THEN
    IF NOT (
         (OLD.estado = 'PENDIENTE_PAGO' AND NEW.estado IN ('ACTIVA','CANCELADA'))
      OR (OLD.estado = 'ACTIVA' AND NEW.estado IN ('EN_CURSO','CANCELADA','NO_PRESENTADA'))
      OR (OLD.estado = 'EN_CURSO' AND NEW.estado IN ('FINALIZADA','CON_EXCESO'))
    ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Transicion de estado de reserva no permitida.';
    END IF;
  END IF;
END$$

DELIMITER ;

-- =====================================================================
--  Comprobaciones rápidas (opcionales, se pueden lanzar a mano)
-- =====================================================================
-- SELECT COUNT(*) FROM CLIENTE;                -- 5
-- SELECT COUNT(*) FROM VEHICULO;               -- 10
-- SELECT COUNT(*) FROM RESERVA;                -- 20
-- SELECT COUNT(*) FROM PAGO;                   -- 21
-- SELECT estado, COUNT(*) FROM RESERVA GROUP BY estado;
