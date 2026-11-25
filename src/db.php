<?php
declare(strict_types=1);

require_once __DIR__ . '/arranque.php';

/**
 * Conexión a la base y utilidades de consulta.
 *
 * Producción usa MySQL o MariaDB, que es lo que ofrece cPanel. SQLite sigue
 * soportado para desarrollar sin servidor de base de datos: los dos esquemas
 * tienen las mismas tablas, y todo el SQL de la aplicación está escrito para
 * funcionar en ambos motores.
 *
 * Para que eso se sostenga, el código no usa funciones propias de un motor:
 *   · las fechas se calculan en PHP y se pasan como parámetro,
 *   · en vez de MAX(a, b) —que en MySQL es otra cosa— se usa `sql_no_negativo()`,
 *   · las diferencias de días se calculan en PHP con `dias_entre()`.
 */

function motor_bd(): string
{
    return CONFIG_BD['motor'] === 'sqlite' ? 'sqlite' : 'mysql';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $conexion = conectar_bd();

    // Se guarda antes de inicializar: la carga de datos de demostración usa db()
    // y tiene que recibir esta misma conexión.
    $pdo = $conexion;

    if (!base_inicializada($conexion)) {
        inicializar_base($conexion);
    }

    migrar_base($conexion);

    return $pdo;
}

