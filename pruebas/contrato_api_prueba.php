<?php
declare(strict_types=1);

/*
 * Contrato de la API de la app de terreno (docs/api-terreno.md).
 *
 * Levanta un servidor PHP de verdad con su propia base de demostración y le
 * habla por HTTP, como lo hacen la app web y la app en Flutter. Si alguna de
 * estas pruebas falla, una de las dos apps se rompe: no se cambia el contrato
 * sin actualizarlas.
 */

/* ---------- Servidor de prueba ---------- */

final class ServidorContrato
{
    public static string $base = '';
    /** @var resource|null */
    private static $proceso = null;
    private static string $bd = '';

    public static function iniciar(): void
    {
        self::$bd = sys_get_temp_dir() . '/ganaderia-contrato-' . getmypid() . '.sqlite';
        @unlink(self::$bd);
        $puerto = 18000 + (getmypid() % 900) + random_int(0, 90);
        self::$base = 'http://127.0.0.1:' . $puerto;

        $entorno = getenv() + ['GANADERIA_BD_CONTRATO' => self::$bd];
        $entorno['GANADERIA_BD_CONTRATO'] = self::$bd;
        $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        self::$proceso = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $puerto, __DIR__ . '/servidor_contrato.php'],
            [0 => ['file', $nulo, 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']],
            $tuberias,
            dirname(__DIR__),
            $entorno,
        );
        register_shutdown_function([self::class, 'detener']);

        // Espera a que responda (la primera visita crea la base y la demostración).
        for ($i = 0; $i < 100; $i++) {
            if (@file_get_contents(self::$base . '/index.php?r=/salud') !== false) {
                return;
            }
            usleep(100_000);
        }
        throw new RuntimeException('El servidor de prueba no arrancó en ' . self::$base);
    }

    public static function detener(): void
    {
        if (is_resource(self::$proceso)) {
            proc_terminate(self::$proceso);
            proc_close(self::$proceso);
        }
        foreach (glob(self::$bd . '*') ?: [] as $resto) {
            @unlink($resto);
        }
        $datos = dirname(self::$bd) . '/' . basename(self::$bd, '.sqlite') . '-datos';
        foreach (glob($datos . '/*') ?: [] as $resto) {
            @unlink($resto);
        }
        @rmdir($datos);
    }
}

/**
 * Llamada a la API como la hace la app: ruta con ?r=, JSON y el token en las
 * dos cabeceras (o solo en una, para probar cada una).
 *
 * @return array{codigo: int, cuerpo: array, cabeceras: list<string>}
 */
function api(string $metodo, string $ruta, ?array $cuerpo = null, ?string $token = null, string $cabecera_token = 'ambas'): array
{
    $cabeceras = ['Accept: application/json'];
    if ($cuerpo !== null) {
        $cabeceras[] = 'Content-Type: application/json';
    }
    if ($token !== null) {
        if ($cabecera_token !== 'x-token') {
            $cabeceras[] = 'Authorization: Bearer ' . $token;
        }
        if ($cabecera_token !== 'authorization') {
            $cabeceras[] = 'X-Token: ' . $token;
        }
    }
    $contexto = stream_context_create(['http' => [
        'method' => $metodo,
        'header' => implode("\r\n", $cabeceras),
        'content' => $cuerpo !== null ? json_encode($cuerpo) : '',
        'ignore_errors' => true,
        'timeout' => 20,
    ]]);
    $respuesta = (string) file_get_contents(ServidorContrato::$base . '/index.php?r=/api/' . ltrim($ruta, '/'), false, $contexto);
    $lineas = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    preg_match('#HTTP/\S+ (\d{3})#', (string) ($lineas[0] ?? ''), $m);
    return ['codigo' => (int) ($m[1] ?? 0), 'cuerpo' => json_decode($respuesta, true) ?? [], 'cabeceras' => $lineas];
}

/** Ingresa como lo hace la app: si pide elegir organización (409), toma la primera. */
function ingresar(string $email, ?int $organizacion = null): string
{
    $r = api('POST', '/sesion', ['email' => $email, 'password' => 'demo1234'] + ($organizacion !== null ? ['organizacion_id' => $organizacion] : []));
    if ($r['codigo'] === 409 && $organizacion === null) {
        return ingresar($email, (int) $r['cuerpo']['organizaciones'][0]['id']);
    }
    afirmar_igual(200, $r['codigo'], 'ingreso de ' . $email . ': ' . json_encode($r['cuerpo']));
    return (string) $r['cuerpo']['token'];
}

