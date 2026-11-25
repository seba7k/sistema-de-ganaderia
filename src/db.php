<?php
declare(strict_types=1);

require_once __DIR__ . '/arranque.php';
// Para normalizar los RUP ya cargados al migrar.
require_once __DIR__ . '/dominio/sag.php';

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
 * Cambios de esquema y de datos posteriores a la primera versión, en orden.
 *
 * Una base creada con una versión anterior no tiene las columnas ni las tablas
 * nuevas, y el sistema se sube encima de la que ya está funcionando. Cada
 * cambio se anota en la tabla `migraciones` al aplicarse: así cada visita hace
 * una sola consulta para saber que no falta nada, y lo que transforma datos
 * (como normalizar los RUP) corre una sola vez. Cada paso también se puede
 * repetir sin daño, por si una migración se cortó a la mitad.
 *
 * @return array<string, callable(PDO): void>
 */
function migraciones(): array
{
    $mysql = motor_bd() === 'mysql';
    return [
        // v1.0: fecha de secado y anulación de registros.
        'fecha-secado' => static fn (PDO $pdo) => agregar_columna_si_falta($pdo, 'animales', 'fecha_secado', $mysql ? 'DATE NULL' : 'TEXT'),
        'anulacion' => static function (PDO $pdo) use ($mysql): void {
            $tipos = $mysql
                ? ['anulada' => 'TINYINT(1) NOT NULL DEFAULT 0', 'anulada_por' => 'INT NULL', 'anulada_en' => 'DATETIME NULL', 'motivo_anulacion' => 'VARCHAR(255) NULL']
                : ['anulada' => 'INTEGER NOT NULL DEFAULT 0', 'anulada_por' => 'INTEGER', 'anulada_en' => 'TEXT', 'motivo_anulacion' => 'TEXT'];
            foreach (['pesadas', 'entregas_alimento', 'tratamientos', 'mortalidades', 'notas_animal'] as $tabla) {
                foreach ($tipos as $columna => $tipo) {
                    agregar_columna_si_falta($pdo, $tabla, $columna, $tipo);
                }
            }
        },
        // Tablas nuevas: el esquema es todo CREATE TABLE IF NOT EXISTS y no toca lo existente.
        'tablas-nuevas' => static function (PDO $pdo): void {
            foreach (sentencias_sql((string) file_get_contents(archivo_esquema())) as $sentencia) {
                $pdo->exec($sentencia);
            }
        },
        // v1.1: registro MVA, validación de tratamientos y RUP/DIIO normalizados.
        'mva-columnas' => 'migrar_columnas_mva',
        'rup-normalizado' => static fn (PDO $pdo) => normalizar_columna_cargada(
            $pdo, 'establecimientos', 'rup', 'normalizar_rup', ['rup'],
        ),
        'diio-normalizado' => static fn (PDO $pdo) => normalizar_columna_cargada(
            $pdo, 'animales', 'caravana_visual', 'normalizar_diio', ['caravana_visual', 'numero_interno', 'eid'],
        ),
        // v1.4: salidas del predio (venta, faena, traslado) con su respaldo de FMA.
        'salidas' => static function (PDO $pdo): void {
            foreach (sentencias_sql((string) file_get_contents(archivo_esquema())) as $sentencia) {
                $pdo->exec($sentencia);
            }
        },
    ];
}

function migrar_base(PDO $pdo): void
{
    $hechas = migraciones_hechas($pdo);
    if (array_diff(array_keys(migraciones()), $hechas) === []) {
        return;
    }

    /* Dos visitas a la vez sobre una base recién subida intentarían el mismo
       ALTER: la segunda espera el candado y, al entrar, ve las marcas puestas. */
    $liberar = tomar_candado($pdo);
    try {
        $hechas = migraciones_hechas($pdo);
        foreach (migraciones() as $nombre => $migrar) {
            if (in_array($nombre, $hechas, true)) {
                continue;
            }
            $migrar($pdo);
            $pdo->prepare('INSERT INTO migraciones (nombre, aplicada_en) VALUES (?, ?)')->execute([$nombre, ahora()]);
        }
    } finally {
        $liberar();
    }
}

/** Nombres de las migraciones ya aplicadas. Crea la tabla si la base es anterior a ella. */
function migraciones_hechas(PDO $pdo): array
{
    try {
        return $pdo->query('SELECT nombre FROM migraciones')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException) {
        $pdo->exec(motor_bd() === 'mysql'
            ? 'CREATE TABLE IF NOT EXISTS migraciones (nombre VARCHAR(60) NOT NULL, aplicada_en DATETIME NOT NULL, PRIMARY KEY (nombre))
               ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : 'CREATE TABLE IF NOT EXISTS migraciones (nombre TEXT PRIMARY KEY, aplicada_en TEXT NOT NULL)');
        return [];
    }
}

/**
 * Clave con la que se firman las huellas de los tratamientos validados.
 *
 * Sale de `clave_huella` en config.php o, si no está, de datos/clave-huella.php,
 * que se crea sola la primera vez. Tiene que vivir fuera de la base: quien solo
 * puede escribir en la base no puede rehacer una huella sin ella. Si se pierde,
 * los tratamientos ya validados se ven como «Alterado»: va en los respaldos.
 */
