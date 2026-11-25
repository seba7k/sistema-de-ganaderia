<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Autenticación por sesión y permisos por función.
 *
 * La sesión guarda el usuario Y la organización en la que está trabajando: una
 * misma persona puede pertenecer a varias (el veterinario que atiende campos de
 * distintos dueños), y sin ese dato ninguna consulta sabría qué filtrar.
 */

/** Los tres roles del sistema, con el nombre que se muestra en pantalla. */
const ROLES = [
    'ADMINISTRADOR' => 'Administrador',
    'VETERINARIO'   => 'Médico veterinario',
    'OPERARIO'      => 'Operario de manga',
];

/**
 * Qué puede hacer cada rol.
 *
 * Los permisos son por función y no una escalera: el veterinario no hereda lo
 * del operario ni al revés. El veterinario trata, diagnostica y registra la
 * reproducción, pero no mueve hacienda ni carga alimento. El operario trabaja en
 * la manga y el comedero, pero no medica ni vende. Solo el administrador ve la
 * economía, la bodega completa y la nómina.
 */
const PERMISOS_POR_ROL = [
    'ADMINISTRADOR' => ['*'],
    'VETERINARIO' => [
        'ver_hacienda', 'ver_corrales', 'ver_alimentacion', 'ver_sanidad', 'ver_reproduccion', 'ver_insumos',
        'registrar_pesada', 'registrar_baja',
        'registrar_tratamiento', 'validar_tratamiento', 'registrar_servicio', 'registrar_diagnostico', 'registrar_parto',
        'registrar_secado', 'registrar_nota',
        'anular_registro', 'ver_bitacora',
        'ver_descartes', 'usar_app',
    ],
    'OPERARIO' => [
        'ver_hacienda', 'ver_corrales', 'ver_alimentacion', 'ver_sanidad', 'ver_reproduccion',
        'registrar_pesada', 'registrar_baja', 'registrar_parto', 'registrar_secado', 'registrar_nota',
        'registrar_entrega', 'registrar_traslado',
        'usar_app',
    ],
];

function nombre_rol(string $rol): string
{
    return ROLES[$rol] ?? $rol;
}

/** ¿El rol tiene este permiso? Un rol desconocido no tiene ninguno. */
function rol_puede(string $rol, string $permiso): bool
{
    $permisos = PERMISOS_POR_ROL[$rol] ?? [];
    return in_array('*', $permisos, true) || in_array($permiso, $permisos, true);
}

/** ¿La sesión actual tiene este permiso? */
function puede(string $permiso): bool
{
    $usuario = usuario_actual();
    return $usuario !== null && rol_puede($usuario['rol'], $permiso);
}

/** Corta la ejecución con la página de «sin permiso» si la sesión no lo tiene. */
function exigir_permiso(string $permiso): void
{
    if (!puede($permiso)) {
        http_response_code(403);
        require __DIR__ . '/vistas/error-403.php';
        exit;
    }
}