/** Las claves del objeto, exactamente las esperadas (ni de más ni de menos). */
function afirmar_claves(array $esperadas, array $objeto, string $que): void
{
    $faltan = array_diff($esperadas, array_keys($objeto));
    afirmar($faltan === [], $que . ': faltan ' . implode(', ', $faltan));
}

function nuevo_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

ServidorContrato::iniciar();

/* ---------- /sesion ---------- */

prueba('sesion: credenciales malas dan 401 con mensaje', function (): void {
    $r = api('POST', '/sesion', ['email' => 'admin@ganaderia.cl', 'password' => 'mala']);
    afirmar_igual(401, $r['codigo'], 'código');
    afirmar(is_string($r['cuerpo']['error'] ?? null) && $r['cuerpo']['error'] !== '', 'falta error');
});

prueba('sesion: el ingreso devuelve token, usuario y operaciones', function (): void {
    $r = api('POST', '/sesion', ['email' => 'operario@ganaderia.cl', 'password' => 'demo1234']);
    if ($r['codigo'] === 409) {
        $r = api('POST', '/sesion', ['email' => 'operario@ganaderia.cl', 'password' => 'demo1234', 'organizacion_id' => $r['cuerpo']['organizaciones'][0]['id']]);
    }
    afirmar_igual(200, $r['codigo'], 'código: ' . json_encode($r['cuerpo']));
    afirmar_claves(['token', 'expira_en', 'usuario', 'operaciones'], $r['cuerpo'], 'respuesta');
    afirmar(preg_match('/^[a-f0-9]{64}$/', $r['cuerpo']['token']) === 1, 'token de 64 hex');
    afirmar(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $r['cuerpo']['expira_en']) === 1, 'expira_en');
    afirmar_claves(['id', 'nombre', 'rol', 'rol_nombre', 'organizacion'], $r['cuerpo']['usuario'], 'usuario');
    afirmar(is_int($r['cuerpo']['usuario']['id']), 'id entero');
    afirmar(array_is_list($r['cuerpo']['operaciones']) && in_array('PESADA', $r['cuerpo']['operaciones'], true), 'operaciones');
});

prueba('sesion: con varias organizaciones pide elegir (409) y después entra', function (): void {
    // En la demostración el administrador trabaja en dos organizaciones.
    $r = api('POST', '/sesion', ['email' => 'admin@ganaderia.cl', 'password' => 'demo1234']);
    afirmar_igual(409, $r['codigo'], 'código');
    afirmar(is_string($r['cuerpo']['error'] ?? null), 'falta error');
    $orgs = $r['cuerpo']['organizaciones'] ?? [];
    afirmar(count($orgs) >= 2, 'lista de organizaciones');
    afirmar_claves(['id', 'nombre', 'rol'], $orgs[0], 'organización');
    $r = api('POST', '/sesion', ['email' => 'admin@ganaderia.cl', 'password' => 'demo1234', 'organizacion_id' => (int) $orgs[0]['id']]);
    afirmar_igual(200, $r['codigo'], 'con organización elegida');
    afirmar(in_array('SALIDA', $r['cuerpo']['operaciones'], true), 'el administrador puede registrar salidas');
});

prueba('sesion: método equivocado da 405 con la cabecera Allow', function (): void {
    $r = api('GET', '/sesion');
    afirmar_igual(405, $r['codigo'], 'código');
    afirmar((bool) preg_grep('/^Allow: POST/i', $r['cabeceras']), 'falta Allow');
});

/* ---------- /catalogo ---------- */

prueba('catalogo: sin token da 401', function (): void {
    afirmar_igual(401, api('GET', '/catalogo')['codigo'], 'código');
});

prueba('catalogo: trae todas las claves y los animales con sus campos', function (): void {
    $r = api('GET', '/catalogo', null, ingresar('admin@ganaderia.cl'));
    afirmar_igual(200, $r['codigo'], 'código');
    $c = $r['cuerpo'];
    afirmar_claves(['generado_en', 'hoy', 'gdp_maxima', 'formato_diio', 'animales', 'corrales', 'raciones', 'productos', 'usuario', 'operaciones'], $c, 'catálogo');
    afirmar(preg_match('/^\d{4}-\d{2}-\d{2}$/', $c['hoy']) === 1, 'hoy');
    afirmar(is_int($c['gdp_maxima']) || is_float($c['gdp_maxima']), 'gdp_maxima numérico');
    afirmar(count($c['animales']) > 0, 'sin animales');
    afirmar_claves(['id', 'diio', 'eid', 'interno', 'sexo', 'categoria', 'etapa', 'raza', 'corral_id', 'peso', 'fecha_peso', 'carencia_hasta', 'parto_probable', 'secado'], $c['animales'][0], 'animal');
    afirmar(is_int($c['animales'][0]['id']), 'id de animal entero');
    afirmar_claves(['id', 'nombre', 'tipo', 'capacidad', 'ocupacion'], $c['corrales'][0], 'corral');
    afirmar_claves(['id', 'nombre', 'etapa', 'materia_seca'], $c['raciones'][0], 'ración');
    afirmar_claves(['id', 'nombre', 'unidad', 'carencia', 'stock'], $c['productos'][0], 'producto');
});

