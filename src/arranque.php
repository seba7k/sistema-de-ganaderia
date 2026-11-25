<?php
declare(strict_types=1);

/**
 * Arranque común: configuración, dirección base y verificación del servidor.
 *
 * Este archivo evita a propósito la sintaxis de PHP 8. Si el hosting tiene una
 * versión vieja, tiene que llegar a mostrar qué falta en vez de morir con un
 * error de sintaxis y una pantalla en blanco.
 */

if (defined('RAIZ_APP')) {
    return;
}

define('RAIZ_APP', dirname(__DIR__));

$configuracion = require RAIZ_APP . '/config.php';

// Configuración local de desarrollo: pisa solo las claves que define.
if (is_file(RAIZ_APP . '/config.local.php')) {
    $configuracion = array_replace_recursive($configuracion, require RAIZ_APP . '/config.local.php');
}

define('APP_DEMO', !empty($configuracion['demo']));
define('GESTION_AVANZADA', !empty($configuracion['gestion_avanzada']));
define('URL_AMIGABLES', !isset($configuracion['url_amigables']) || $configuracion['url_amigables']);
define('MINUTOS_INACTIVIDAD', (int) (isset($configuracion['minutos_inactividad']) ? $configuracion['minutos_inactividad'] : 60));
define('FORMATO_DIIO', isset($configuracion['formato_diio']) && is_string($configuracion['formato_diio']) ? trim($configuracion['formato_diio']) : '');
define('DIAS_SESION_APP', (int) (isset($configuracion['dias_sesion_app']) ? $configuracion['dias_sesion_app'] : 30));

define('CONFIG_BD', array_merge(
    array(
        'motor'   => 'mysql',
        'host'    => 'localhost',
        'puerto'  => 3306,
        'nombre'  => '',
        'usuario' => '',
        'clave'   => '',
        'archivo' => RAIZ_APP . '/datos/ganaderia.sqlite',
    ),
    isset($configuracion['base_datos']) && is_array($configuracion['base_datos']) ? $configuracion['base_datos'] : array()
));

date_default_timezone_set((string) (isset($configuracion['zona_horaria']) ? $configuracion['zona_horaria'] : 'America/Santiago'));

error_reporting(E_ALL);
ini_set('display_errors', !empty($configuracion['mostrar_errores']) ? '1' : '0');
ini_set('log_errors', '1');

/*
 * Dirección base.
 *
 * El sistema tiene que funcionar tanto en la raíz de un dominio como en una
 * subcarpeta (rparra.cl/pt). Todos los enlaces se arman sobre esta base, que se
 * deduce de dónde está index.php en vez de escribirse a mano.
 */
if (PHP_SAPI === 'cli') {
    define('BASE_URL', '');
} else {
    $script = str_replace('\\', '/', (string) (isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : ''));
    // Con el servidor de desarrollo de PHP y un script de ruteo, SCRIPT_NAME no
    // termina en /index.php: en ese caso la aplicación está en la raíz.
    define('BASE_URL', substr($script, -10) === '/index.php' ? substr($script, 0, -10) : '');
}

/** Ruta interna pedida, sin la dirección base: '/', '/hacienda', '/api/catalogo'… */
function ruta_actual()
{
    // index.php?r=/hacienda funciona siempre, tenga o no mod_rewrite el hosting.
    if (isset($_GET['r']) && is_string($_GET['r']) && $_GET['r'] !== '') {
        $ruta = $_GET['r'];
    } else {
        $ruta = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
        $ruta = is_string($ruta) ? rawurldecode($ruta) : '/';
        if (BASE_URL !== '' && strpos($ruta, BASE_URL) === 0) {
            $ruta = (string) substr($ruta, strlen(BASE_URL));
        }
    }

    $ruta = '/' . ltrim($ruta, '/');
    if ($ruta === '/index.php') {
        return '/';
    }
    return strlen($ruta) > 1 ? rtrim($ruta, '/') : $ruta;
}

