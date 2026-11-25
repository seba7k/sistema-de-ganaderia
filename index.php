<?php
declare(strict_types=1);

/**
 * Front controller: toda petición entra por acá.
 *
 * En Apache/cPanel lo decide el .htaccess. Con el servidor de desarrollo de PHP,
 * este mismo archivo hace de script de ruteo:
 *
 *     php -S localhost:8000 index.php
 *
 * Mantener este archivo sin sintaxis de PHP 8 hasta pasar la verificación de
 * requisitos: si el hosting tiene una versión vieja, tiene que llegar a decirlo.
 */

require __DIR__ . '/src/arranque.php';

$ruta = ruta_actual();

if (PHP_SAPI === 'cli-server') {
    $pedido = (string) parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);

    // El servidor de desarrollo no lee .htaccess: solo se sirven directo los
    // archivos estáticos de la raíz, las fuentes y los de la app de terreno, nunca src/ ni datos/.
    if (preg_match('#^/[A-Za-z0-9_.-]+\.(css|js|png|jpe?g|svg|ico|webp|woff2?)$#', $pedido) && is_file(__DIR__ . $pedido)) {
        return false;
    }
    if (preg_match('#^/fuentes/[A-Za-z0-9_.-]+\.woff2$#', $pedido) && is_file(__DIR__ . $pedido)) {
        header('Content-Type: font/woff2');
        header('Cache-Control: public, max-age=31536000');
        readfile(__DIR__ . $pedido);
        exit;
    }
    if ($pedido === '/app') {
        header('Location: /app/');
        exit;
    }
    if (preg_match('#^/app/([A-Za-z0-9_.-]*)$#', $pedido, $partes)) {
        $archivo = __DIR__ . '/app/' . ($partes[1] === '' ? 'index.html' : $partes[1]);
        $tipos = array(
            'html' => 'text/html; charset=utf-8',
            'js' => 'text/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'webmanifest' => 'application/manifest+json',
            'png' => 'image/png',
        );
        $extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
        if (is_file($archivo) && isset($tipos[$extension])) {
            header('Content-Type: ' . $tipos[$extension]);
            readfile($archivo);
            exit;
        }
    }
}

// La app de terreno recibe siempre JSON, también cuando el servidor no puede arrancar.
$es_api = strpos($ruta, '/api/') === 0;

function responder_falla_api($mensaje)
{
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => $mensaje));
    exit;
}

$pendientes = requisitos_pendientes();
if ($pendientes) {
    if ($es_api) {
        responder_falla_api('El servidor todavía no está configurado.');
    }
    mostrar_aviso_servidor($pendientes);
    exit;
}

require __DIR__ . '/src/auth.php';
require __DIR__ . '/src/formato.php';

// Abre la base y, si es la primera visita, crea las tablas.
try {
    db();
} catch (PDOException $error) {
    error_log('[ganaderia] base de datos: ' . $error->getMessage());
    $mensaje = motor_bd() === 'mysql'
        ? 'No se pudo conectar a la base MySQL. Revisá en config.php el nombre de la base, el usuario y la clave, '
          . 'y que en cPanel el usuario esté agregado a la base con «Todos los privilegios».'
        : 'No se pudo abrir la base SQLite. Revisá que PHP pueda escribir en la carpeta datos/.';
    if ($es_api) {
        responder_falla_api('El servidor no tiene conexión con la base de datos.');
    }
    mostrar_aviso_servidor(array($mensaje), 'Sin conexión con la base de datos');
    exit;
}

// La API no usa la sesión de la web: se identifica con el token de la app.
if ($es_api) {
    require __DIR__ . '/src/api.php';
    atender_api(substr($ruta, 4));
    exit;
}

/*
 * Cabeceras de seguridad para las páginas del sistema.
 *
 * La política de contenido corta la vía de un XSS aunque se cuele: el navegador
 * solo ejecuta scripts servidos por este mismo sitio, no acepta código escrito
 * dentro del HTML, y no deja que otra página meta el sistema en un marco. Se
 * permiten estilos en línea porque la interfaz usa el atributo style para el
 * color de cada módulo.
 */
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
        . "style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; "
        . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('X-Content-Type-Options: nosniff');
    if (es_https()) {
        // Un año de HTTPS obligatorio: el navegador deja de ofrecer la versión sin
        // cifrar, por donde viajarían la contraseña y el token de la app.
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
}

iniciar_sesion_php();

// El aviso de inactividad de la web pregunta acá si la sesión sigue viva. Consultar
// cuenta como actividad, así que «Seguir conectado» la renueva.
if ($ruta === '/mantener-sesion') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    /* Va por POST y con token: por GET, cualquier sitio abierto en otra pestaña
       podía mantener viva para siempre una sesión que debía cerrarse sola. */
    $metodo = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
    if ($metodo !== 'POST' || !verificar_csrf(isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : null)) {
        http_response_code(405);
        echo json_encode(array('activa' => false));
        exit;
    }
    echo json_encode(array('activa' => hay_sesion()));
    exit;
}

// Un cuerpo desmedido se corta acá y no ocupa memoria dibujando una página.
if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 4000000) {
    http_response_code(413);
    exit;
}

/* Rutas abiertas: el resto exige sesión. Se declara así, y no al revés, porque
   olvidarse de proteger una página es un error silencioso. */
$rutas_abiertas = array('/ingresar', '/salir');

if (!hay_sesion() && !in_array($ruta, $rutas_abiertas, true)) {
    header('Location: ' . url('/ingresar'));
    exit;
}

$paginas = array(
    '/'             => 'panel',
    '/hacienda'     => 'hacienda',
    '/corrales'     => 'corrales',
    '/alimentacion' => 'alimentacion',
    '/sanidad'      => 'sanidad',
    '/reproduccion' => 'reproduccion',
    '/animal'       => 'animal',
    '/lotes'        => 'lotes',
    '/insumos'      => 'insumos',
    '/usuarios'     => 'usuarios',
    '/predios'      => 'predios',
    '/bitacora'     => 'bitacora',
    '/exportar'     => 'exportar',
);

// Sin gestión avanzada, esas pantallas no existen: el sistema queda en el ciclo
// productivo, que es lo que el productor usa todos los días.
if (!GESTION_AVANZADA) {
    unset($paginas['/sanidad'], $paginas['/insumos'], $paginas['/lotes']);
}

if ($ruta === '/ingresar') {
    if (hay_sesion()) {
        header('Location: ' . url('/'));
        exit;
    }
    require __DIR__ . '/src/paginas/ingreso.php';
} elseif ($ruta === '/salir') {
    cerrar_sesion();
    header('Location: ' . (isset($_GET['inactividad']) ? url_con('/ingresar', array('inactividad' => '1')) : url('/ingresar')));
} elseif ($ruta === '/predio') {
    /* Cambio de predio activo. Va por POST y con token: por GET, un enlace o una
       imagen en otro sitio podía hacerle cambiar de campo a alguien con la sesión
       abierta, y dejarlo cargando datos en el predio equivocado. Se vuelve a la
       misma pantalla, pero solo si es una ruta conocida del sistema. */
    if (verificar_csrf(isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : null)) {
        cambiar_predio((int) (isset($_POST['id']) ? $_POST['id'] : 0));
    }
    $volver = isset($_POST['volver']) ? (string) $_POST['volver'] : '/';
    header('Location: ' . url(isset($paginas[$volver]) ? $volver : '/'));
} elseif (isset($paginas[$ruta])) {
    require __DIR__ . '/src/paginas/' . $paginas[$ruta] . '.php';
} else {
    http_response_code(404);
    require __DIR__ . '/src/vistas/error-404.php';
}