function conectar_bd(): PDO
{
    $bd = CONFIG_BD;
    $opciones = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    if (motor_bd() === 'sqlite') {
        $carpeta = dirname($bd['archivo']);
        if (!is_dir($carpeta)) {
            mkdir($carpeta, 0755, true);
        }
        $pdo = new PDO('sqlite:' . $bd['archivo'], null, null, $opciones + [PDO::ATTR_TIMEOUT => 5]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $bd['host'],
        (int) $bd['puerto'],
        $bd['nombre'],
    );
    // Consultas preparadas nativas: los enteros llegan como enteros y no como texto.
    $pdo = new PDO($dsn, $bd['usuario'], $bd['clave'], $opciones + [PDO::ATTR_EMULATE_PREPARES => false]);
    // Modo estricto: un dato inválido se rechaza en vez de guardarse truncado.
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE'");
    return $pdo;
}

/**
 * La base está lista cuando existe la última tabla del esquema y, en modo
 * demostración, ya tiene datos. Así, si la creación se cortó a mitad de camino,
 * la próxima visita la retoma.
 */
function base_inicializada(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM sincronizaciones LIMIT 1');
    } catch (PDOException) {
        return false;
    }
    if (!(APP_DEMO || defined('FORZAR_DATOS_DEMO'))) {
        return true;
    }
    return (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;
}

/**
 * Crea las tablas y, en modo demostración, carga los datos de ejemplo.
 *
 * En MySQL las sentencias CREATE TABLE confirman solas cualquier transacción
 * abierta, así que las tablas se crean fuera de una. Por eso todas llevan
 * IF NOT EXISTS: si algo se corta, reintentar es seguro. Los datos de ejemplo sí
 * van en una transacción: o quedan todos o no queda ninguno.
 */
function inicializar_base(PDO $pdo): void
{
    $liberar = tomar_candado($pdo);

    try {
        if (base_inicializada($pdo)) {
            return;
        }

        foreach (sentencias_sql((string) file_get_contents(archivo_esquema())) as $sentencia) {
            $pdo->exec($sentencia);
        }

        $vacia = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === 0;
        if ($vacia && (APP_DEMO || defined('FORZAR_DATOS_DEMO'))) {
            require_once __DIR__ . '/demo.php';
            $pdo->beginTransaction();
            try {
                cargar_datos_demo($pdo);
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
        }
    } finally {
        $liberar();
    }
}

/**
 * Aplica los cambios de esquema posteriores a la primera versión.
 *
 * Una base creada con una versión anterior no tiene las columnas ni las tablas
 * nuevas, y el sistema se sube encima de la que ya está funcionando. Cada cambio
 * se prueba con una consulta barata: si responde, ya estaba aplicado.
 *
 * Las tablas nuevas se crean volviendo a pasar el esquema, que es todo
 * CREATE TABLE IF NOT EXISTS y por eso no toca lo existente.
 */
function migrar_base(PDO $pdo): void
{
    try {
        $pdo->query('SELECT fecha_secado FROM animales LIMIT 1');
    } catch (PDOException) {
        $pdo->exec('ALTER TABLE animales ADD COLUMN fecha_secado ' . (motor_bd() === 'mysql' ? 'DATE NULL' : 'TEXT'));
    }

    /* Se prueba tabla por tabla y no una sola como centinela: si alguna quedó a
       medias —por un esquema viejo o por una migración interrumpida— revisar solo
       la primera dejaría las otras sin columnas y la aplicación fallaría recién al
       consultarlas. */
    $tipos = motor_bd() === 'mysql'
        ? ['TINYINT(1) NOT NULL DEFAULT 0', 'INT NULL', 'DATETIME NULL', 'VARCHAR(255) NULL']
        : ['INTEGER NOT NULL DEFAULT 0', 'INTEGER', 'TEXT', 'TEXT'];
    foreach (['pesadas', 'entregas_alimento', 'tratamientos', 'mortalidades', 'notas_animal'] as $tabla) {
        try {
            $pdo->query('SELECT anulada FROM ' . $tabla . ' LIMIT 1');
            continue;
        } catch (PDOException) {
            // Falta el bloque de anulación en esta tabla: se agrega abajo.
        }
        foreach (['anulada', 'anulada_por', 'anulada_en', 'motivo_anulacion'] as $i => $columna) {
            try {
                $pdo->exec('ALTER TABLE ' . $tabla . ' ADD COLUMN ' . $columna . ' ' . $tipos[$i]);
            } catch (PDOException) {
                // La columna ya estaba: seguimos con la siguiente.
            }
        }
    }

    /* Médico Veterinario Autorizado (v1.1): su registro en la membresía y la
       validación de los tratamientos. */
    $mysql = motor_bd() === 'mysql';
    foreach ([
        'membresias' => [
            'mva_rut'           => $mysql ? 'VARCHAR(12) NULL' : 'TEXT',
            'mva_numero'        => $mysql ? 'VARCHAR(30) NULL' : 'TEXT',
            'mva_vigente_hasta' => $mysql ? 'DATE NULL' : 'TEXT',
        ],
        'tratamientos' => [
            'validado_por'        => $mysql ? 'INT NULL' : 'INTEGER',
            'validado_en'         => $mysql ? 'DATETIME NULL' : 'TEXT',
            'validado_mva_rut'    => $mysql ? 'VARCHAR(12) NULL' : 'TEXT',
            'validado_mva_numero' => $mysql ? 'VARCHAR(30) NULL' : 'TEXT',
            'huella'              => $mysql ? 'CHAR(64) NULL' : 'TEXT',
        ],
    ] as $tabla => $columnas) {
        foreach ($columnas as $columna => $tipo) {
            try {
                $pdo->query('SELECT ' . $columna . ' FROM ' . $tabla . ' LIMIT 1');
            } catch (PDOException) {
                $pdo->exec('ALTER TABLE ' . $tabla . ' ADD COLUMN ' . $columna . ' ' . $tipo);
            }
        }
    }

    try {
        $pdo->query('SELECT 1 FROM bitacora LIMIT 1');
        $pdo->query('SELECT 1 FROM notas_animal LIMIT 1');
    } catch (PDOException) {
        foreach (sentencias_sql((string) file_get_contents(archivo_esquema())) as $sentencia) {
            $pdo->exec($sentencia);
        }
    }
}

/**
 * Candado para la creación de la base. Dos visitas simultáneas a un sitio recién
 * subido intentarían crear las tablas a la vez: la segunda espera a la primera.
 */
function tomar_candado(PDO $pdo): callable
{
    if (motor_bd() === 'mysql') {
        $pdo->query("SELECT GET_LOCK('ganaderia_inicializacion', 60)")->fetchColumn();
        return static function () use ($pdo): void {
            $pdo->query("SELECT RELEASE_LOCK('ganaderia_inicializacion')")->fetchColumn();
        };
    }

    $archivo = fopen(CONFIG_BD['archivo'] . '.lock', 'c');
    if ($archivo === false) {
        return static function (): void {
        };
    }
    flock($archivo, LOCK_EX);
    return static function () use ($archivo): void {
        flock($archivo, LOCK_UN);
        fclose($archivo);
    };
}

function archivo_esquema(): string
{
    return RAIZ_APP . '/esquema.' . motor_bd() . '.sql';
}

/** Divide un script SQL en sentencias, descartando los comentarios de línea. */
function sentencias_sql(string $script): array
{
    $lineas = array_filter(
        explode("\n", $script),
        static fn (string $linea): bool => !str_starts_with(ltrim($linea), '--'),
    );
    return array_values(array_filter(
        array_map('trim', explode(';', implode("\n", $lineas))),
        static fn (string $sentencia): bool => $sentencia !== '',
    ));
}

/** Borra todas las tablas. Solo lo usa seed.php, por consola. */
function vaciar_base(): void
{
    if (motor_bd() === 'sqlite') {
        foreach ([CONFIG_BD['archivo'], CONFIG_BD['archivo'] . '-journal', CONFIG_BD['archivo'] . '.lock'] as $archivo) {
            if (is_file($archivo)) {
                unlink($archivo);
            }
        }
        return;
    }

    $pdo = conectar_bd();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tabla) {
        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $tabla) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/**
 * Ejecuta una consulta con parámetros ligados.
 *
 * Todas las consultas pasan por acá con placeholders: nunca se interpola un valor
 * dentro del SQL, que es de donde salen las inyecciones.
 */
function consultar(string $sql, array $parametros = []): PDOStatement
{
    $sentencia = db()->prepare($sql);
    $sentencia->execute(array_values($parametros));
    return $sentencia;
}

function filas(string $sql, array $parametros = []): array
{
    return consultar($sql, $parametros)->fetchAll();
}

function fila(string $sql, array $parametros = []): ?array
{
    $resultado = consultar($sql, $parametros)->fetch();
    return $resultado === false ? null : $resultado;
}

function valor(string $sql, array $parametros = []): mixed
{
    $resultado = consultar($sql, $parametros)->fetchColumn();
    return $resultado === false ? null : $resultado;
}

/**
 * Bitácora: quién cambió o anuló algo ya cargado, y los hechos de acceso.
 *
 * Vive acá, con la conexión, porque la usan tanto la autenticación (ingresos
 * fallidos, bloqueos) como las operaciones de negocio (correcciones, anulaciones).
 */
function registrar_bitacora(
    ?int $org,
    ?int $usuario_id,
    string $accion,
    ?string $entidad = null,
    ?int $entidad_id = null,
    string $detalle = '',
): void {
    consultar(
        'INSERT INTO bitacora (organizacion_id, usuario_id, fecha_hora, accion, entidad, entidad_id, detalle, origen)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $org, $usuario_id, ahora(), $accion, $entidad, $entidad_id,
            mb_substr($detalle, 0, 500),
            mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'consola'), 0, 45),
        ],
    );
}

/** Id de la última fila insertada. */
function ultimo_id(): int
{
    return (int) db()->lastInsertId();
}

/**
 * Expresión SQL que nunca baja de cero, igual en MySQL y en SQLite.
 * (MAX con dos argumentos existe en SQLite pero en MySQL es una agregación.)
 */
function sql_no_negativo(string $expresion): string
{
    return "CASE WHEN ($expresion) > 0 THEN ($expresion) ELSE 0 END";
}

/*
 * Fechas para las consultas. Se calculan en PHP y se pasan como parámetro: así
 * valen en los dos motores y usan la zona horaria de Chile, no la del servidor.
 */

function hoy(): string
{
    return (new DateTimeImmutable('today'))->format('Y-m-d');
}

function hace_dias(int $dias): string
{
    return (new DateTimeImmutable('today'))->modify("-{$dias} days")->format('Y-m-d');
}

function ahora(): string
{
    return (new DateTimeImmutable())->format('Y-m-d H:i:s');
}
