<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/operaciones.php';

/**
 * API REST de la app de terreno. JSON sobre HTTPS.
 *
 *   POST /api/sesion         correo y contraseña → token
 *   POST /api/sesion/cerrar  revoca el token
 *   GET  /api/catalogo       animales, corrales, raciones y productos para trabajar sin señal
 *   POST /api/sincronizar    operaciones registradas sin señal → resultado de cada una
 *
 * Con URL amigables las rutas son /api/…; sin mod_rewrite, index.php?r=/api/….
 * La app usa siempre la segunda forma, que funciona en cualquier hosting.
 *
 * La web usa una cookie de sesión que se cierra a los 60 minutos sin actividad.
 * La app no puede depender de eso, porque trabaja días enteros sin señal: se
 * identifica con un token de larga duración. En la base se guarda solo su hash.
 */

const TIPOS_OPERACION = [
    'PESADA'      => ['permiso' => 'registrar_pesada', 'funcion' => 'op_pesada'],
    'PARTO'       => ['permiso' => 'registrar_parto', 'funcion' => 'op_parto'],
    'SECADO'      => ['permiso' => 'registrar_secado', 'funcion' => 'op_secado'],
    'ANIMAL'      => ['permiso' => 'registrar_animal', 'funcion' => 'op_alta_animal'],
    'NOTA'        => ['permiso' => 'registrar_nota', 'funcion' => 'op_nota'],
    'TRATAMIENTO' => ['permiso' => 'registrar_tratamiento', 'funcion' => 'op_tratamiento'],
    'ENTREGA'     => ['permiso' => 'registrar_entrega', 'funcion' => 'op_entrega'],
    'TRASLADO'    => ['permiso' => 'registrar_traslado', 'funcion' => 'op_traslado'],
    'BAJA'        => ['permiso' => 'registrar_baja', 'funcion' => 'op_baja'],
    'VALIDACION'  => ['permiso' => 'validar_tratamiento', 'funcion' => 'op_validar_tratamiento'],
    'SALIDA'      => ['permiso' => 'registrar_venta', 'funcion' => 'op_salida'],
];

/** Operaciones que solo existen con la gestión avanzada: sin ella no hay sanidad con productos. */
const OPERACIONES_AVANZADAS = ['TRATAMIENTO', 'VALIDACION'];

const MAXIMO_OPERACIONES_POR_ENVIO = 100;

function responder(int $codigo, array $cuerpo): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cuerpo_json(): array
{
    $crudo = (string) file_get_contents('php://input');
    if ($crudo === '') {
        return [];
    }
    if (strlen($crudo) > 2_000_000) {
        responder(413, ['error' => 'El envío es demasiado grande.']);
    }
    $datos = json_decode($crudo, true);
    if (!is_array($datos)) {
        responder(400, ['error' => 'El cuerpo de la petición no es JSON válido.']);
    }
    return $datos;
}

function exigir_metodo(string $metodo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $metodo) {
        header('Allow: ' . $metodo);
        responder(405, ['error' => 'Método no permitido: usá ' . $metodo . '.']);
    }
}

/**
 * Token recibido. Va en «Authorization: Bearer …»; como hay hostings que le
 * quitan esa cabecera a PHP, también se acepta en «X-Token».
 */
function token_recibido(): ?string
{
    $cabecera = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($cabecera), $coincidencia) === 1) {
        return strtolower($coincidencia[1]);
    }
    $alternativa = trim((string) ($_SERVER['HTTP_X_TOKEN'] ?? ''));
    return preg_match('/^[a-f0-9]{64}$/i', $alternativa) === 1 ? strtolower($alternativa) : null;
}

/**
 * Quién llama. El rol se lee de la membresía vigente y no del token: si el
 * administrador le cambia el rol a alguien o le quita el acceso, rige desde la
 * siguiente sincronización y no recién cuando el token vence.
 */
