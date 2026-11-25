-- Esquema de Gestión ganadera para MySQL / MariaDB (motor InnoDB)
--
-- Es el motor de producción: el que ofrece cPanel. Las tablas se crean solas la
-- primera vez que alguien abre el sistema.
--
-- Multi-tenant por discriminador: toda tabla de negocio lleva organizacion_id y
-- toda consulta filtra por él.
--
-- Los números con decimales van en DOUBLE y no en DECIMAL a propósito: PDO
-- devuelve DECIMAL como texto, y el código hace cuentas con esos valores.
--
-- Nota para quien edite: este archivo se divide por punto y coma al ejecutarse,
-- así que los comentarios no pueden llevar ese carácter.

CREATE TABLE IF NOT EXISTS organizaciones (
  id      INT NOT NULL AUTO_INCREMENT,
  nombre  VARCHAR(150) NOT NULL,
  rut     VARCHAR(20) NULL,
  activa  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_organizaciones_rut (rut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
  id               INT NOT NULL AUTO_INCREMENT,
  email            VARCHAR(190) NOT NULL,
  password_hash    VARCHAR(255) NOT NULL,
  nombre           VARCHAR(80) NOT NULL,
  apellido         VARCHAR(80) NOT NULL,
  telefono         VARCHAR(30) NULL,
  activo           TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_acceso_en DATETIME NULL,
  creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El rol vive en la membresía: la misma persona puede ser veterinaria en un
-- campo y administradora en otro.
CREATE TABLE IF NOT EXISTS membresias (
  id                          INT NOT NULL AUTO_INCREMENT,
  organizacion_id             INT NOT NULL,
  usuario_id                  INT NOT NULL,
  rol                         VARCHAR(30) NOT NULL,
  establecimientos_permitidos VARCHAR(255) NOT NULL DEFAULT '[]',
  activa                      TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_membresias (organizacion_id, usuario_id),
  CONSTRAINT fk_membresias_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_membresias_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS establecimientos (
  id              INT NOT NULL AUTO_INCREMENT,
  organizacion_id INT NOT NULL,
  nombre          VARCHAR(150) NOT NULL,
  tipo            VARCHAR(30) NOT NULL DEFAULT 'FEEDLOT',
  rup             VARCHAR(30) NULL,
  comuna          VARCHAR(80) NULL,
  region          VARCHAR(80) NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_establecimientos_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS corrales (
  id                 INT NOT NULL AUTO_INCREMENT,
  organizacion_id    INT NOT NULL,
  establecimiento_id INT NOT NULL,
  nombre             VARCHAR(80) NOT NULL,
  tipo               VARCHAR(30) NOT NULL DEFAULT 'CORRAL',
  capacidad_maxima   INT NOT NULL,
  metros_comedero    DOUBLE NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_corrales_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_corrales_est FOREIGN KEY (establecimiento_id) REFERENCES establecimientos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lotes (
  id                      INT NOT NULL AUTO_INCREMENT,
  organizacion_id         INT NOT NULL,
  establecimiento_id      INT NOT NULL,
  codigo                  VARCHAR(40) NOT NULL,
  nombre                  VARCHAR(120) NULL,
  origen                  VARCHAR(30) NOT NULL DEFAULT 'COMPRA',
  proveedor               VARCHAR(120) NULL,
  fecha_ingreso           DATE NOT NULL,
  fecha_cierre            DATE NULL,
  estado                  VARCHAR(30) NOT NULL DEFAULT 'EN_ENGORDA',
  cabezas_iniciales       INT NOT NULL,
  cabezas_actuales        INT NOT NULL,
  peso_ingreso_total_kg   DOUBLE NOT NULL DEFAULT 0,
  costo_adquisicion_total DOUBLE NOT NULL DEFAULT 0,
  peso_objetivo_kg        DOUBLE NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_lotes_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_lotes_est FOREIGN KEY (establecimiento_id) REFERENCES establecimientos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pajuelas de semen: el padre de los terneros nacidos por inseminación, que no
-- es un animal del plantel. Va antes de animales porque animales la referencia.
CREATE TABLE IF NOT EXISTS pajuelas (
  id                INT NOT NULL AUTO_INCREMENT,
  organizacion_id   INT NOT NULL,
  codigo            VARCHAR(40) NOT NULL,
  toro              VARCHAR(120) NOT NULL,
  raza              VARCHAR(60) NULL,
  proveedor         VARCHAR(120) NULL,
  dosis_disponibles INT NOT NULL DEFAULT 0,
  costo_unitario    DOUBLE NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pajuelas_codigo (organizacion_id, codigo),
  CONSTRAINT fk_pajuelas_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Identificación dual: DIIO oficial del SAG (caravana visual y su chip EID) y
-- número interno del predio (paleta o crotal). Los tres son únicos dentro de la
-- organización: la base rechaza un duplicado aunque falle la validación del código.
CREATE TABLE IF NOT EXISTS animales (
  id                  INT NOT NULL AUTO_INCREMENT,
  organizacion_id     INT NOT NULL,
  establecimiento_id  INT NOT NULL,
  corral_actual_id    INT NULL,
  lote_actual_id      INT NULL,
  caravana_visual     VARCHAR(40) NULL,
  eid                 VARCHAR(20) NULL,
  numero_interno      VARCHAR(40) NULL,
  sexo                VARCHAR(20) NOT NULL,
  categoria           VARCHAR(20) NOT NULL,
  raza                VARCHAR(60) NULL,
  madre_id            INT NULL,
  padre_id            INT NULL,
  pajuela_id          INT NULL,
  origen              VARCHAR(30) NOT NULL DEFAULT 'COMPRADO',
  etapa               VARCHAR(20) NOT NULL DEFAULT 'ENGORDA',
  fecha_nacimiento    DATE NULL,
  fecha_destete       DATE NULL,
  -- Fin de la lactancia, en lechería
  fecha_secado        DATE NULL,
  fecha_ingreso       DATE NOT NULL,
  peso_ingreso_kg     DOUBLE NOT NULL,
  peso_actual_kg      DOUBLE NULL,
  fecha_ultima_pesada DATE NULL,
  estado              VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
  fecha_egreso        DATE NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_animales_caravana (organizacion_id, caravana_visual),
  UNIQUE KEY uq_animales_eid (organizacion_id, eid),
  UNIQUE KEY uq_animales_interno (organizacion_id, numero_interno),
  KEY idx_animales_org (organizacion_id, estado),
  KEY idx_animales_etapa (organizacion_id, etapa),
  KEY idx_animales_corral (corral_actual_id),
  KEY idx_animales_madre (madre_id),
  CONSTRAINT fk_animales_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_animales_est FOREIGN KEY (establecimiento_id) REFERENCES establecimientos (id),
  CONSTRAINT fk_animales_corral FOREIGN KEY (corral_actual_id) REFERENCES corrales (id),
  CONSTRAINT fk_animales_lote FOREIGN KEY (lote_actual_id) REFERENCES lotes (id),
  CONSTRAINT fk_animales_madre FOREIGN KEY (madre_id) REFERENCES animales (id),
  CONSTRAINT fk_animales_padre FOREIGN KEY (padre_id) REFERENCES animales (id),
  CONSTRAINT fk_animales_pajuela FOREIGN KEY (pajuela_id) REFERENCES pajuelas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pesadas (
  id              INT NOT NULL AUTO_INCREMENT,
  organizacion_id INT NOT NULL,
  animal_id       INT NOT NULL,
  fecha           DATE NOT NULL,
  peso_kg         DOUBLE NOT NULL,
  -- NACIMIENTO, DESTETE, ENCASTE, CONTROL, PREVENTA o SALIDA
  motivo          VARCHAR(20) NOT NULL DEFAULT 'CONTROL',
  -- Anulación: el registro queda, marcado y con su motivo. Borrarlo de verdad
  -- dejaría un hueco en el historial del animal.
  anulada             TINYINT(1) NOT NULL DEFAULT 0,
  anulada_por         INT NULL,
  anulada_en          DATETIME NULL,
  motivo_anulacion    VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_pesadas_animal (animal_id, fecha),
  CONSTRAINT fk_pesadas_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_pesadas_animal FOREIGN KEY (animal_id) REFERENCES animales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS insumos (
  id                      INT NOT NULL AUTO_INCREMENT,
  organizacion_id         INT NOT NULL,
  establecimiento_id      INT NOT NULL,
  nombre                  VARCHAR(120) NOT NULL,
  tipo                    VARCHAR(20) NOT NULL,
  unidad                  VARCHAR(20) NOT NULL DEFAULT 'KILOGRAMO',
  stock_actual            DOUBLE NOT NULL DEFAULT 0,
  stock_minimo            DOUBLE NULL,
  costo_unitario          DOUBLE NOT NULL DEFAULT 0,
  dias_carencia_carne     INT NULL,
  principio_activo        VARCHAR(120) NULL,
  materia_seca_porcentaje DOUBLE NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_insumos_nombre (organizacion_id, nombre),
  CONSTRAINT fk_insumos_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_insumos_est FOREIGN KEY (establecimiento_id) REFERENCES establecimientos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS raciones (
  id                      INT NOT NULL AUTO_INCREMENT,
  organizacion_id         INT NOT NULL,
  nombre                  VARCHAR(80) NOT NULL,
  etapa                   VARCHAR(30) NOT NULL,
  materia_seca_porcentaje DOUBLE NOT NULL,
  costo_por_kg            DOUBLE NOT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_raciones_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Receta de la ración: con qué insumos se prepara y en qué proporción. Es lo que
-- permite descontar el stock de bodega al registrar una entrega.
CREATE TABLE IF NOT EXISTS ingredientes_racion (
  id              INT NOT NULL AUTO_INCREMENT,
  organizacion_id INT NOT NULL,
  racion_id       INT NOT NULL,
  insumo_id       INT NOT NULL,
  -- Participación en la mezcla, en porcentaje sobre kilos tal cual
  porcentaje      DOUBLE NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ingredientes (racion_id, insumo_id),
  CONSTRAINT fk_ingredientes_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_ingredientes_racion FOREIGN KEY (racion_id) REFERENCES raciones (id),
  CONSTRAINT fk_ingredientes_insumo FOREIGN KEY (insumo_id) REFERENCES insumos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Entrega de alimento. Va a un corral completo (lógica de feedlot) o a un animal
-- puntual (lógica de cría): uno de los dos, nunca ambos.
CREATE TABLE IF NOT EXISTS entregas_alimento (
  id              INT NOT NULL AUTO_INCREMENT,
  organizacion_id INT NOT NULL,
  corral_id       INT NULL,
  animal_id       INT NULL,
  lote_id         INT NULL,
  racion_id       INT NOT NULL,
  fecha           DATE NOT NULL,
  numero_entrega  INT NOT NULL DEFAULT 1,
  kg_planificados DOUBLE NULL,
  kg_entregados   DOUBLE NOT NULL,
  kg_materia_seca DOUBLE NOT NULL DEFAULT 0,
  costo_total     DOUBLE NOT NULL,
  responsable_id  INT NULL,
  -- Anulación: el registro queda, marcado y con su motivo. Borrarlo de verdad
  -- dejaría un hueco en el historial del animal.
  anulada             TINYINT(1) NOT NULL DEFAULT 0,
  anulada_por         INT NULL,
  anulada_en          DATETIME NULL,
  motivo_anulacion    VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_entregas_corral (corral_id, fecha),
  KEY idx_entregas_animal (animal_id, fecha),
  CONSTRAINT fk_entregas_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_entregas_corral FOREIGN KEY (corral_id) REFERENCES corrales (id),
  CONSTRAINT fk_entregas_animal FOREIGN KEY (animal_id) REFERENCES animales (id),
  CONSTRAINT fk_entregas_lote FOREIGN KEY (lote_id) REFERENCES lotes (id),
  CONSTRAINT fk_entregas_racion FOREIGN KEY (racion_id) REFERENCES raciones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tratamientos (
  id                  INT NOT NULL AUTO_INCREMENT,
  organizacion_id     INT NOT NULL,
  establecimiento_id  INT NOT NULL,
  corral_id           INT NULL,
  lote_id             INT NULL,
  insumo_id           INT NOT NULL,
  tipo                VARCHAR(30) NOT NULL,
  fecha               DATE NOT NULL,
  via                 VARCHAR(30) NOT NULL DEFAULT 'INTRAMUSCULAR',
  dosis_por_animal    DOUBLE NOT NULL,
  cantidad_animales   INT NOT NULL,
  -- Copiado del producto al aplicar: corregir su ficha después no puede cambiar
  -- la habilitación de faena de un animal ya tratado.
  dias_carencia_carne INT NOT NULL,
  diagnostico         VARCHAR(255) NULL,
  responsable_id      INT NULL,
  costo_total         DOUBLE NOT NULL DEFAULT 0,
  -- Anulación: el registro queda, marcado y con su motivo. Borrarlo de verdad
  -- dejaría un hueco en el historial del animal.
  anulada             TINYINT(1) NOT NULL DEFAULT 0,
  anulada_por         INT NULL,
  anulada_en          DATETIME NULL,
  motivo_anulacion    VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_trat_fecha (organizacion_id, fecha),
  CONSTRAINT fk_trat_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_trat_est FOREIGN KEY (establecimiento_id) REFERENCES establecimientos (id),
  CONSTRAINT fk_trat_corral FOREIGN KEY (corral_id) REFERENCES corrales (id),
  CONSTRAINT fk_trat_lote FOREIGN KEY (lote_id) REFERENCES lotes (id),
  CONSTRAINT fk_trat_insumo FOREIGN KEY (insumo_id) REFERENCES insumos (id),
  CONSTRAINT fk_trat_responsable FOREIGN KEY (responsable_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aplicaciones_tratamiento (
  id              INT NOT NULL AUTO_INCREMENT,
  organizacion_id INT NOT NULL,
  tratamiento_id  INT NOT NULL,
  animal_id       INT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_aplicaciones (tratamiento_id, animal_id),
  KEY idx_aplic_animal (animal_id),
  CONSTRAINT fk_aplic_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_aplic_trat FOREIGN KEY (tratamiento_id) REFERENCES tratamientos (id),
  CONSTRAINT fk_aplic_animal FOREIGN KEY (animal_id) REFERENCES animales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mortalidades (
  id              INT NOT NULL AUTO_INCREMENT,
  organizacion_id INT NOT NULL,
  animal_id       INT NOT NULL,
  lote_id         INT NULL,
  fecha           DATE NOT NULL,
  causa_presunta  VARCHAR(255) NULL,
  -- Anulación: el registro queda, marcado y con su motivo. Borrarlo de verdad
  -- dejaría un hueco en el historial del animal.
  anulada             TINYINT(1) NOT NULL DEFAULT 0,
  anulada_por         INT NULL,
  anulada_en          DATETIME NULL,
  motivo_anulacion    VARCHAR(255) NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_mort_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_mort_animal FOREIGN KEY (animal_id) REFERENCES animales (id),
  CONSTRAINT fk_mort_lote FOREIGN KEY (lote_id) REFERENCES lotes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ventas_hacienda (
  id                INT NOT NULL AUTO_INCREMENT,
  organizacion_id   INT NOT NULL,
  lote_id           INT NULL,
  fecha             DATE NOT NULL,
  destino           VARCHAR(30) NOT NULL DEFAULT 'FRIGORIFICO',
  comprador         VARCHAR(150) NULL,
  cantidad_animales INT NOT NULL,
  peso_total_kg     DOUBLE NOT NULL,
  precio_por_kg     DOUBLE NOT NULL,
  monto_neto        DOUBLE NOT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_ventas_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_ventas_lote FOREIGN KEY (lote_id) REFERENCES lotes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Servicio o encaste. El resultado empieza PENDIENTE y se confirma con el
-- diagnóstico de preñez.
CREATE TABLE IF NOT EXISTS servicios (
  id                INT NOT NULL AUTO_INCREMENT,
  organizacion_id   INT NOT NULL,
  vaca_id           INT NOT NULL,
  tipo              VARCHAR(20) NOT NULL DEFAULT 'MONTA_NATURAL',
  fecha             DATE NOT NULL,
  toro_id           INT NULL,
  pajuela_id        INT NULL,
  resultado         VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
  fecha_diagnostico DATE NULL,
  responsable_id    INT NULL,
  observaciones     VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_servicios_vaca (vaca_id, fecha),
  CONSTRAINT fk_serv_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_serv_vaca FOREIGN KEY (vaca_id) REFERENCES animales (id),
  CONSTRAINT fk_serv_toro FOREIGN KEY (toro_id) REFERENCES animales (id),
  CONSTRAINT fk_serv_pajuela FOREIGN KEY (pajuela_id) REFERENCES pajuelas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parto. Se guardan también los que salieron mal (aborto, ternero débil): son la
-- base de la alerta de descarte.
CREATE TABLE IF NOT EXISTS partos (
  id                 INT NOT NULL AUTO_INCREMENT,
  organizacion_id    INT NOT NULL,
  vaca_id            INT NOT NULL,
  servicio_id        INT NULL,
  fecha              DATE NOT NULL,
  resultado          VARCHAR(20) NOT NULL,
  dificultad         VARCHAR(20) NOT NULL DEFAULT 'NORMAL',
  ternero_id         INT NULL,
  peso_nacimiento_kg DOUBLE NULL,
  ternero_debil      TINYINT(1) NOT NULL DEFAULT 0,
  observaciones      VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_partos_vaca (vaca_id, fecha),
  CONSTRAINT fk_partos_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_partos_vaca FOREIGN KEY (vaca_id) REFERENCES animales (id),
  CONSTRAINT fk_partos_servicio FOREIGN KEY (servicio_id) REFERENCES servicios (id),
  CONSTRAINT fk_partos_ternero FOREIGN KEY (ternero_id) REFERENCES animales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Traslados entre corrales. Sin este registro se pierde dónde estuvo cada
-- animal y cuándo.
CREATE TABLE IF NOT EXISTS movimientos_hacienda (
  id                INT NOT NULL AUTO_INCREMENT,
  organizacion_id   INT NOT NULL,
  tipo              VARCHAR(30) NOT NULL DEFAULT 'TRASLADO_INTERNO',
  fecha             DATE NOT NULL,
  corral_origen_id  INT NULL,
  corral_destino_id INT NULL,
  cantidad_animales INT NOT NULL,
  motivo            VARCHAR(255) NULL,
  responsable_id    INT NULL,
  PRIMARY KEY (id),
  KEY idx_movimientos_fecha (organizacion_id, fecha),
  CONSTRAINT fk_mov_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_mov_origen FOREIGN KEY (corral_origen_id) REFERENCES corrales (id),
  CONSTRAINT fk_mov_destino FOREIGN KEY (corral_destino_id) REFERENCES corrales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS detalles_movimiento (
  id              INT NOT NULL AUTO_INCREMENT,
  organizacion_id INT NOT NULL,
  movimiento_id   INT NOT NULL,
  animal_id       INT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_detalle (movimiento_id, animal_id),
  KEY idx_detalle_animal (animal_id),
  CONSTRAINT fk_det_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_det_mov FOREIGN KEY (movimiento_id) REFERENCES movimientos_hacienda (id),
  CONSTRAINT fk_det_animal FOREIGN KEY (animal_id) REFERENCES animales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Libro de entradas y salidas de bodega. insumos.stock_actual es su saldo.
CREATE TABLE IF NOT EXISTS movimientos_stock (
  id              INT NOT NULL AUTO_INCREMENT,
  organizacion_id INT NOT NULL,
  insumo_id       INT NOT NULL,
  -- COMPRA, CONSUMO_SANITARIO, CONSUMO_ALIMENTACION, AJUSTE o MERMA
  tipo            VARCHAR(30) NOT NULL,
  fecha           DATE NOT NULL,
  cantidad        DOUBLE NOT NULL,
  costo_unitario  DOUBLE NOT NULL DEFAULT 0,
  costo_total     DOUBLE NOT NULL DEFAULT 0,
  referencia_id   INT NULL,
  observaciones   VARCHAR(255) NULL,
  responsable_id  INT NULL,
  PRIMARY KEY (id),
  KEY idx_stock_insumo (insumo_id, fecha),
  CONSTRAINT fk_stock_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_stock_insumo FOREIGN KEY (insumo_id) REFERENCES insumos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tokens de la app de terreno. Se guarda el hash del token, nunca el token: si
-- alguien leyera esta tabla no podría usarlos.
CREATE TABLE IF NOT EXISTS tokens_api (
  id              INT NOT NULL AUTO_INCREMENT,
  organizacion_id INT NOT NULL,
  usuario_id      INT NOT NULL,
  token_hash      CHAR(64) NOT NULL,
  rol             VARCHAR(30) NOT NULL,
  dispositivo     VARCHAR(160) NULL,
  creado_en       DATETIME NOT NULL,
  expira_en       DATETIME NOT NULL,
  ultimo_uso_en   DATETIME NULL,
  revocado        TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tokens_hash (token_hash),
  CONSTRAINT fk_tokens_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_tokens_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Operaciones recibidas desde la app. El identificador único que genera el
-- teléfono hace que un reintento de la sincronización no duplique el registro.
CREATE TABLE IF NOT EXISTS sincronizaciones (
  id              INT NOT NULL AUTO_INCREMENT,
  organizacion_id INT NOT NULL,
  uuid            CHAR(36) NOT NULL,
  tipo            VARCHAR(30) NOT NULL,
  estado          VARCHAR(20) NOT NULL,
  mensaje         VARCHAR(500) NULL,
  entidad_id      INT NULL,
  usuario_id      INT NOT NULL,
  registrado_en   DATETIME NULL,
  recibido_en     DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sincronizaciones (organizacion_id, uuid),
  CONSTRAINT fk_sync_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_sync_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notas sanitarias y de manejo, en texto libre. El productor escribe lo que le
-- pasó al animal (mastitis, cojera) con su fecha. Sin catálogos ni dosis: el
-- detalle de los fármacos es trabajo del veterinario, fuera del sistema.
CREATE TABLE IF NOT EXISTS notas_animal (
  id              INT NOT NULL AUTO_INCREMENT,
  organizacion_id INT NOT NULL,
  animal_id       INT NOT NULL,
  fecha           DATE NOT NULL,
  texto           VARCHAR(500) NOT NULL,
  usuario_id      INT NULL,
  creado_en       DATETIME NOT NULL,
  -- Anulación: el registro queda, marcado y con su motivo. Borrarlo de verdad
  -- dejaría un hueco en el historial del animal.
  anulada             TINYINT(1) NOT NULL DEFAULT 0,
  anulada_por         INT NULL,
  anulada_en          DATETIME NULL,
  motivo_anulacion    VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_notas_animal (animal_id, fecha),
  CONSTRAINT fk_notas_org FOREIGN KEY (organizacion_id) REFERENCES organizaciones (id),
  CONSTRAINT fk_notas_animal FOREIGN KEY (animal_id) REFERENCES animales (id),
  CONSTRAINT fk_notas_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora: quién hizo cada cambio que modifica o borra algo ya cargado, y los
-- hechos de seguridad (ingresos fallidos, sesiones de la app). Sin esto, una
-- corrección hecha a las apuradas no se puede reconstruir después.
CREATE TABLE IF NOT EXISTS bitacora (
  id              INT NOT NULL AUTO_INCREMENT,
  organizacion_id INT NULL,
  usuario_id      INT NULL,
  fecha_hora      DATETIME NOT NULL,
  accion          VARCHAR(40) NOT NULL,
  entidad         VARCHAR(40) NULL,
  entidad_id      INT NULL,
  detalle         VARCHAR(500) NULL,
  origen          VARCHAR(45) NULL,
  PRIMARY KEY (id),
  KEY idx_bitacora_org (organizacion_id, fecha_hora),
  KEY idx_bitacora_accion (accion, fecha_hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Intentos de ingreso fallidos, para frenar la fuerza bruta sin bloquear a quien
-- simplemente se equivocó una vez.
CREATE TABLE IF NOT EXISTS intentos_ingreso (
  id          INT NOT NULL AUTO_INCREMENT,
  identidad   VARCHAR(190) NOT NULL,
  origen      VARCHAR(45) NOT NULL,
  momento     DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_intentos (identidad, momento),
  KEY idx_intentos_origen (origen, momento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