prueba('catalogo: el token sirve solo en X-Token o solo en Authorization', function (): void {
    $token = ingresar('admin@ganaderia.cl');
    afirmar_igual(200, api('GET', '/catalogo', null, $token, 'x-token')['codigo'], 'solo X-Token');
    afirmar_igual(200, api('GET', '/catalogo', null, $token, 'authorization')['codigo'], 'solo Authorization');
});

prueba('catalogo: quien valida recibe mva y tratamientos por validar', function (): void {
    $r = api('POST', '/sesion', ['email' => 'vet@ganaderia.cl', 'password' => 'demo1234']);
    $token = $r['codigo'] === 200 ? $r['cuerpo']['token'] : ingresar('vet@ganaderia.cl', (int) $r['cuerpo']['organizaciones'][0]['id']);
    $c = api('GET', '/catalogo', null, $token)['cuerpo'];
    afirmar_claves(['mva', 'por_validar', 'por_validar_total'], $c, 'veterinario');
    if ($c['por_validar'] !== []) {
        afirmar_claves(['id', 'fecha', 'producto', 'unidad', 'tipo', 'via', 'dosis', 'cabezas', 'carencia', 'diagnostico', 'donde', 'cargo'], $c['por_validar'][0], 'pendiente');
    }
});

prueba('catalogo: el operario no recibe tareas de venta ni de validación', function (): void {
    $c = api('GET', '/catalogo', null, ingresar('operario@ganaderia.cl'))['cuerpo'];
    afirmar(!in_array('SALIDA', $c['operaciones'], true) && !in_array('VALIDACION', $c['operaciones'], true), json_encode($c['operaciones']));
    afirmar(!array_key_exists('por_validar', $c), 'no debería traer pendientes');
});

/* ---------- /sincronizar ---------- */

prueba('sincronizar: OK trae id; el mismo uuid otra vez es DUPLICADA', function (): void {
    $token = ingresar('admin@ganaderia.cl');
    $animal = api('GET', '/catalogo', null, $token)['cuerpo']['animales'][0];
    $op = ['uuid' => nuevo_uuid(), 'tipo' => 'NOTA', 'datos' => ['animal_id' => $animal['id'], 'fecha' => hoy(), 'texto' => 'Prueba de contrato'], 'registrado_en' => ahora()];
    $r = api('POST', '/sincronizar', ['operaciones' => [$op]], $token);
    afirmar_igual(200, $r['codigo'], 'código');
    afirmar_claves(['resultados', 'servidor_hora'], $r['cuerpo'], 'respuesta');
    $res = $r['cuerpo']['resultados'][0];
    afirmar_claves(['uuid', 'estado', 'mensaje', 'id'], $res, 'resultado OK');
    afirmar_igual('OK', $res['estado'], 'estado: ' . $res['mensaje']);
    afirmar_igual($op['uuid'], $res['uuid'], 'uuid');
    afirmar_igual('DUPLICADA', api('POST', '/sincronizar', ['operaciones' => [$op]], $token)['cuerpo']['resultados'][0]['estado'], 'reintento');
});

prueba('sincronizar: RECHAZADA trae mensaje, errores y requiere_confirmacion', function (): void {
    $token = ingresar('admin@ganaderia.cl');
    $op = ['uuid' => nuevo_uuid(), 'tipo' => 'PESADA', 'datos' => ['identificacion' => 'NO-EXISTE-999', 'fecha' => hoy(), 'peso_kg' => 300], 'registrado_en' => ahora()];
    $res = api('POST', '/sincronizar', ['operaciones' => [$op]], $token)['cuerpo']['resultados'][0];
    afirmar_igual('RECHAZADA', $res['estado'], 'estado');
    afirmar_claves(['uuid', 'estado', 'mensaje', 'errores', 'requiere_confirmacion'], $res, 'resultado rechazado');
    afirmar(is_array($res['errores']) && $res['errores'] !== [], 'errores por campo');
    afirmar(is_bool($res['requiere_confirmacion']), 'requiere_confirmacion booleano');
});