function identidad_api(): array
{
    $token = token_recibido();
    if ($token === null) {
        responder(401, ['error' => 'Falta el token de sesión.']);
    }

    $quien = fila(
        'SELECT t.id AS token_id, t.expira_en, t.usuario_id, t.organizacion_id, m.rol,
                u.nombre, u.apellido, o.nombre AS organizacion
           FROM tokens_api t
           JOIN usuarios u ON u.id = t.usuario_id
           JOIN membresias m ON m.usuario_id = t.usuario_id AND m.organizacion_id = t.organizacion_id
           JOIN organizaciones o ON o.id = t.organizacion_id
          WHERE t.token_hash = ? AND t.revocado = 0 AND u.activo = 1 AND m.activa = 1 AND o.activa = 1',
        [hash('sha256', $token)],
    );
    if ($quien === null || $quien['expira_en'] < ahora()) {
        responder(401, ['error' => 'La sesión de la app venció. Volvé a ingresar: lo pendiente sigue guardado en el teléfono.']);
    }
    if (!rol_puede($quien['rol'], 'usar_app')) {
        responder(403, ['error' => 'Tu rol no tiene acceso a la app de terreno.']);
    }

    /* Se renueva el vencimiento mientras el teléfono se siga usando: un equipo
       que sincroniza todas las semanas no tiene por qué volver a ingresar, y uno
       perdido caduca igual a los días de sesión de su última sincronización.
       La configuración ya prometía esto; antes no ocurría. */
    consultar(
        'UPDATE tokens_api SET ultimo_uso_en = ?, expira_en = ? WHERE id = ?',
        [
            ahora(),
            (new DateTimeImmutable())->modify('+' . DIAS_SESION_APP . ' days')->format('Y-m-d H:i:s'),
            (int) $quien['token_id'],
        ],
    );
    return $quien;
}

/**
 * Tipos de operación que el rol puede registrar: la app muestra solo esos
 * botones. El tratamiento con producto, dosis y carencia solo existe con la
 * gestión avanzada encendida.
 */
function operaciones_del_rol(string $rol): array
{
    return array_values(array_filter(
        array_keys(TIPOS_OPERACION),
        static fn (string $tipo): bool => rol_puede($rol, TIPOS_OPERACION[$tipo]['permiso'])
            && (GESTION_AVANZADA || !in_array($tipo, OPERACIONES_AVANZADAS, true)),
    ));
}

function datos_usuario_api(array $quien): array
{
    return [
        'id'           => (int) $quien['usuario_id'],
        'nombre'       => trim($quien['nombre'] . ' ' . $quien['apellido']),
        'rol'          => $quien['rol'],
        'rol_nombre'   => nombre_rol($quien['rol']),
        'organizacion' => $quien['organizacion'],
    ];
}

function atender_api(string $ruta): void
{
    match ($ruta) {
        '/sesion'        => api_sesion(),
        '/sesion/cerrar' => api_cerrar_sesion(),
        '/catalogo'      => api_catalogo(),
        '/sincronizar'   => api_sincronizar(),
        default          => responder(404, ['error' => 'Ruta de API desconocida.']),
    };
}

