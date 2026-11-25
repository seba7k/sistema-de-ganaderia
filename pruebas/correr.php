<?php
declare(strict_types=1);

/**
 * Pruebas automáticas. Solo por consola: `php pruebas/correr.php`
 *
 * Corre cada archivo `*_prueba.php` de esta carpeta en un proceso propio, con
 * una base SQLite de demostración recién creada en la carpeta temporal. No usa
 * config.php ni toca la base de la instalación.
 *
 * Los archivos `*_formato_prueba.php` corren con un formato de DIIO de ejemplo
 * (nueve dígitos), para probar la validación que se activa con `formato_diio`.
 *
 *   php pruebas/correr.php                  todas
 *   php pruebas/correr.php mva_prueba.php   una sola
 *
 * Termina con código 1 si alguna prueba falla.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const FORMATO_DE_PRUEBA = '/^\d{9}$/';

/* ---------- Un archivo, dentro de su propio proceso ---------- */
if (($argv[1] ?? '') === '--uno') {
    $archivo = __DIR__ . '/' . basename((string) ($argv[2] ?? ''));
    if (!is_file($archivo)) {
        fwrite(STDERR, "No existe {$archivo}\n");
        exit(2);
    }
    require __DIR__ . '/apoyo.php';
    configurar_pruebas(str_ends_with($archivo, '_formato_prueba.php') ? FORMATO_DE_PRUEBA : '');
    require $archivo;
    echo 'RESULTADO ' . Resultados::$bien . ' ' . count(Resultados::$fallas) . "\n";
    exit(Resultados::$fallas === [] ? 0 : 1);
}

/* ---------- Todos los archivos, uno por proceso ---------- */
$archivos = isset($argv[1])
    ? [basename($argv[1])]
    : array_map('basename', glob(__DIR__ . '/*_prueba.php') ?: []);
sort($archivos);

$bien = 0;
$fallas = 0;
$con_error = [];
foreach ($archivos as $archivo) {
    echo "\n{$archivo}\n";
    $salida = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --uno ' . escapeshellarg($archivo) . ' 2>&1', $salida, $codigo);
    $resumen = null;
    foreach ($salida as $linea) {
        if (preg_match('/^RESULTADO (\d+) (\d+)$/', $linea, $m) === 1) {
            $resumen = [(int) $m[1], (int) $m[2]];
            continue;
        }
        echo $linea, "\n";
    }
    if ($resumen === null) {
        // El archivo ni llegó a terminar: error fatal o sintaxis.
        $con_error[] = $archivo;
        $fallas++;
        continue;
    }
    $bien += $resumen[0];
    $fallas += $resumen[1];
}

echo "\n" . str_repeat('─', 40) . "\n";
echo "{$bien} bien, {$fallas} fallas" . ($con_error !== [] ? ' (no terminaron: ' . implode(', ', $con_error) . ')' : '') . "\n";
exit($fallas === 0 ? 0 : 1);
