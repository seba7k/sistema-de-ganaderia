<?php
declare(strict_types=1);

/**
 * Aviso automático de errores del servidor.
 *
 * Todo error no controlado (excepción, error fatal, caída de la base) se anota
 * en datos/errores.log —un archivo, no la base: si lo que falló es la base,
 * igual tiene que quedar registrado— y se avisa al administrador por los
 * canales configurados en config.php → 'alertas':
 *
 *   · correo:   con mail() del propio hosting (cPanel lo trae; no necesita Google)
 *   · telegram: un bot de Telegram (token y chat), llega como notificación al teléfono
 *   · webhook:  una URL que recibe el aviso en JSON (Slack, Discord, Make, etc.)
 *
 * Para no inundar a nadie: el mismo error se avisa como máximo una vez por hora
 * (las repeticiones se cuentan y salen en el aviso siguiente) y en total no se
 * mandan más de 20 avisos por hora.
 *
 * Privacidad (Ley 21.719): el aviso sale del sistema hacia terceros (Telegram,
 * el proveedor de correo), así que lleva lo mínimo: tipo de error, archivo y
 * línea, la ruta sin parámetros y el mensaje con correos, RUT y números largos
 * enmascarados. Nunca lleva lo que se escribió en un formulario.
 *
 * Este archivo lo carga el arranque, que tiene que funcionar incluso en un PHP
 * viejo para poder decir qué falta: por eso evita la sintaxis de PHP 8.
 */

const ALERTAS_POR_ERROR_MINUTOS = 60;
const ALERTAS_MAXIMO_POR_HORA = 20;

/** Configuración de alertas con sus valores por defecto. */
function config_alertas()
{
    $c = defined('CONFIG_ALERTAS') ? CONFIG_ALERTAS : array();
    return array_merge(array(
        'correo' => '',
        'telegram_token' => '',
        'telegram_chat' => '',
        'webhook' => '',
        'nombre_sitio' => isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'Gestión ganadera',
    ), is_array($c) ? $c : array());
}

function carpeta_alertas()
{
    return defined('CARPETA_DATOS') ? CARPETA_DATOS : RAIZ_APP . '/datos';
}

/** Saca del texto lo que podría identificar a una persona. */
function enmascarar_datos($texto)
{
    $texto = (string) $texto;
    // Correos
    $texto = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[correo]', $texto);
    // RUT chileno con o sin puntos: 12.345.678-9 / 12345678-K
    $texto = preg_replace('/\b\d{1,2}\.?\d{3}\.?\d{3}-[\dkK]\b/', '[rut]', $texto);
    // Secuencias largas de dígitos (teléfonos, documentos); los DIIO cortos y las líneas quedan.
    $texto = preg_replace('/\b\d{9,}\b/', '[número]', $texto);
    // Lo que va entre comillas en un mensaje de SQL suele ser un dato cargado.
    $texto = preg_replace("/'[^']{3,}'/", "'[dato]'", $texto);
    return $texto;
}