function clave_huella(): string
{
    static $clave = null;
    if ($clave !== null) {
        return $clave;
    }
    if (CLAVE_HUELLA_CONFIG !== '') {
        return $clave = CLAVE_HUELLA_CONFIG;
    }

    $archivo = RAIZ_APP . '/datos/clave-huella.php';
    if (!is_file($archivo)) {
        // Modo «x»: si dos visitas llegan juntas, solo una crea el archivo.
        $nuevo = @fopen($archivo, 'x');
        if ($nuevo !== false) {
            fwrite($nuevo, "<?php\n// Clave de las huellas MVA. No la borres ni la cambies: respaldala.\nreturn '" . bin2hex(random_bytes(32)) . "';\n");
            fclose($nuevo);
        }
    }
    $leida = is_file($archivo) ? require $archivo : null;
    if (!is_string($leida) || strlen($leida) < 32) {
        throw new RuntimeException('No se pudo leer ni crear datos/clave-huella.php: revisá los permisos de la carpeta datos.');
    }
    return $clave = $leida;
}

/** ¿Ya se aplicó esta migración en la base? */
function migracion_aplicada(PDO $pdo, string $nombre): bool
{
    $consulta = $pdo->prepare('SELECT 1 FROM migraciones WHERE nombre = ?');
    $consulta->execute([$nombre]);
    return $consulta->fetchColumn() !== false;
}

/**
 * Agrega la columna si falta. Tolera que otra visita la haya agregado recién:
 * lo que importa es que, al terminar, la columna exista.
 */
function agregar_columna_si_falta(PDO $pdo, string $tabla, string $columna, string $tipo): void
{
    try {
        $pdo->query('SELECT ' . $columna . ' FROM ' . $tabla . ' LIMIT 1');
        return;
    } catch (PDOException) {
        // Falta: se agrega abajo.
    }
    try {
        $pdo->exec('ALTER TABLE ' . $tabla . ' ADD COLUMN ' . $columna . ' ' . $tipo);
    } catch (PDOException $error) {
        // Si la columna ya está, otra visita se adelantó; si no, el error es real.
        $pdo->query('SELECT ' . $columna . ' FROM ' . $tabla . ' LIMIT 1');
    }
}

/** v1.1: registro MVA en la membresía y validación de los tratamientos. */
function migrar_columnas_mva(PDO $pdo): void
{
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
            agregar_columna_si_falta($pdo, $tabla, $columna, $tipo);
        }
    }

    /* En MySQL, quién validó apunta a un usuario real. La columna es nueva y
       está toda en NULL, así que la llave entra sin chocar con datos viejos.
       SQLite no permite agregar llaves con ALTER: ahí solo la tiene una base
       creada desde cero, y la regla la sigue cuidando la aplicación. */
    if ($mysql) {
        $existe = $pdo->query(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tratamientos'
                AND CONSTRAINT_NAME = 'fk_trat_validador'"
        )->fetchColumn();
        if ($existe === false) {
            $pdo->exec('ALTER TABLE tratamientos ADD CONSTRAINT fk_trat_validador FOREIGN KEY (validado_por) REFERENCES usuarios (id)');
        }
    }
}

/**
 * Normaliza una sola vez los valores ya cargados de una columna, como los RUP o
 * los DIIO escritos antes de que se guardaran siempre igual.
 *
 * Lo que no se puede normalizar queda como está. Uno que al normalizarse
 * chocaría con otro valor de la misma organización en cualquiera de las
 * columnas `$ocupadas` (por ejemplo, un DIIO igual al número interno de otro
 * animal) también se deja: es un caso para que alguien lo revise, no para que
 * el sistema elija.
 *
 * @param callable(string): ?string $normalizar devuelve null o '' si no se puede
 * @param list<string> $ocupadas columnas con las que no puede chocar
 */
function normalizar_columna_cargada(PDO $pdo, string $tabla, string $columna, callable $normalizar, array $ocupadas): void
{
    $filas = $pdo->query('SELECT id, organizacion_id, ' . implode(', ', array_unique([$columna, ...$ocupadas])) . ' FROM ' . $tabla . ' ORDER BY id')->fetchAll();
    $tomados = [];
    foreach ($filas as $fila) {
        foreach ($ocupadas as $otra) {
            if ($fila[$otra] !== null) {
                $tomados[$fila['organizacion_id'] . '|' . $fila[$otra]] = true;
            }
        }
    }
    $actualizar = $pdo->prepare('UPDATE ' . $tabla . ' SET ' . $columna . ' = ? WHERE id = ?');
    foreach ($filas as $fila) {
        if ($fila[$columna] === null) {
            continue;
        }
        $normal = $normalizar((string) $fila[$columna]) ?: null;
        $clave = $fila['organizacion_id'] . '|' . $normal;
        if ($normal === null || $normal === $fila[$columna] || isset($tomados[$clave])) {
            continue;
        }
        $actualizar->execute([$normal, (int) $fila['id']]);
        $tomados[$clave] = true;
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