function es_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    // Hostings que terminan el TLS en un proxy y le pasan HTTP plano a PHP.
    return isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
}

/** Lo que le falta al servidor para poder correr el sistema. Vacío = todo bien. */
function requisitos_pendientes()
{
    $pendientes = array();
    $bd = CONFIG_BD;

    if (PHP_VERSION_ID < 80100) {
        $pendientes[] = 'Se necesita PHP 8.1 o superior y el servidor tiene PHP ' . PHP_VERSION
            . '. En cPanel: «Select PHP Version» o «MultiPHP Manager».';
    }
    if (!extension_loaded('mbstring')) {
        $pendientes[] = 'Falta la extensión mbstring. En cPanel: «Select PHP Version» → pestaña Extensions → marcar mbstring.';
    }

    if (FORMATO_DIIO !== '' && @preg_match(FORMATO_DIIO, '') === false) {
        $pendientes[] = 'El «formato_diio» de config.php no es una expresión regular válida. '
            . 'Corregilo, o dejalo vacío para no revisar el formato del DIIO.';
    }

    if ($bd['motor'] === 'sqlite') {
        if (!extension_loaded('pdo_sqlite')) {
            $pendientes[] = 'Falta la extensión pdo_sqlite. En cPanel: «Select PHP Version» → Extensions → marcar pdo_sqlite.';
        }
        $carpeta = dirname($bd['archivo']);
        if (is_dir($carpeta) ? !is_writable($carpeta) : !is_writable(dirname($carpeta))) {
            $pendientes[] = 'PHP no puede escribir en la carpeta de la base. En el Administrador de archivos: clic derecho → Change Permissions → 755.';
        }
    } else {
        if (!extension_loaded('pdo_mysql')) {
            $pendientes[] = 'Falta la extensión pdo_mysql. En cPanel: «Select PHP Version» → Extensions → marcar pdo_mysql.';
        }
        if ($bd['nombre'] === '' || $bd['usuario'] === '' || strpos($bd['nombre'], 'TU_USUARIO') !== false) {
            $pendientes[] = 'Faltan los datos de la base MySQL. En cPanel, «Bases de datos MySQL»: creá una base y un usuario, '
                . 'agregá el usuario a la base con todos los privilegios, y completá nombre, usuario y clave en config.php.';
        }
    }

    return $pendientes;
}

/** Pantalla autónoma para cuando el sistema no puede arrancar. No depende de nada más. */
function mostrar_aviso_servidor(array $mensajes, $titulo = 'Falta configurar el servidor')
{
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</title></head>'
        . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;'
        . 'background:#0f2417;font-family:system-ui,sans-serif;color:#16201a">'
        . '<div style="background:#fff;border-radius:16px;padding:32px;max-width:560px;box-shadow:0 20px 60px rgba(0,0,0,.35)">'
        . '<h1 style="margin:0 0 6px;font-size:20px">' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p style="margin:0 0 18px;color:#5a6b60">Gestión ganadera no puede arrancar todavía:</p><ul style="padding-left:20px;line-height:1.6">';
    foreach ($mensajes as $mensaje) {
        echo '<li style="margin-bottom:10px">' . htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    echo '</ul></div></body></html>';
}

/*
 * Un error no controlado no puede terminar en pantalla en blanco ni mostrar el
 * detalle técnico: se registra en el log del servidor y el usuario ve un aviso.
 * Las peticiones a la API reciben JSON, porque del otro lado hay una app y no
 * una persona leyendo HTML.
 */
set_exception_handler(function ($error) {
    error_log('[ganaderia] ' . $error);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, (string) $error . PHP_EOL);
        exit(1);
    }
    if (strpos(ruta_actual(), '/api/') === 0) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array('error' => 'Error interno del servidor. Quedó registrado en el log.'));
        return;
    }
    mostrar_aviso_servidor(
        array('Ocurrió un error inesperado. El detalle quedó registrado en el log de errores del servidor.'),
        'Algo salió mal'
    );
});