function iniciar_sesion_php(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    // Nombre y ruta de cookie propios. Con los valores por defecto (PHPSESSID en
    // la raíz), otra aplicación del mismo dominio —otro sistema en una carpeta
    // hermana de public_html— usaría la misma cookie y las dos se pisarían la sesión.
    session_name('GANADERIA_SESION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_URL === '' ? '/' : BASE_URL . '/',
        'secure'   => es_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // Que el servidor no borre la sesión antes del plazo de inactividad.
    ini_set('session.gc_maxlifetime', (string) max(1440, MINUTOS_INACTIVIDAD * 60 + 300));
    session_start(['use_strict_mode' => true]);
}

/**
 * Verifica credenciales y deja la sesión abierta.
 *
 * @return array{ok: bool, error?: string, organizaciones?: array}
 */
function intentar_login(string $email, string $password, ?int $organizacion_id = null): array
{
    $resultado = verificar_credenciales($email, $password, $organizacion_id);
    if (!$resultado['ok']) {
        return $resultado;
    }

    iniciar_sesion_php();
    session_regenerate_id(true); // evita la fijación de sesión
    $_SESSION['usuario'] = $resultado['usuario'];
    $_SESSION['credencial'] = $resultado['credencial'];
    $_SESSION['ultima_actividad'] = time();

    return ['ok' => true];
}

/* ============================================================
   Freno a la fuerza bruta

   Sin esto, alguien puede probar contraseñas contra el formulario o contra la
   API toda la noche. Se cuentan los intentos fallidos por correo y por
   dirección de origen: pasado el límite, esa combinación espera unos minutos.
   El que se equivocó una vez no se entera; el que prueba en serie, sí.
   ============================================================ */

const INTENTOS_PERMITIDOS = 8;
const INTENTOS_POR_ORIGEN = 40;
const MINUTOS_BLOQUEO = 15;

/** Minutos que faltan para poder volver a intentar, o 0 si no está bloqueado. */
/**
 * Minutos que falta esperar antes de volver a intentar, o 0 si se puede entrar.
 *
 * Se cuentan dos cosas distintas y con umbrales distintos a propósito:
 *
 * - Por correo, pocos intentos: es la defensa real contra quien prueba
 *   contraseñas en una cuenta.
 * - Por conexión, muchos más: en un campo entran todos por el mismo router, y
 *   con un umbral bajo la persona que se equivoca ocho veces dejaría afuera al
 *   resto del equipo por un cuarto de hora. Ese número alto igual frena a quien
 *   prueba cuentas en serie desde un mismo lugar.
 */
function minutos_de_bloqueo(string $email): int
{
    $desde = (new DateTimeImmutable())->modify('-' . MINUTOS_BLOQUEO . ' minutes')->format('Y-m-d H:i:s');
    $origen = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'consola'), 0, 45);

    foreach ([
        ['identidad', $email, INTENTOS_PERMITIDOS],
        ['origen', $origen, INTENTOS_POR_ORIGEN],
    ] as [$columna, $valor_buscado, $limite]) {
        $fallidos = (int) valor(
            'SELECT COUNT(*) FROM intentos_ingreso WHERE momento >= ? AND ' . $columna . ' = ?',
            [$desde, $valor_buscado],
        );
        if ($fallidos < $limite) {
            continue;
        }
        $ultimo = (string) valor(
            'SELECT MAX(momento) FROM intentos_ingreso WHERE momento >= ? AND ' . $columna . ' = ?',
            [$desde, $valor_buscado],
        );
        $libre = (new DateTimeImmutable($ultimo))->modify('+' . MINUTOS_BLOQUEO . ' minutes');
        return max((int) ceil(($libre->getTimestamp() - time()) / 60), 1);
    }

    return 0;
}

function anotar_intento_fallido(string $email): void
{
    consultar(
        'INSERT INTO intentos_ingreso (identidad, origen, momento) VALUES (?, ?, ?)',
        [mb_substr($email, 0, 190), mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'consola'), 0, 45), ahora()],
    );
    // Los intentos viejos no sirven para nada: se limpian de a poco.
    consultar(
        'DELETE FROM intentos_ingreso WHERE momento < ?',
        [(new DateTimeImmutable())->modify('-1 day')->format('Y-m-d H:i:s')],
    );
}

function olvidar_intentos(string $email): void
{
    consultar('DELETE FROM intentos_ingreso WHERE identidad = ?', [$email]);
}

/**
 * Comprueba email, contraseña y organización sin abrir sesión.
 *
 * La usan tanto el ingreso web como la API de la app de terreno: las dos puertas
 * aplican exactamente las mismas reglas.
 *
 * @return array{ok: bool, error?: string, organizaciones?: array, usuario?: array}
 */