/** Ruta pedida, sin parámetros (los parámetros pueden traer datos). */
function ruta_sin_parametros()
{
    if (PHP_SAPI === 'cli') {
        return 'consola';
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $ruta = (string) parse_url($uri, PHP_URL_PATH);
    if (isset($_GET['r']) && is_string($_GET['r'])) {
        $ruta .= ' ' . substr($_GET['r'], 0, 60);
    }
    return substr($ruta, 0, 120);
}

/**
 * Anota un error y, si corresponde, avisa.
 *
 * @param string $nivel  CRITICO (la base no responde, error fatal) o ERROR
 * @return array{huella: string, avisado: bool}
 */
function registrar_error_servidor($nivel, $tipo, $mensaje, $archivo = '', $linea = 0)
{
    $mensaje = enmascarar_datos(substr((string) $mensaje, 0, 1000));
    $archivo = str_replace('\\', '/', (string) $archivo);
    $raiz = str_replace('\\', '/', RAIZ_APP);
    if (strpos($archivo, $raiz) === 0) {
        $archivo = substr($archivo, strlen($raiz) + 1);
    }
    // La huella agrupa repeticiones del mismo error aunque cambien números o datos.
    $huella = substr(sha1($tipo . '|' . $archivo . '|' . (int) $linea . '|' . preg_replace('/\d+/', '#', $mensaje)), 0, 16);

    $registro = array(
        'momento' => date('Y-m-d H:i:s'),
        'nivel' => $nivel,
        'tipo' => (string) $tipo,
        'mensaje' => $mensaje,
        'archivo' => $archivo,
        'linea' => (int) $linea,
        'ruta' => ruta_sin_parametros(),
        'huella' => $huella,
    );

    $carpeta = carpeta_alertas();
    if (!is_dir($carpeta)) {
        @mkdir($carpeta, 0755, true);
    }
    @file_put_contents($carpeta . '/errores.log', json_encode($registro, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

    $avisado = false;
    if (debe_avisar($huella, $repeticiones)) {
        $avisado = enviar_alerta($registro, $repeticiones);
    }
    return array('huella' => $huella, 'avisado' => $avisado);
}

/**
 * Decide si este error se avisa ahora, con un archivo de estado: cuándo se
 * avisó cada huella y cuántas veces se repitió desde entonces.
 */
function debe_avisar($huella, &$repeticiones)
{
    $repeticiones = 0;
    $archivo = carpeta_alertas() . '/alertas-estado.json';
    $manejador = @fopen($archivo, 'c+');
    if ($manejador === false) {
        return true; // Sin poder llevar la cuenta, es mejor avisar que callar.
    }
    flock($manejador, LOCK_EX);
    $estado = json_decode((string) stream_get_contents($manejador), true);
    if (!is_array($estado)) {
        $estado = array('huellas' => array(), 'envios' => array());
    }
    $ahora = time();

    // Olvida lo viejo: envíos de hace más de una hora y huellas de hace más de un día.
    $estado['envios'] = array_values(array_filter($estado['envios'], function ($t) use ($ahora) {
        return $t > $ahora - 3600;
    }));
    foreach ($estado['huellas'] as $h => $datos) {
        if ($datos['ultimo'] < $ahora - 86400) {
            unset($estado['huellas'][$h]);
        }
    }

    $previo = isset($estado['huellas'][$huella]) ? $estado['huellas'][$huella] : null;
    $avisar = ($previo === null || $previo['ultimo'] <= $ahora - ALERTAS_POR_ERROR_MINUTOS * 60)
        && count($estado['envios']) < ALERTAS_MAXIMO_POR_HORA;

    if ($avisar) {
        $repeticiones = $previo === null ? 0 : (int) $previo['repetido'];
        $estado['huellas'][$huella] = array('ultimo' => $ahora, 'repetido' => 0);
        $estado['envios'][] = $ahora;
    } else {
        $estado['huellas'][$huella] = array(
            'ultimo' => $previo === null ? $ahora : $previo['ultimo'],
            'repetido' => ($previo === null ? 0 : (int) $previo['repetido']) + 1,
        );
    }

    ftruncate($manejador, 0);
    rewind($manejador);
    fwrite($manejador, json_encode($estado));
    flock($manejador, LOCK_UN);
    fclose($manejador);
    return $avisar;
}

/** Texto del aviso, igual para todos los canales. */
function texto_alerta(array $r, $repeticiones)
{
    $c = config_alertas();
    return ($r['nivel'] === 'CRITICO' ? '🔴' : '🟠') . ' Error en ' . $c['nombre_sitio'] . "\n"
        . $r['momento'] . ' · ' . $r['tipo'] . "\n"
        . $r['mensaje'] . "\n"
        . ($r['archivo'] !== '' ? $r['archivo'] . ':' . $r['linea'] . "\n" : '')
        . 'Ruta: ' . $r['ruta'] . "\n"
        . ($repeticiones > 0 ? 'Se repitió ' . $repeticiones . ' veces desde el aviso anterior.' . "\n" : '')
        . 'Detalle en Sistema → Errores del servidor.';
}

/** Manda el aviso por cada canal configurado. Devuelve true si salió por alguno. */
function enviar_alerta(array $registro, $repeticiones)
{
    $c = config_alertas();
    $texto = texto_alerta($registro, $repeticiones);
    $enviado = false;

    // Pruebas automáticas: se anota lo que se habría mandado.
    if (defined('ALERTAS_SIMULADAS')) {
        $GLOBALS['alertas_enviadas'][] = $texto;
        return true;
    }

    if ($c['correo'] !== '' && function_exists('mail')) {
        $asunto = '=?UTF-8?B?' . base64_encode('Error en ' . $c['nombre_sitio']) . '?=';
        $enviado = @mail($c['correo'], $asunto, $texto, "Content-Type: text/plain; charset=UTF-8\r\n") || $enviado;
    }
    if ($c['telegram_token'] !== '' && $c['telegram_chat'] !== '') {
        $enviado = enviar_post_json(
            'https://api.telegram.org/bot' . $c['telegram_token'] . '/sendMessage',
            array('chat_id' => $c['telegram_chat'], 'text' => $texto, 'disable_web_page_preview' => true)
        ) || $enviado;
    }
    if ($c['webhook'] !== '') {
        $enviado = enviar_post_json($c['webhook'], array('text' => $texto, 'error' => $registro)) || $enviado;
    }
    return $enviado;
}

/** POST con JSON y un tiempo máximo corto: un canal caído no puede colgar el sitio. */
function enviar_post_json($url, array $datos)
{
    if (strpos($url, 'https://') !== 0) {
        return false;
    }
    $contexto = stream_context_create(array('http' => array(
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode($datos, JSON_UNESCAPED_UNICODE),
        'timeout' => 4,
        'ignore_errors' => true,
    )));
    $respuesta = @file_get_contents($url, false, $contexto);
    return $respuesta !== false;
}

/** ¿Hay algún canal configurado? */
function alertas_configuradas()
{
    $c = config_alertas();
    return $c['correo'] !== '' || ($c['telegram_token'] !== '' && $c['telegram_chat'] !== '') || $c['webhook'] !== '';
}

/**
 * Errores agrupados por huella, del más reciente al más antiguo, para la
 * pantalla de errores. Lee solo el final del archivo: puede ser largo.
 *
 * @return list<array>
 */
function errores_recientes($dias = 30)
{
    $archivo = carpeta_alertas() . '/errores.log';
    if (!is_file($archivo)) {
        return array();
    }
    $limite = date('Y-m-d H:i:s', time() - $dias * 86400);
    $revisados = errores_revisados();
    $grupos = array();
    $tamano = filesize($archivo);
    $manejador = fopen($archivo, 'r');
    if ($tamano > 2000000) {
        fseek($manejador, $tamano - 2000000);
        fgets($manejador); // descarta la línea cortada
    }
    while (($linea = fgets($manejador)) !== false) {
        $r = json_decode($linea, true);
        if (!is_array($r) || $r['momento'] < $limite) {
            continue;
        }
        $h = $r['huella'];
        if (!isset($grupos[$h])) {
            $grupos[$h] = $r + array('veces' => 0, 'primera' => $r['momento']);
        }
        $grupos[$h]['veces']++;
        $grupos[$h]['momento'] = $r['momento'];
        $grupos[$h]['mensaje'] = $r['mensaje'];
        $grupos[$h]['ruta'] = $r['ruta'];
    }
    fclose($manejador);
    foreach ($grupos as $h => $g) {
        // Revisado = se marcó después de su última aparición; si vuelve a pasar, reaparece.
        $grupos[$h]['revisado'] = isset($revisados[$h]) && $revisados[$h] >= $g['momento'];
    }
    usort($grupos, function ($a, $b) {
        return strcmp($b['momento'], $a['momento']);
    });
    return $grupos;
}

function errores_revisados()
{
    $archivo = carpeta_alertas() . '/errores-revisados.json';
    $datos = is_file($archivo) ? json_decode((string) file_get_contents($archivo), true) : null;
    return is_array($datos) ? $datos : array();
}

function marcar_error_revisado($huella)
{
    $revisados = errores_revisados();
    $revisados[(string) $huella] = date('Y-m-d H:i:s');
    file_put_contents(carpeta_alertas() . '/errores-revisados.json', json_encode($revisados), LOCK_EX);
}

/** Cuántos errores distintos sin revisar hubo en las últimas 24 horas. */
function errores_sin_revisar()
{
    $n = 0;
    foreach (errores_recientes(1) as $g) {
        if (!$g['revisado']) {
            $n++;
        }
    }
    return $n;
}

/**
 * Engancha el registro a PHP: excepciones las pasa el manejador del arranque;
 * acá se agregan los errores fatales, que no pasan por ningún manejador.
 */
function activar_alertas_de_errores()
{
    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
            registrar_error_servidor('CRITICO', 'Error fatal', $error['message'], $error['file'], $error['line']);
        }
    });
}
