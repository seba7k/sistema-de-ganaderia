<?php
declare(strict_types=1);

/**
 * Apoyo de las pruebas: configuración aislada, base de demo nueva y aserciones.
 *
 * Lo carga correr.php en un proceso propio por cada archivo de prueba, así cada
 * archivo arranca con la base de demostración recién creada y lo que cambia uno
 * no afecta al siguiente.
 */

/** Configuración de prueba: SQLite en una carpeta temporal y gestión avanzada. */
function configurar_pruebas(string $formato_diio = ''): void
{
    $archivo = sys_get_temp_dir() . '/ganaderia-pruebas-' . getmypid() . '.sqlite';
    foreach ([$archivo, $archivo . '-journal', $archivo . '.lock'] as $viejo) {
        if (is_file($viejo)) {
            unlink($viejo);
        }
    }
    register_shutdown_function(static function () use ($archivo): void {
        foreach ([$archivo, $archivo . '-journal', $archivo . '.lock'] as $resto) {
            if (is_file($resto)) {
                @unlink($resto);
            }
        }
    });

    define('CONFIG_FORZADA', [
        'base_datos'       => ['motor' => 'sqlite', 'archivo' => $archivo],
        'gestion_avanzada' => true,
        'demo'             => true,
        'formato_diio'     => $formato_diio,
        'clave_huella'     => 'clave-de-prueba-no-usar-en-produccion',
        'zona_horaria'     => 'America/Santiago',
    ]);
    define('FORZAR_DATOS_DEMO', true);

    require_once __DIR__ . '/../src/auth.php';
    require_once __DIR__ . '/../src/operaciones.php';
    require_once __DIR__ . '/../src/api.php';
    db(); // crea la base y carga la demostración
}

final class Resultados
{
    public static int $bien = 0;
    /** @var list<string> */
    public static array $fallas = [];
    public static string $actual = '';
}

final class FallaPrueba extends RuntimeException
{
}

function prueba(string $nombre, callable $cuerpo): void
{
    Resultados::$actual = $nombre;
    try {
        $cuerpo();
        Resultados::$bien++;
        echo "  ok    {$nombre}\n";
    } catch (Throwable $error) {
        $detalle = $error instanceof FallaPrueba ? $error->getMessage() : get_class($error) . ': ' . $error->getMessage();
        Resultados::$fallas[] = $nombre . ' — ' . $detalle;
        echo "  FALLA {$nombre}\n        {$detalle}\n";
    }
}

function afirmar(bool $condicion, string $mensaje = 'la condición no se cumplió'): void
{
    if (!$condicion) {
        throw new FallaPrueba($mensaje);
    }
}

function afirmar_igual(mixed $esperado, mixed $obtenido, string $que = 'valor'): void
{
    afirmar($esperado === $obtenido, $que . ': se esperaba ' . var_export($esperado, true) . ' y salió ' . var_export($obtenido, true));
}

/** Errores por campo de la ErrorOperacion que lanza $c, o null si no lanzó ninguna. */
function error_de(callable $c): ?array
{
    try {
        $c();
        return null;
    } catch (ErrorOperacion $error) {
        return $error->errores;
    }
}

/** Afirma que $c lanza una ErrorOperacion cuyo texto contiene $fragmento. */
function afirmar_rechazo(callable $c, string $fragmento): void
{
    $errores = error_de($c);
    afirmar($errores !== null, 'se esperaba un rechazo con «' . $fragmento . '» y se guardó');
    afirmar(str_contains(implode(' ', $errores), $fragmento), 'el rechazo dice «' . implode(' ', $errores) . '», no «' . $fragmento . '»');
}

/** Id de un usuario de la demostración por su correo. */
function id_usuario(string $email): int
{
    return (int) valor('SELECT id FROM usuarios WHERE email = ?', [$email]);
}

/** Primer tratamiento de la organización sin validar ni anular. */
function tratamiento_pendiente(int $org = 1): int
{
    return (int) valor(
        'SELECT id FROM tratamientos WHERE organizacion_id = ? AND validado_en IS NULL AND anulada = 0 ORDER BY id LIMIT 1',
        [$org],
    );
}

function tratamiento(int $id): array
{
    return fila('SELECT * FROM tratamientos WHERE id = ?', [$id]);
}

/** Envía una operación como lo hace la app y devuelve el resultado. */
function sincronizar_como(string $email, int $org, array $operacion): array
{
    $rol = (string) valor('SELECT rol FROM membresias WHERE organizacion_id = ? AND usuario_id = ?', [$org, id_usuario($email)]);
    return procesar_operacion($org, id_usuario($email), $rol, $operacion);
}

/** Lee un ZIP armado por zip_armar(): ruta => contenido. Para revisar los .xlsx. */
function leer_zip(string $zip): array
{
    $archivos = [];
    $pos = 0;
    while (substr($zip, $pos, 4) === "PK\x03\x04") {
        $c = unpack('vversion/vbanderas/vmetodo/vhora/vfecha/Vcrc/Vcomprimido/Voriginal/vlargo/vextra', substr($zip, $pos + 4, 26));
        $nombre = substr($zip, $pos + 30, $c['largo']);
        $datos = substr($zip, $pos + 30 + $c['largo'] + $c['extra'], $c['comprimido']);
        $contenido = $c['metodo'] === 8 ? (string) gzinflate($datos) : $datos;
        afirmar(crc32($contenido) === $c['crc'], 'CRC de ' . $nombre);
        afirmar(strlen($contenido) === $c['original'], 'tamaño de ' . $nombre);
        $archivos[$nombre] = $contenido;
        $pos += 30 + $c['largo'] + $c['extra'] + $c['comprimido'];
    }
    afirmar(substr($zip, $pos, 4) === "PK\x01\x02", 'falta el directorio central');
    return $archivos;
}
