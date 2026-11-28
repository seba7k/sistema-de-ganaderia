<?php
declare(strict_types=1);

/**
 * Servidor de prueba para el contrato de la API.
 *
 * Lo levanta pruebas/contrato_api_prueba.php con el servidor integrado de PHP:
 *
 *   php -S 127.0.0.1:PUERTO pruebas/servidor_contrato.php
 *
 * Usa una base SQLite de demostración propia (la ruta llega en la variable de
 * entorno GANADERIA_BD_CONTRATO), así que no toca config.php ni la base real.
 * Fuera del servidor integrado no responde nada: en Apache, además, la carpeta
 * pruebas/ está bloqueada por .htaccess.
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$archivo = (string) getenv('GANADERIA_BD_CONTRATO');
if ($archivo === '') {
    http_response_code(500);
    exit('Falta GANADERIA_BD_CONTRATO');
}

define('CONFIG_FORZADA', [
    'base_datos'       => ['motor' => 'sqlite', 'archivo' => $archivo],
    'gestion_avanzada' => true,
    'demo'             => true,
    'formato_diio'     => '',
    'clave_huella'     => 'clave-de-prueba-no-usar-en-produccion',
    'zona_horaria'     => 'America/Santiago',
    'carpeta_datos'    => dirname($archivo) . '/' . basename($archivo, '.sqlite') . '-datos',
    'url_amigables'    => true,
]);
define('FORZAR_DATOS_DEMO', true);
define('ALERTAS_SIMULADAS', true);

return require __DIR__ . '/../index.php';