function api_sesion(): never
{
    exigir_metodo('POST');
    $datos = cuerpo_json();

    $organizacion = isset($datos['organizacion_id']) && is_numeric($datos['organizacion_id'])
        ? (int) $datos['organizacion_id']
        : null;
    $resultado = verificar_credenciales(
        is_string($datos['email'] ?? null) ? $datos['email'] : '',
        is_string($datos['password'] ?? null) ? $datos['password'] : '',
        $organizacion,
    );

    if (isset($resultado['organizaciones'])) {
        responder(409, [
            'error' => 'Trabajás en más de una organización: elegí con cuál vas a trabajar.',
            'organizaciones' => array_map(static fn (array $o): array => [
                'id' => (int) $o['organizacion_id'], 'nombre' => $o['nombre'], 'rol' => nombre_rol($o['rol']),
            ], $resultado['organizaciones']),
        ]);
    }
    if (!$resultado['ok']) {
        responder(401, ['error' => $resultado['error']]);
    }

    $usuario = $resultado['usuario'];
    if (!rol_puede($usuario['rol'], 'usar_app')) {
        responder(403, ['error' => 'Tu rol no tiene acceso a la app de terreno.']);
    }

    $token = bin2hex(random_bytes(32));
    $expira = (new DateTimeImmutable())->modify('+' . DIAS_SESION_APP . ' days')->format('Y-m-d H:i:s');
    consultar(
        'INSERT INTO tokens_api (organizacion_id, usuario_id, token_hash, rol, dispositivo, creado_en, expira_en)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $usuario['organizacion_id'], $usuario['id'], hash('sha256', $token), $usuario['rol'],
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160), ahora(), $expira,
        ],
    );

    /* Un teléfono por persona es lo normal; dos, quien cambió de equipo. Más que
       eso son sesiones olvidadas en aparatos que ya no están: se revocan las más
       viejas y se limpian las vencidas, que si no la tabla crece para siempre.
       El desempate va por id y no solo por fecha: dos ingresos en el mismo
       segundo empataban, y el que se revocaba podía ser el recién creado. */
    $token_nuevo = ultimo_id();
    foreach (filas(
        'SELECT id FROM tokens_api WHERE usuario_id = ? AND organizacion_id = ? AND revocado = 0
          ORDER BY creado_en DESC, id DESC',
        [(int) $usuario['id'], (int) $usuario['organizacion_id']],
    ) as $puesto => $anterior) {
        if ($puesto >= 3 && (int) $anterior['id'] !== $token_nuevo) {
            consultar('UPDATE tokens_api SET revocado = 1 WHERE id = ?', [(int) $anterior['id']]);
        }
    }
    consultar('DELETE FROM tokens_api WHERE expira_en < ?', [hace_dias(90) . ' 00:00:00']);

    registrar_bitacora(
        (int) $usuario['organizacion_id'], (int) $usuario['id'], 'SESION_APP', 'tokens_api', null,
        'Sesión de la app de terreno abierta',
    );

    responder(200, [
        'token'       => $token,
        'expira_en'   => $expira,
        'usuario'     => datos_usuario_api([
            'usuario_id' => $usuario['id'], 'nombre' => $usuario['nombre'], 'apellido' => $usuario['apellido'],
            'rol' => $usuario['rol'], 'organizacion' => $usuario['organizacion'],
        ]),
        'operaciones' => operaciones_del_rol($usuario['rol']),
    ]);
}

function api_cerrar_sesion(): never
{
    exigir_metodo('POST');
    $quien = identidad_api();
    consultar('UPDATE tokens_api SET revocado = 1 WHERE id = ?', [(int) $quien['token_id']]);
    responder(200, ['ok' => true]);
}

function api_catalogo(): never
{
    exigir_metodo('GET');
    $quien = identidad_api();
    $operaciones = operaciones_del_rol($quien['rol']);
    $extra = in_array('VALIDACION', $operaciones, true)
        ? validaciones_app((int) $quien['organizacion_id'], (int) $quien['usuario_id'])
        : [];
    responder(200, catalogo_app((int) $quien['organizacion_id']) + $extra + [
        'usuario'     => datos_usuario_api($quien),
        'operaciones' => $operaciones,
    ]);
}

function api_sincronizar(): never
{
    exigir_metodo('POST');
    $quien = identidad_api();
    $datos = cuerpo_json();

    $operaciones = $datos['operaciones'] ?? null;
    if (!is_array($operaciones) || !array_is_list($operaciones)) {
        responder(400, ['error' => 'Falta la lista de operaciones.']);
    }
    if (count($operaciones) > MAXIMO_OPERACIONES_POR_ENVIO) {
        responder(413, ['error' => 'Se aceptan hasta ' . MAXIMO_OPERACIONES_POR_ENVIO . ' operaciones por envío.']);
    }

    $resultados = [];
    foreach ($operaciones as $operacion) {
        $resultados[] = procesar_operacion(
            (int) $quien['organizacion_id'],
            (int) $quien['usuario_id'],
            (string) $quien['rol'],
            is_array($operacion) ? $operacion : [],
        );
    }

    responder(200, ['resultados' => $resultados, 'servidor_hora' => ahora()]);
}

/**
 * Procesa una operación de la cola del teléfono.
 *
 * Es idempotente: cada operación trae un identificador único generado en el
 * teléfono, y lo que ya se guardó no se vuelve a guardar. Así, si la señal se
 * corta después de guardar pero antes de que llegue la respuesta, el reintento
 * no duplica el pesaje ni el parto.
 *
 * Cada operación va en su propia transacción: una rechazada no frena a las demás.
 * Las rechazadas no quedan registradas, para que puedan reenviarse corregidas.
 */