function verificar_credenciales(string $email, string $password, ?int $organizacion_id = null): array
{
    $email = mb_strtolower(trim($email));

    $bloqueo = minutos_de_bloqueo($email);
    if ($bloqueo > 0) {
        registrar_bitacora(null, null, 'INGRESO_BLOQUEADO', null, null, $email);
        return [
            'ok' => false,
            'error' => 'Demasiados intentos fallidos. Esperá ' . $bloqueo . ' minuto(s) y volvé a probar.',
        ];
    }

    $usuario = fila(
        'SELECT id, email, password_hash, nombre, apellido, activo FROM usuarios WHERE email = ?',
        [$email],
    );

    // Se verifica contra un hash de descarte incluso sin usuario: si se saltara
    // el cálculo, la respuesta más rápida delataría qué emails están registrados.
    $hash = $usuario['password_hash'] ?? '$2y$12$descartedescartedescartedescartedescartedescartedescarteXX';
    $correcta = password_verify($password, $hash);

    if ($usuario === null || !$correcta) {
        anotar_intento_fallido($email);
        registrar_bitacora(null, $usuario['id'] ?? null, 'INGRESO_FALLIDO', null, null, $email);
        return ['ok' => false, 'error' => 'Email o contraseña incorrectos.'];
    }
    if ((int) $usuario['activo'] !== 1) {
        anotar_intento_fallido($email);
        return ['ok' => false, 'error' => 'La cuenta está desactivada.'];
    }

    $membresias = filas(
        'SELECT m.organizacion_id, m.rol, o.nombre
           FROM membresias m
           JOIN organizaciones o ON o.id = m.organizacion_id
          WHERE m.usuario_id = ? AND m.activa = 1 AND o.activa = 1
          ORDER BY o.nombre',
        [$usuario['id']],
    );

    if ($membresias === []) {
        return ['ok' => false, 'error' => 'El usuario no tiene acceso a ninguna organización activa.'];
    }

    if ($organizacion_id !== null) {
        $elegida = null;
        foreach ($membresias as $membresia) {
            if ((int) $membresia['organizacion_id'] === $organizacion_id) {
                $elegida = $membresia;
            }
        }
        if ($elegida === null) {
            return ['ok' => false, 'error' => 'No tiene acceso a esa organización.'];
        }
    } elseif (count($membresias) > 1) {
        // Falta saber con cuál organización va a trabajar.
        return ['ok' => false, 'organizaciones' => $membresias];
    } else {
        $elegida = $membresias[0];
    }

    // Entró bien: los intentos fallidos anteriores dejan de contar.
    olvidar_intentos($email);

    // Si la contraseña estaba guardada con un costo viejo, se rehashea ahora que
    // la tenemos en claro. Nadie tiene que cambiar nada.
    if (password_needs_rehash($usuario['password_hash'], PASSWORD_BCRYPT, ['cost' => COSTO_BCRYPT])) {
        $nuevo_hash = hash_password($password);
        if (consultar('UPDATE usuarios SET password_hash = ? WHERE id = ? AND password_hash = ?',
            [$nuevo_hash, (int) $usuario['id'], $usuario['password_hash']])->rowCount() === 1) {
            $usuario['password_hash'] = $nuevo_hash;
        }
    }
    consultar('UPDATE usuarios SET ultimo_acceso_en = ? WHERE id = ?', [ahora(), $usuario['id']]);

    return [
        'ok' => true,
        'credencial' => hash('sha256', $usuario['password_hash']),
        'usuario' => [
            'id'              => (int) $usuario['id'],
            'email'           => $usuario['email'],
            'nombre'          => $usuario['nombre'],
            'apellido'        => $usuario['apellido'],
            'organizacion_id' => (int) $elegida['organizacion_id'],
            'organizacion'    => $elegida['nombre'],
            'rol'             => $elegida['rol'],
        ],
    ];
}

