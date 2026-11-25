-- Esquema de Gestión ganadera para SQLite
--
-- Para desarrollo local sin servidor de base de datos. En producción se usa
-- esquema.mysql.sql: los dos tienen las mismas tablas y columnas.
--
-- Este archivo se divide por punto y coma al ejecutarse, así que los comentarios
-- no pueden llevar ese carácter.

CREATE TABLE IF NOT EXISTS organizaciones (
  id     INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre TEXT NOT NULL,
  rut    TEXT UNIQUE,
  activa INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS usuarios (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  email            TEXT NOT NULL UNIQUE,
  password_hash    TEXT NOT NULL,
  nombre           TEXT NOT NULL,
  apellido         TEXT NOT NULL,
  telefono         TEXT,
  activo           INTEGER NOT NULL DEFAULT 1,
  ultimo_acceso_en TEXT,
  creado_en        TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS membresias (
  id                          INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id             INTEGER NOT NULL REFERENCES organizaciones(id),
  usuario_id                  INTEGER NOT NULL REFERENCES usuarios(id),
  rol                         TEXT NOT NULL,
  establecimientos_permitidos TEXT NOT NULL DEFAULT '[]',
  activa                      INTEGER NOT NULL DEFAULT 1,
  mva_rut                     TEXT,
  mva_numero                  TEXT,
  mva_vigente_hasta           TEXT,
  UNIQUE (organizacion_id, usuario_id)
);

CREATE TABLE IF NOT EXISTS establecimientos (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id INTEGER NOT NULL REFERENCES organizaciones(id),
  nombre          TEXT NOT NULL,
  tipo            TEXT NOT NULL DEFAULT 'FEEDLOT',
  rup             TEXT,
  comuna          TEXT,
  region          TEXT
);

CREATE TABLE IF NOT EXISTS corrales (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id    INTEGER NOT NULL REFERENCES organizaciones(id),
  establecimiento_id INTEGER NOT NULL REFERENCES establecimientos(id),
  nombre             TEXT NOT NULL,
  tipo               TEXT NOT NULL DEFAULT 'CORRAL',
  capacidad_maxima   INTEGER NOT NULL,
  metros_comedero    REAL
);

CREATE TABLE IF NOT EXISTS lotes (
  id                      INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id         INTEGER NOT NULL REFERENCES organizaciones(id),
  establecimiento_id      INTEGER NOT NULL REFERENCES establecimientos(id),
  codigo                  TEXT NOT NULL,
  nombre                  TEXT,
  origen                  TEXT NOT NULL DEFAULT 'COMPRA',
  proveedor               TEXT,
  fecha_ingreso           TEXT NOT NULL,
  fecha_cierre            TEXT,
  estado                  TEXT NOT NULL DEFAULT 'EN_ENGORDA',
  cabezas_iniciales       INTEGER NOT NULL,
  cabezas_actuales        INTEGER NOT NULL,
  peso_ingreso_total_kg   REAL NOT NULL DEFAULT 0,
  costo_adquisicion_total REAL NOT NULL DEFAULT 0,
  peso_objetivo_kg        REAL
);

CREATE TABLE IF NOT EXISTS pajuelas (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id   INTEGER NOT NULL REFERENCES organizaciones(id),
  codigo            TEXT NOT NULL,
  toro              TEXT NOT NULL,
  raza              TEXT,
  proveedor         TEXT,
  dosis_disponibles INTEGER NOT NULL DEFAULT 0,
  costo_unitario    REAL NOT NULL DEFAULT 0,
  UNIQUE (organizacion_id, codigo)
);

CREATE TABLE IF NOT EXISTS animales (
  id                  INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id     INTEGER NOT NULL REFERENCES organizaciones(id),
  establecimiento_id  INTEGER NOT NULL REFERENCES establecimientos(id),
  corral_actual_id    INTEGER REFERENCES corrales(id),
  lote_actual_id      INTEGER REFERENCES lotes(id),
  caravana_visual     TEXT,
  eid                 TEXT,
  numero_interno      TEXT,
  sexo                TEXT NOT NULL,
  categoria           TEXT NOT NULL,
  raza                TEXT,
  madre_id            INTEGER REFERENCES animales(id),
  padre_id            INTEGER REFERENCES animales(id),
  pajuela_id          INTEGER REFERENCES pajuelas(id),
  origen              TEXT NOT NULL DEFAULT 'COMPRADO',
  etapa               TEXT NOT NULL DEFAULT 'ENGORDA',
  fecha_nacimiento    TEXT,
  fecha_destete       TEXT,
  fecha_secado        TEXT,
  fecha_ingreso       TEXT NOT NULL,
  peso_ingreso_kg     REAL NOT NULL,
  peso_actual_kg      REAL,
  fecha_ultima_pesada TEXT,
  estado              TEXT NOT NULL DEFAULT 'ACTIVO',
  fecha_egreso        TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_animales_caravana ON animales(organizacion_id, caravana_visual);
CREATE UNIQUE INDEX IF NOT EXISTS uq_animales_eid      ON animales(organizacion_id, eid);
CREATE UNIQUE INDEX IF NOT EXISTS uq_animales_interno  ON animales(organizacion_id, numero_interno);
CREATE INDEX IF NOT EXISTS idx_animales_org    ON animales(organizacion_id, estado);
CREATE INDEX IF NOT EXISTS idx_animales_etapa  ON animales(organizacion_id, etapa);
CREATE INDEX IF NOT EXISTS idx_animales_corral ON animales(corral_actual_id);
CREATE INDEX IF NOT EXISTS idx_animales_madre  ON animales(madre_id);

CREATE TABLE IF NOT EXISTS pesadas (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id INTEGER NOT NULL REFERENCES organizaciones(id),
  animal_id       INTEGER NOT NULL REFERENCES animales(id),
  fecha           TEXT NOT NULL,
  peso_kg         REAL NOT NULL,
  motivo          TEXT NOT NULL DEFAULT 'CONTROL',
  anulada         INTEGER NOT NULL DEFAULT 0,
  anulada_por     INTEGER,
  anulada_en      TEXT,
  motivo_anulacion TEXT
);
CREATE INDEX IF NOT EXISTS idx_pesadas_animal ON pesadas(animal_id, fecha);

CREATE TABLE IF NOT EXISTS insumos (
  id                      INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id         INTEGER NOT NULL REFERENCES organizaciones(id),
  establecimiento_id      INTEGER NOT NULL REFERENCES establecimientos(id),
  nombre                  TEXT NOT NULL,
  tipo                    TEXT NOT NULL,
  unidad                  TEXT NOT NULL DEFAULT 'KILOGRAMO',
  stock_actual            REAL NOT NULL DEFAULT 0,
  stock_minimo            REAL,
  costo_unitario          REAL NOT NULL DEFAULT 0,
  dias_carencia_carne     INTEGER,
  principio_activo        TEXT,
  materia_seca_porcentaje REAL,
  UNIQUE (organizacion_id, nombre)
);

CREATE TABLE IF NOT EXISTS raciones (
  id                      INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id         INTEGER NOT NULL REFERENCES organizaciones(id),
  nombre                  TEXT NOT NULL,
  etapa                   TEXT NOT NULL,
  materia_seca_porcentaje REAL NOT NULL,
  costo_por_kg            REAL NOT NULL
);

CREATE TABLE IF NOT EXISTS ingredientes_racion (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id INTEGER NOT NULL REFERENCES organizaciones(id),
  racion_id       INTEGER NOT NULL REFERENCES raciones(id),
  insumo_id       INTEGER NOT NULL REFERENCES insumos(id),
  porcentaje      REAL NOT NULL,
  UNIQUE (racion_id, insumo_id)
);

CREATE TABLE IF NOT EXISTS entregas_alimento (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id INTEGER NOT NULL REFERENCES organizaciones(id),
  corral_id       INTEGER REFERENCES corrales(id),
  animal_id       INTEGER REFERENCES animales(id),
  lote_id         INTEGER REFERENCES lotes(id),
  racion_id       INTEGER NOT NULL REFERENCES raciones(id),
  fecha           TEXT NOT NULL,
  numero_entrega  INTEGER NOT NULL DEFAULT 1,
  kg_planificados REAL,
  kg_entregados   REAL NOT NULL,
  kg_materia_seca REAL NOT NULL DEFAULT 0,
  costo_total     REAL NOT NULL,
  responsable_id  INTEGER,
  anulada         INTEGER NOT NULL DEFAULT 0,
  anulada_por     INTEGER,
  anulada_en      TEXT,
  motivo_anulacion TEXT
);
CREATE INDEX IF NOT EXISTS idx_entregas_corral ON entregas_alimento(corral_id, fecha);
CREATE INDEX IF NOT EXISTS idx_entregas_animal ON entregas_alimento(animal_id, fecha);

CREATE TABLE IF NOT EXISTS tratamientos (
  id                  INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id     INTEGER NOT NULL REFERENCES organizaciones(id),
  establecimiento_id  INTEGER NOT NULL REFERENCES establecimientos(id),
  corral_id           INTEGER REFERENCES corrales(id),
  lote_id             INTEGER REFERENCES lotes(id),
  insumo_id           INTEGER NOT NULL REFERENCES insumos(id),
  tipo                TEXT NOT NULL,
  fecha               TEXT NOT NULL,
  via                 TEXT NOT NULL DEFAULT 'INTRAMUSCULAR',
  dosis_por_animal    REAL NOT NULL,
  cantidad_animales   INTEGER NOT NULL,
  dias_carencia_carne INTEGER NOT NULL,
  diagnostico         TEXT,
  responsable_id      INTEGER REFERENCES usuarios(id),
  costo_total         REAL NOT NULL DEFAULT 0,
  anulada         INTEGER NOT NULL DEFAULT 0,
  anulada_por     INTEGER,
  anulada_en      TEXT,
  motivo_anulacion TEXT,
  validado_por        INTEGER REFERENCES usuarios(id),
  validado_en         TEXT,
  validado_mva_rut    TEXT,
  validado_mva_numero TEXT,
  huella              TEXT
);
CREATE INDEX IF NOT EXISTS idx_trat_fecha ON tratamientos(organizacion_id, fecha);

CREATE TABLE IF NOT EXISTS aplicaciones_tratamiento (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id INTEGER NOT NULL REFERENCES organizaciones(id),
  tratamiento_id  INTEGER NOT NULL REFERENCES tratamientos(id),
  animal_id       INTEGER NOT NULL REFERENCES animales(id),
  UNIQUE (tratamiento_id, animal_id)
);
CREATE INDEX IF NOT EXISTS idx_aplic_animal ON aplicaciones_tratamiento(animal_id);

CREATE TABLE IF NOT EXISTS mortalidades (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id INTEGER NOT NULL REFERENCES organizaciones(id),
  animal_id       INTEGER NOT NULL REFERENCES animales(id),
  lote_id         INTEGER REFERENCES lotes(id),
  fecha           TEXT NOT NULL,
  causa_presunta  TEXT,
  anulada         INTEGER NOT NULL DEFAULT 0,
  anulada_por     INTEGER,
  anulada_en      TEXT,
  motivo_anulacion TEXT
);

CREATE TABLE IF NOT EXISTS ventas_hacienda (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id   INTEGER NOT NULL REFERENCES organizaciones(id),
  lote_id           INTEGER REFERENCES lotes(id),
  fecha             TEXT NOT NULL,
  destino           TEXT NOT NULL DEFAULT 'FRIGORIFICO',
  comprador         TEXT,
  cantidad_animales INTEGER NOT NULL,
  peso_total_kg     REAL NOT NULL,
  precio_por_kg     REAL NOT NULL,
  monto_neto        REAL NOT NULL
);

CREATE TABLE IF NOT EXISTS servicios (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id   INTEGER NOT NULL REFERENCES organizaciones(id),
  vaca_id           INTEGER NOT NULL REFERENCES animales(id),
  tipo              TEXT NOT NULL DEFAULT 'MONTA_NATURAL',
  fecha             TEXT NOT NULL,
  toro_id           INTEGER REFERENCES animales(id),
  pajuela_id        INTEGER REFERENCES pajuelas(id),
  resultado         TEXT NOT NULL DEFAULT 'PENDIENTE',
  fecha_diagnostico TEXT,
  responsable_id    INTEGER REFERENCES usuarios(id),
  observaciones     TEXT
);
CREATE INDEX IF NOT EXISTS idx_servicios_vaca ON servicios(vaca_id, fecha);

CREATE TABLE IF NOT EXISTS partos (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id    INTEGER NOT NULL REFERENCES organizaciones(id),
  vaca_id            INTEGER NOT NULL REFERENCES animales(id),
  servicio_id        INTEGER REFERENCES servicios(id),
  fecha              TEXT NOT NULL,
  resultado          TEXT NOT NULL,
  dificultad         TEXT NOT NULL DEFAULT 'NORMAL',
  ternero_id         INTEGER REFERENCES animales(id),
  peso_nacimiento_kg REAL,
  ternero_debil      INTEGER NOT NULL DEFAULT 0,
  observaciones      TEXT
);
CREATE INDEX IF NOT EXISTS idx_partos_vaca ON partos(vaca_id, fecha);

CREATE TABLE IF NOT EXISTS movimientos_hacienda (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id   INTEGER NOT NULL REFERENCES organizaciones(id),
  tipo              TEXT NOT NULL DEFAULT 'TRASLADO_INTERNO',
  fecha             TEXT NOT NULL,
  corral_origen_id  INTEGER REFERENCES corrales(id),
  corral_destino_id INTEGER REFERENCES corrales(id),
  cantidad_animales INTEGER NOT NULL,
  motivo            TEXT,
  responsable_id    INTEGER REFERENCES usuarios(id)
);
CREATE INDEX IF NOT EXISTS idx_movimientos_fecha ON movimientos_hacienda(organizacion_id, fecha);

CREATE TABLE IF NOT EXISTS detalles_movimiento (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id INTEGER NOT NULL REFERENCES organizaciones(id),
  movimiento_id   INTEGER NOT NULL REFERENCES movimientos_hacienda(id),
  animal_id       INTEGER NOT NULL REFERENCES animales(id),
  UNIQUE (movimiento_id, animal_id)
);
CREATE INDEX IF NOT EXISTS idx_detalle_animal ON detalles_movimiento(animal_id);

CREATE TABLE IF NOT EXISTS movimientos_stock (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id INTEGER NOT NULL REFERENCES organizaciones(id),
  insumo_id       INTEGER NOT NULL REFERENCES insumos(id),
  tipo            TEXT NOT NULL,
  fecha           TEXT NOT NULL,
  cantidad        REAL NOT NULL,
  costo_unitario  REAL NOT NULL DEFAULT 0,
  costo_total     REAL NOT NULL DEFAULT 0,
  referencia_id   INTEGER,
  observaciones   TEXT,
  responsable_id  INTEGER REFERENCES usuarios(id)
);
CREATE INDEX IF NOT EXISTS idx_stock_insumo ON movimientos_stock(insumo_id, fecha);

CREATE TABLE IF NOT EXISTS tokens_api (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id INTEGER NOT NULL REFERENCES organizaciones(id),
  usuario_id      INTEGER NOT NULL REFERENCES usuarios(id),
  token_hash      TEXT NOT NULL UNIQUE,
  rol             TEXT NOT NULL,
  dispositivo     TEXT,
  creado_en       TEXT NOT NULL,
  expira_en       TEXT NOT NULL,
  ultimo_uso_en   TEXT,
  revocado        INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS sincronizaciones (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id INTEGER NOT NULL REFERENCES organizaciones(id),
  uuid            TEXT NOT NULL,
  tipo            TEXT NOT NULL,
  estado          TEXT NOT NULL,
  mensaje         TEXT,
  entidad_id      INTEGER,
  usuario_id      INTEGER NOT NULL REFERENCES usuarios(id),
  registrado_en   TEXT,
  recibido_en     TEXT NOT NULL,
  UNIQUE (organizacion_id, uuid)
);

-- Notas sanitarias y de manejo, en texto libre, con su fecha.
CREATE TABLE IF NOT EXISTS notas_animal (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id INTEGER NOT NULL REFERENCES organizaciones(id),
  animal_id       INTEGER NOT NULL REFERENCES animales(id),
  fecha           TEXT NOT NULL,
  texto           TEXT NOT NULL,
  usuario_id      INTEGER REFERENCES usuarios(id),
  creado_en       TEXT NOT NULL,
  anulada         INTEGER NOT NULL DEFAULT 0,
  anulada_por     INTEGER,
  anulada_en      TEXT,
  motivo_anulacion TEXT
);

CREATE INDEX IF NOT EXISTS idx_notas_animal ON notas_animal(animal_id, fecha);

-- Bitácora de cambios y hechos de seguridad.
CREATE TABLE IF NOT EXISTS bitacora (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id INTEGER,
  usuario_id      INTEGER,
  fecha_hora      TEXT NOT NULL,
  accion          TEXT NOT NULL,
  entidad         TEXT,
  entidad_id      INTEGER,
  detalle         TEXT,
  origen          TEXT
);

CREATE INDEX IF NOT EXISTS idx_bitacora_org ON bitacora(organizacion_id, fecha_hora);

CREATE TABLE IF NOT EXISTS intentos_ingreso (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  identidad TEXT NOT NULL,
  origen    TEXT NOT NULL,
  momento   TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_intentos ON intentos_ingreso(identidad, momento);

CREATE TABLE IF NOT EXISTS migraciones (
  nombre      TEXT PRIMARY KEY,
  aplicada_en TEXT NOT NULL
);

-- v1.4: salida de animales del predio (venta, faena o traslado a otro predio).
-- Cada salida es el respaldo del FMA: origen, destino, transporte y qué DIIO salieron.
CREATE TABLE IF NOT EXISTS salidas (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id    INTEGER NOT NULL REFERENCES organizaciones(id),
  establecimiento_id INTEGER NOT NULL REFERENCES establecimientos(id),
  tipo               TEXT NOT NULL,
  fecha              TEXT NOT NULL,
  destino_rup        TEXT NOT NULL,
  destino_nombre     TEXT,
  destino_comuna     TEXT,
  destino_establecimiento_id INTEGER REFERENCES establecimientos(id),
  comprador          TEXT,
  transportista      TEXT,
  transportista_rut  TEXT,
  patente            TEXT,
  cantidad_animales  INTEGER NOT NULL,
  peso_total_kg      REAL,
  precio_por_kg      REAL,
  monto_neto         REAL,
  observaciones      TEXT,
  responsable_id     INTEGER REFERENCES usuarios(id),
  creada_en          TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_salidas_fecha ON salidas(organizacion_id, fecha);

CREATE TABLE IF NOT EXISTS detalles_salida (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id INTEGER NOT NULL REFERENCES organizaciones(id),
  salida_id       INTEGER NOT NULL REFERENCES salidas(id),
  animal_id       INTEGER NOT NULL REFERENCES animales(id),
  diio            TEXT NOT NULL,
  UNIQUE (salida_id, animal_id)
);
CREATE INDEX IF NOT EXISTS idx_detalle_salida_animal ON detalles_salida(animal_id);

-- v1.5: Ley 21.719 de protección de datos personales.
-- Solicitudes de los titulares (acceso, rectificación, supresión, oposición,
-- portabilidad, bloqueo), con su plazo de respuesta.
CREATE TABLE IF NOT EXISTS solicitudes_datos (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  organizacion_id INTEGER NOT NULL REFERENCES organizaciones(id),
  usuario_id      INTEGER NOT NULL REFERENCES usuarios(id),
  tipo            TEXT NOT NULL,
  detalle         TEXT,
  estado          TEXT NOT NULL DEFAULT 'PENDIENTE',
  creada_en       TEXT NOT NULL,
  vence_el        TEXT NOT NULL,
  respuesta       TEXT,
  respondida_por  INTEGER REFERENCES usuarios(id),
  respondida_en   TEXT
);
CREATE INDEX IF NOT EXISTS idx_solicitudes_org ON solicitudes_datos(organizacion_id, estado);

-- Constancia de que cada persona recibió la información de la política de privacidad vigente.
CREATE TABLE IF NOT EXISTS aceptaciones_privacidad (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  usuario_id INTEGER NOT NULL REFERENCES usuarios(id),
  version    TEXT NOT NULL,
  aceptada_en TEXT NOT NULL,
  UNIQUE (usuario_id, version)
);