function procesar_operacion(int $org, int $usuario_id, string $rol, array $operacion): array
{
    $uuid = strtolower(trim(is_string($operacion['uuid'] ?? null) ? $operacion['uuid'] : ''));
    $tipo = strtoupper(trim(is_string($operacion['tipo'] ?? null) ? $operacion['tipo'] : ''));

    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid) !== 1) {
        return ['uuid' => $uuid, 'estado' => 'RECHAZADA', 'mensaje' => 'La operación no trae un identificador válido.'];
    }

    $previa = fila('SELECT entidad_id, mensaje FROM sincronizaciones WHERE organizacion_id = ? AND uuid = ?', [$org, $uuid]);
    if ($previa !== null) {
        return ['uuid' => $uuid, 'estado' => 'DUPLICADA', 'mensaje' => $previa['mensaje'], 'id' => $previa['entidad_id']];
    }

    if (in_array($tipo, OPERACIONES_AVANZADAS, true) && !GESTION_AVANZADA) {
        /* Mismo criterio que la lista de operaciones que se le manda a la app:
           si la sanidad está apagada, un tratamiento encolado antes de apagarla
           no puede entrar por la puerta de atrás y dejar una carencia que ninguna
           pantalla muestra. */
        return ['uuid' => $uuid, 'estado' => 'RECHAZADA', 'mensaje' => 'La sanidad no está habilitada en este sistema.'];
    }
    if (!isset(TIPOS_OPERACION[$tipo])) {
        return ['uuid' => $uuid, 'estado' => 'RECHAZADA', 'mensaje' => 'Tipo de operación desconocido.'];
    }
    // El permiso se revisa operación por operación y con el rol vigente.
    if (!rol_puede($rol, TIPOS_OPERACION[$tipo]['permiso'])) {
        return ['uuid' => $uuid, 'estado' => 'RECHAZADA', 'mensaje' => 'Tu rol no permite registrar esto.'];
    }

    $datos = is_array($operacion['datos'] ?? null) ? $operacion['datos'] : [];
    $registrado = is_string($operacion['registrado_en'] ?? null)
        && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $operacion['registrado_en']) === 1
        ? $operacion['registrado_en']
        : null;

    try {
        $resultado = en_transaccion(static function () use ($org, $usuario_id, $tipo, $uuid, $datos, $registrado): array {
            $resultado = (TIPOS_OPERACION[$tipo]['funcion'])($org, $usuario_id, $datos);
            consultar(
                'INSERT INTO sincronizaciones (organizacion_id, uuid, tipo, estado, mensaje, entidad_id,
                                               usuario_id, registrado_en, recibido_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $org, $uuid, $tipo, 'OK', mb_substr($resultado['mensaje'], 0, 500),
                    $resultado['id'], $usuario_id, $registrado, ahora(),
                ],
            );
            return $resultado;
        });
    } catch (ErrorOperacion $error) {
        return [
            'uuid'    => $uuid,
            'estado'  => 'RECHAZADA',
            'mensaje' => $error->getMessage(),
            'errores' => $error->errores,
            'requiere_confirmacion' => $error->requiere_confirmacion,
        ];
    } catch (Throwable $error) {
        // Dos envíos simultáneos de la misma operación: la clave única frena al segundo.
        if (fila('SELECT id FROM sincronizaciones WHERE organizacion_id = ? AND uuid = ?', [$org, $uuid]) !== null) {
            return ['uuid' => $uuid, 'estado' => 'DUPLICADA', 'mensaje' => 'Ya estaba guardada.'];
        }
        error_log('[ganaderia] sincronización ' . $uuid . ': ' . $error->getMessage());
        return ['uuid' => $uuid, 'estado' => 'ERROR', 'mensaje' => 'Error del servidor al guardar. Se reintentará.'];
    }

    return [
        'uuid'    => $uuid,
        'estado'  => 'OK',
        'mensaje' => $resultado['mensaje'],
        'id'      => $resultado['id'],
        'alerta'  => $resultado['alerta'] ?? null,
    ];
}