function cerrar_sesion(): void
{
    iniciar_sesion_php();
    $_SESSION = [];

    // La sesión del servidor ya no vale, pero la cookie seguiría en el navegador
    // hasta cerrarlo: se borra explícitamente.
    if (!headers_sent()) {
        $parametros = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $parametros['path'],
            'secure'   => $parametros['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    session_destroy();
}

/**
 * Usuario de la sesión, o null.
 *
 * Acá se aplica el cierre por inactividad: si pasó más tiempo del configurado
 * desde la última petición, la sesión se descarta aunque la cookie siga viva. Se
 * controla en el servidor y no solo con un temporizador en el navegador, porque
 * ese se esquiva cerrando la pestaña o desactivando JavaScript.
 */
function usuario_actual(): ?array
{
    iniciar_sesion_php();
    if (!isset($_SESSION['usuario'])) {
        return null;
    }

    $ultima = (int) ($_SESSION['ultima_actividad'] ?? time());
    if (time() - $ultima > MINUTOS_INACTIVIDAD * 60) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['cierre_por_inactividad'] = true;
        return null;
    }

    // Reutilizar la identidad solo durante esta petición.
    static $identidades = [];
    $usuario = $_SESSION['usuario'];
    $credencial = $_SESSION['credencial'] ?? '';
    $clave = $usuario['id'] . ':' . $usuario['organizacion_id'] . ':' . $credencial;
    if (!array_key_exists($clave, $identidades)) {
        $identidades[$clave] = fila(
            'SELECT m.rol, u.password_hash
               FROM membresias m
               JOIN usuarios u ON u.id = m.usuario_id
               JOIN organizaciones o ON o.id = m.organizacion_id
              WHERE m.usuario_id = ? AND m.organizacion_id = ?
                AND m.activa = 1 AND u.activo = 1 AND o.activa = 1',
            [$usuario['id'], $usuario['organizacion_id']],
        );
    }
    $vigente = $identidades[$clave];
    if ($vigente === null || !hash_equals(hash('sha256', $vigente['password_hash']), $credencial)) {
        cerrar_sesion();
        return null;
    }
    $_SESSION['usuario']['rol'] = $vigente['rol'];
    $_SESSION['ultima_actividad'] = time();
    return $_SESSION['usuario'];
}

/** True una sola vez después de un cierre por inactividad, para avisarlo. */
function sesion_cerrada_por_inactividad(): bool
{
    iniciar_sesion_php();
    $cerrada = !empty($_SESSION['cierre_por_inactividad']);
    unset($_SESSION['cierre_por_inactividad']);
    return $cerrada;
}

/** Organización de la sesión. Toda consulta de negocio filtra por este valor. */
function organizacion_actual(): int
{
    $usuario = usuario_actual();
    if ($usuario === null) {
        throw new RuntimeException('No hay sesión iniciada.');
    }
    return $usuario['organizacion_id'];
}

/* ============================================================
   Predios

   Un mismo propietario —una organización, identificada por su RUT, como en el
   registro del SAG— puede tener varios campos. Las pantallas muestran uno a la
   vez: sumar la ocupación de corrales o el inventario de predios distintos daría
   números que no significan nada.
   ============================================================ */

/** Predios de la organización de la sesión. */
function predios_permitidos(): array
{
    static $cache = [];
    $usuario = usuario_actual();
    if ($usuario === null) {
        return [];
    }
    $org = $usuario['organizacion_id'];
    if (!isset($cache[$org])) {
        $cache[$org] = array_map(
            static fn (array $predio): array => [
                'id' => (int) $predio['id'], 'nombre' => $predio['nombre'], 'tipo' => $predio['tipo'],
            ],
            filas('SELECT id, nombre, tipo FROM establecimientos WHERE organizacion_id = ? ORDER BY nombre', [$org]),
        );
    }
    return $cache[$org];
}

/** Predio en el que se está trabajando. Null solo si la organización no tiene ninguno. */
function predio_actual(): ?int
{
    $predios = predios_permitidos();
    if ($predios === []) {
        return null;
    }
    $elegido = isset($_SESSION['predio']) ? (int) $_SESSION['predio'] : 0;
    if (!in_array($elegido, array_column($predios, 'id'), true)) {
        $elegido = $predios[0]['id'];
        $_SESSION['predio'] = $elegido;
    }
    return $elegido;
}

function nombre_predio(): string
{
    $actual = predio_actual();
    foreach (predios_permitidos() as $predio) {
        if ($predio['id'] === $actual) {
            return $predio['nombre'];
        }
    }
    return 'Sin predio';
}

/** Cambia el predio activo. Devuelve false si no es de esta organización. */
function cambiar_predio(int $id): bool
{
    if (!in_array($id, array_column(predios_permitidos(), 'id'), true)) {
        return false;
    }
    iniciar_sesion_php();
    $_SESSION['predio'] = $id;
    return true;
}

/**
 * Condición SQL que limita una consulta al predio activo.
 *
 * El número va escrito dentro del SQL y no como parámetro ligado porque es un
 * entero que `predio_actual()` ya comparó contra los predios de la organización:
 * no viene del navegador. Así esta condición se puede pegar a consultas que
 * arman su lista de parámetros en otro orden.
 */
function filtro_predio(string $alias = 'a', string $columna = 'establecimiento_id'): string
{
    $predio = predio_actual();
    return $predio === null ? '' : ' AND ' . $alias . '.' . $columna . ' = ' . $predio;
}

function hay_sesion(): bool
{
    return usuario_actual() !== null;
}

/**
 * Contraseña temporal para una cuenta nueva.
 *
 * Sin letras ni números que se confundan al dictarla por teléfono (l, 1, o, 0),
 * porque mientras no haya envío de correo el administrador se la entrega a mano.
 */
function generar_password_temporal(int $largo = 10): string
{
    $alfabeto = 'abcdefghijkmnpqrstuvwxyz23456789';
    $clave = '';
    for ($i = 0; $i < $largo; $i++) {
        $clave .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    return $clave;
}

/**
 * Hash de contraseña con bcrypt.
 *
 * El costo 12 es más caro que el que trae PHP por defecto: hace un poco más lenta
 * cada verificación —imperceptible para quien entra— y mucho más cara la prueba
 * masiva de contraseñas si alguna vez se filtra la base.
 */
const COSTO_BCRYPT = 12;

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => COSTO_BCRYPT]);
}

/** Token anti-CSRF para los formularios que modifican datos. */
function token_csrf(): string
{
    iniciar_sesion_php();
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verificar_csrf(?string $token): bool
{
    iniciar_sesion_php();
    return is_string($token)
        && isset($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}