prueba('sincronizar: un peso anormal pide confirmación y con confirmar:true entra', function (): void {
    $token = ingresar('admin@ganaderia.cl');
    $catalogo = api('GET', '/catalogo', null, $token)['cuerpo'];
    $animales = array_values(array_filter($catalogo['animales'], static fn (array $a): bool => $a['peso'] !== null && $a['fecha_peso'] !== null && substr($a['fecha_peso'], 0, 10) < hoy()));
    afirmar($animales !== [], 'sin animal con pesaje previo');
    // El pesado más recientemente: con pocos días, un salto grande es anormal seguro.
    usort($animales, static fn (array $x, array $y): int => strcmp($y['fecha_peso'], $x['fecha_peso']));
    $a = $animales[0];
    $dias = max(1, dias_entre(substr($a['fecha_peso'], 0, 10), hoy()));
    $peso = min(1500.0, round($a['peso'] + $catalogo['gdp_maxima'] * 3 * $dias + 20, 1));
    $op = ['uuid' => nuevo_uuid(), 'tipo' => 'PESADA', 'datos' => ['animal_id' => $a['id'], 'fecha' => hoy(), 'peso_kg' => $peso, 'motivo' => 'CONTROL'], 'registrado_en' => ahora()];
    $res = api('POST', '/sincronizar', ['operaciones' => [$op]], $token)['cuerpo']['resultados'][0];
    afirmar_igual('RECHAZADA', $res['estado'], 'sin confirmar: ' . $res['mensaje']);
    afirmar_igual(true, $res['requiere_confirmacion'], 'requiere_confirmacion');
    $op['datos']['confirmar'] = true;
    afirmar_igual('OK', api('POST', '/sincronizar', ['operaciones' => [$op]], $token)['cuerpo']['resultados'][0]['estado'], 'confirmado');
});

prueba('sincronizar: uuid inválido y tipo desconocido se rechazan', function (): void {
    $token = ingresar('admin@ganaderia.cl');
    $r = api('POST', '/sincronizar', ['operaciones' => [
        ['uuid' => 'no-es-uuid', 'tipo' => 'NOTA', 'datos' => []],
        ['uuid' => nuevo_uuid(), 'tipo' => 'INVENTADA', 'datos' => []],
    ]], $token)['cuerpo']['resultados'];
    afirmar_igual('RECHAZADA', $r[0]['estado'], 'uuid');
    afirmar_igual('RECHAZADA', $r[1]['estado'], 'tipo');
});

prueba('sincronizar: sin lista da 400 y más de 100 da 413', function (): void {
    $token = ingresar('admin@ganaderia.cl');
    afirmar_igual(400, api('POST', '/sincronizar', ['operaciones' => 'x'], $token)['codigo'], 'sin lista');
    $muchas = array_fill(0, 101, ['uuid' => nuevo_uuid(), 'tipo' => 'NOTA', 'datos' => []]);
    afirmar_igual(413, api('POST', '/sincronizar', ['operaciones' => $muchas], $token)['codigo'], 'demasiadas');
});

prueba('sincronizar: cada tipo de operación de la app existe en el servidor', function (): void {
    $documentados = ['PESADA', 'PARTO', 'SECADO', 'NOTA', 'ANIMAL', 'TRATAMIENTO', 'ENTREGA', 'TRASLADO', 'BAJA', 'VALIDACION', 'SALIDA'];
    foreach ($documentados as $tipo) {
        afirmar(isset(TIPOS_OPERACION[$tipo]), 'falta ' . $tipo);
    }
    afirmar_igual(count($documentados), count(TIPOS_OPERACION), 'hay tipos nuevos: documentarlos en docs/api-terreno.md');
});

/* ---------- /sesion/cerrar y rutas ---------- */

prueba('cerrar sesión revoca el token', function (): void {
    $token = ingresar('admin@ganaderia.cl');
    $r = api('POST', '/sesion/cerrar', null, $token);
    afirmar_igual(200, $r['codigo'], 'código');
    afirmar_igual(true, $r['cuerpo']['ok'] ?? null, 'ok');
    afirmar_igual(401, api('GET', '/catalogo', null, $token)['codigo'], 'el token ya no sirve');
});

prueba('una ruta desconocida da 404 en JSON', function (): void {
    $r = api('GET', '/no-existe');
    afirmar_igual(404, $r['codigo'], 'código');
    afirmar(is_string($r['cuerpo']['error'] ?? null), 'error en JSON');
});
