<?php
declare(strict_types=1);

require_once __DIR__ . '/operaciones.php';

/**
 * Ley 21.719 de protección de datos personales (vigente desde el 1/12/2026).
 *
 * Lo que el sistema resuelve por sí mismo:
 *  · Deber de información (art. 14 ter): política de privacidad pública en
 *    /privacidad, armada con los datos reales del responsable, y constancia de
 *    que cada persona la recibió (tabla aceptaciones_privacidad, por versión).
 *  · Derechos del titular: acceso y portabilidad (descarga de los propios datos
 *    en JSON), y solicitudes de rectificación, supresión, oposición y bloqueo,
 *    con su plazo de respuesta y el registro de cómo se respondió.
 *  · Supresión: se anonimiza a la persona. Los registros sanitarios y de
 *    movimiento que exige el SAG se conservan, pero quedan sin su nombre.
 *  · Minimización y conservación: las IP de la bitácora se borran pasado el
 *    plazo configurado; los intentos de ingreso duran un día; los avisos de
 *    error salen con los datos enmascarados (src/alertas.php).
 *
 * Lo que no resuelve el software y queda en docs/ley-21719.md: el registro de
 * actividades de tratamiento, el procedimiento ante una brecha y, si el sistema
 * se ofrece a terceros, el contrato de encargo de tratamiento.
 *
 * Esto es orientación técnica, no asesoría legal.
 */

/** Versión de la política: al cambiar el texto, subirla para que todos la vuelvan a leer. */
const VERSION_POLITICA = '2026-12';

const TIPOS_SOLICITUD = [
    'ACCESO'        => 'Acceso: saber qué datos míos tienen',
    'RECTIFICACION' => 'Rectificación: corregir un dato mío',
    'SUPRESION'     => 'Supresión: que borren mis datos',
    'OPOSICION'     => 'Oposición: que dejen de usar mis datos para un fin',
    'PORTABILIDAD'  => 'Portabilidad: recibir mis datos en un archivo',
    'BLOQUEO'       => 'Bloqueo: suspender el uso de mis datos mientras se revisa',
];

const ESTADOS_SOLICITUD = [
    'PENDIENTE' => 'Pendiente',
    'RESPONDIDA' => 'Respondida',
    'RECHAZADA' => 'Rechazada',
];

/** Configuración de privacidad (config.php → 'privacidad') con valores por defecto. */
function config_privacidad(): array
{
    return array_merge([
        // A quién se escribe para ejercer derechos. Vacío: se muestra el del administrador.
        'correo_contacto' => '',
        // Días corridos para responder una solicitud. Revisá el plazo vigente con tu asesor.
        'dias_respuesta' => 30,
        // Días que se guardan las IP de la bitácora.
        'dias_ip_bitacora' => 180,
    ], CONFIG_PRIVACIDAD);
}

/** Correo de contacto para derechos: el configurado o el del primer administrador. */
function correo_privacidad(int $org): string
{
    $configurado = trim((string) config_privacidad()['correo_contacto']);
    if ($configurado !== '') {
        return $configurado;
    }
    return (string) (valor(
        "SELECT u.email FROM membresias m JOIN usuarios u ON u.id = m.usuario_id
          WHERE m.organizacion_id = ? AND m.rol = 'ADMINISTRADOR' AND m.activa = 1 AND u.activo = 1 ORDER BY u.id LIMIT 1",
        [$org],
    ) ?? '');
}

/**
 * Texto de la política, por secciones, con los datos de la organización.
 *
 * @return list<array{titulo: string, parrafos: list<string>}>
 */
function politica_privacidad(?int $org): array
{
    $c = config_privacidad();
    $organizacion = $org !== null ? fila('SELECT nombre, rut FROM organizaciones WHERE id = ?', [$org]) : null;
    $responsable = $organizacion !== null
        ? $organizacion['nombre'] . ($organizacion['rut'] ? ', RUT ' . $organizacion['rut'] : '')
        : 'la organización titular de cada cuenta (el productor o empresa que da acceso al sistema)';
    $contacto = $org !== null ? correo_privacidad($org) : '';

    return [
        ['titulo' => '1. Quién es responsable de tus datos', 'parrafos' => [
            'El responsable del tratamiento es ' . $responsable . '. Es quien decide para qué se usan los datos que se cargan en este sistema de gestión ganadera.',
            $contacto !== ''
                ? 'Para cualquier consulta o para ejercer tus derechos, escribí a ' . $contacto . ' o usá la sección «Mis datos» dentro del sistema.'
                : 'Para cualquier consulta o para ejercer tus derechos, usá la sección «Mis datos» dentro del sistema o escribí al administrador de tu organización.',
        ]],
        ['titulo' => '2. Qué datos personales se tratan', 'parrafos' => [
            'De las personas que usan el sistema: nombre, apellido, correo, teléfono (si se cargó), rol, predios a los que tiene acceso, fecha del último ingreso y los dispositivos donde se instaló la app de terreno.',
            'De los médicos veterinarios autorizados (MVA): RUT, número de registro y vigencia, y la constancia de cada tratamiento que validaron.',
            'Registro de actividad (bitácora): qué cambio se hizo, quién, cuándo y desde qué dirección IP.',
            'De terceros que aparecen en un movimiento de animales: nombre del comprador, nombre y RUT del transportista y patente del camión.',
            'El sistema no está pensado para guardar datos sensibles (salud de personas, origen, creencias, etc.). Las notas de los animales no deben contener datos de personas.',
        ]],
        ['titulo' => '3. Para qué se usan y con qué base legal', 'parrafos' => [
            'Para darte acceso al sistema según tu rol y proteger la cuenta (base: la relación laboral o contractual con el responsable).',
            'Para la trazabilidad productiva y sanitaria del ganado y para cumplir obligaciones ante el SAG: formularios de movimiento animal (FMA), registro en SIPEC, períodos de resguardo de medicamentos y validación por MVA (base: cumplimiento de obligaciones legales).',
            'Para la seguridad del sistema y la auditoría de cambios (base: interés legítimo del responsable en proteger la información y detectar abusos).',
            'Los datos no se usan para publicidad ni para tomar decisiones automatizadas sobre personas.',
        ]],
        ['titulo' => '4. Con quién se comparten', 'parrafos' => [
            'No se venden ni se ceden. Los datos de un movimiento llegan al SAG solo cuando el responsable presenta el FMA o la planilla de SIPEC.',
            'El proveedor del servidor (hosting) los almacena por encargo del responsable. Si se configuran avisos de errores (correo, Telegram o webhook), esos avisos salen con los correos, RUT y números largos enmascarados.',
        ]],
        ['titulo' => '5. Cuánto tiempo se guardan', 'parrafos' => [
            'Tu cuenta, mientras tengas relación con el responsable. Al terminar, se desactiva; si pedís la supresión, se anonimiza.',
            'Los registros sanitarios, de tratamientos y de movimientos de animales se conservan el tiempo que exige la normativa sanitaria, porque son la trazabilidad del ganado. Si se suprime tu cuenta, esos registros quedan sin tu nombre.',
            'Las direcciones IP de la bitácora se borran a los ' . (int) $c['dias_ip_bitacora'] . ' días. Los intentos de ingreso fallidos, al día siguiente.',
        ]],
        ['titulo' => '6. Tus derechos', 'parrafos' => [
            'Podés pedir acceso a tus datos, rectificarlos, suprimirlos, oponerte a un tratamiento, recibirlos en un archivo (portabilidad) y pedir su bloqueo mientras se revisa un reclamo.',
            'Dentro del sistema, en «Mis datos», podés descargar tus datos y hacer cualquier solicitud. El responsable debe responder dentro de ' . (int) $c['dias_respuesta'] . ' días corridos.',
            'Si no recibís respuesta o no estás de acuerdo, podés reclamar ante la Agencia de Protección de Datos Personales.',
        ]],
        ['titulo' => '7. Cómo se protegen', 'parrafos' => [
            'Contraseñas cifradas, conexión cifrada (HTTPS), permisos según el rol, cierre de sesión por inactividad, bloqueo ante intentos repetidos de ingreso, bitácora de cambios y avisos automáticos de errores del servidor.',
            'Si ocurre una vulneración de seguridad que ponga en riesgo tus datos, el responsable la informará a la Agencia de Protección de Datos Personales sin dilaciones indebidas y, cuando corresponda, a las personas afectadas.',
        ]],
        ['titulo' => '8. Cambios a esta política', 'parrafos' => [
            'Versión ' . VERSION_POLITICA . '. Si cambia, el sistema te la vuelve a mostrar al ingresar.',
        ]],
    ];
}

/** ¿La persona todavía no recibió la versión vigente de la política? */
function privacidad_pendiente(int $usuario_id): bool
{
    return valor('SELECT 1 FROM aceptaciones_privacidad WHERE usuario_id = ? AND version = ?', [$usuario_id, VERSION_POLITICA]) === null;
}

function aceptar_privacidad(int $usuario_id): void
{
    if (privacidad_pendiente($usuario_id)) {
        consultar(
            'INSERT INTO aceptaciones_privacidad (usuario_id, version, aceptada_en) VALUES (?, ?, ?)',
            [$usuario_id, VERSION_POLITICA, ahora()],
        );
    }
}

/**
 * Todos los datos personales de una persona: lo que se entrega en el acceso y
 * en la portabilidad. Sin la contraseña ni los tokens.
 */
function datos_personales_de(int $usuario_id): array
{
    $usuario = fila('SELECT id, email, nombre, apellido, telefono, activo, ultimo_acceso_en, creado_en FROM usuarios WHERE id = ?', [$usuario_id]);
    return [
        'generado_el' => ahora(),
        'politica_version' => VERSION_POLITICA,
        'persona' => $usuario,
        'organizaciones' => filas(
            'SELECT o.nombre AS organizacion, m.rol, m.activa, m.mva_rut, m.mva_numero, m.mva_vigente_hasta
               FROM membresias m JOIN organizaciones o ON o.id = m.organizacion_id WHERE m.usuario_id = ? ORDER BY o.nombre',
            [$usuario_id],
        ),
        'dispositivos_app' => filas(
            'SELECT dispositivo, creado_en, expira_en, ultimo_uso_en, revocado FROM tokens_api WHERE usuario_id = ? ORDER BY creado_en DESC',
            [$usuario_id],
        ),
        'actividad' => filas(
            'SELECT fecha_hora, accion, entidad, origen AS ip FROM bitacora WHERE usuario_id = ? ORDER BY fecha_hora DESC LIMIT 500',
            [$usuario_id],
        ),
        'tratamientos_validados_como_mva' => (int) valor('SELECT COUNT(*) FROM tratamientos WHERE validado_por = ?', [$usuario_id]),
        'politica_recibida' => filas('SELECT version, aceptada_en FROM aceptaciones_privacidad WHERE usuario_id = ? ORDER BY aceptada_en', [$usuario_id]),
        'solicitudes' => filas('SELECT tipo, detalle, estado, creada_en, vence_el, respuesta, respondida_en FROM solicitudes_datos WHERE usuario_id = ? ORDER BY creada_en DESC', [$usuario_id]),
    ];
}

/** La persona pide ejercer un derecho. */
function op_solicitud_datos(int $org, int $usuario_id, array $d): array
{
    $tipo = strtoupper(dato_texto($d, 'tipo'));
    $detalle = dato_texto($d, 'detalle');
    $errores = [];
    if (!isset(TIPOS_SOLICITUD[$tipo])) {
        $errores['tipo'] = 'Elegí qué querés pedir.';
    }
    if (in_array($tipo, ['RECTIFICACION', 'OPOSICION'], true) && mb_strlen($detalle) < 5) {
        $errores['detalle'] = $tipo === 'RECTIFICACION' ? 'Contá qué dato hay que corregir y cuál es el correcto.' : 'Contá a qué uso de tus datos te oponés.';
    }
    $abierta = valor("SELECT id FROM solicitudes_datos WHERE organizacion_id = ? AND usuario_id = ? AND tipo = ? AND estado = 'PENDIENTE'", [$org, $usuario_id, $tipo]);
    if ($abierta !== null && !isset($errores['tipo'])) {
        $errores['tipo'] = 'Ya tenés una solicitud de este tipo pendiente.';
    }
    if ($errores !== []) {
        throw new ErrorOperacion($errores);
    }

    $vence = (new DateTimeImmutable(hoy()))->modify('+' . (int) config_privacidad()['dias_respuesta'] . ' days')->format('Y-m-d');
    consultar(
        'INSERT INTO solicitudes_datos (organizacion_id, usuario_id, tipo, detalle, creada_en, vence_el) VALUES (?, ?, ?, ?, ?, ?)',
        [$org, $usuario_id, $tipo, $detalle !== '' ? mb_substr($detalle, 0, 1000) : null, ahora(), $vence],
    );
    $id = ultimo_id();
    registrar_bitacora($org, $usuario_id, 'SOLICITUD_DATOS', 'solicitudes_datos', $id, $tipo);
    return ['id' => $id, 'mensaje' => 'Solicitud registrada. El responsable tiene hasta el ' . fecha_corta($vence) . ' para responderte.'];
}

/**
 * El administrador responde una solicitud. Con supresión aceptada, anonimiza.
 */
function op_responder_solicitud(int $org, int $admin_id, array $d): array
{
    $solicitud = fila("SELECT * FROM solicitudes_datos WHERE organizacion_id = ? AND id = ? AND estado = 'PENDIENTE'", [$org, dato_entero($d, 'solicitud_id') ?? 0]);
    $estado = strtoupper(dato_texto($d, 'estado'));
    $respuesta = dato_texto($d, 'respuesta');
    $errores = [];
    if ($solicitud === null) {
        $errores['solicitud_id'] = 'Esa solicitud no existe o ya fue respondida.';
    }
    if (!in_array($estado, ['RESPONDIDA', 'RECHAZADA'], true)) {
        $errores['estado'] = 'Indicá si se responde o se rechaza.';
    }
    if (mb_strlen($respuesta) < 5) {
        $errores['respuesta'] = 'Escribí la respuesta que va a ver la persona (y el motivo si se rechaza).';
    }
    $anonimizar = $solicitud !== null && $solicitud['tipo'] === 'SUPRESION' && $estado === 'RESPONDIDA' && dato_si($d, 'anonimizar');
    if ($anonimizar && (int) $solicitud['usuario_id'] === $admin_id) {
        $errores['anonimizar'] = 'No podés anonimizar tu propia cuenta: pedíselo a otro administrador.';
    }
    if ($errores !== []) {
        throw new ErrorOperacion($errores);
    }

    en_transaccion(static function () use ($org, $admin_id, $solicitud, $estado, $respuesta, $anonimizar): void {
        consultar(
            'UPDATE solicitudes_datos SET estado = ?, respuesta = ?, respondida_por = ?, respondida_en = ? WHERE id = ? AND organizacion_id = ?',
            [$estado, mb_substr($respuesta, 0, 1000), $admin_id, ahora(), (int) $solicitud['id'], $org],
        );
        if ($anonimizar) {
            anonimizar_usuario($org, $admin_id, (int) $solicitud['usuario_id']);
        }
        registrar_bitacora($org, $admin_id, 'RESPONDE_SOLICITUD_DATOS', 'solicitudes_datos', (int) $solicitud['id'], $solicitud['tipo'] . ' → ' . $estado . ($anonimizar ? ' (anonimizada)' : ''));
    });

    return ['id' => (int) $solicitud['id'], 'mensaje' => 'Solicitud ' . mb_strtolower(ESTADOS_SOLICITUD[$estado]) . ($anonimizar ? '. La persona quedó anonimizada.' : '.')];
}

/**
 * Supresión: la persona deja de ser identificable.
 *
 * Si también trabaja con otra organización, solo se la saca de esta (sus
 * datos los sigue tratando la otra, que es otro responsable). Si no, se
 * anonimiza la cuenta: nombre, correo y teléfono se reemplazan, la clave se
 * invalida y las sesiones de la app se revocan. Los registros que la normativa
 * sanitaria obliga a guardar (tratamientos, validaciones MVA, movimientos)
 * quedan, apuntando a una persona sin nombre.
 */
function anonimizar_usuario(int $org, int $admin_id, int $usuario_id): void
{
    consultar(
        'UPDATE membresias SET activa = 0, mva_rut = NULL, mva_numero = NULL, mva_vigente_hasta = NULL WHERE organizacion_id = ? AND usuario_id = ?',
        [$org, $usuario_id],
    );
    consultar('UPDATE tokens_api SET revocado = 1 WHERE organizacion_id = ? AND usuario_id = ?', [$org, $usuario_id]);

    $otras = (int) valor('SELECT COUNT(*) FROM membresias WHERE usuario_id = ? AND organizacion_id <> ? AND activa = 1', [$usuario_id, $org]);
    if ($otras === 0) {
        consultar(
            "UPDATE usuarios SET nombre = 'Persona', apellido = ?, email = ?, telefono = NULL, password_hash = ?, activo = 0 WHERE id = ?",
            ['suprimida #' . $usuario_id, 'suprimido-' . $usuario_id . '@anonimo.invalid', password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $usuario_id],
        );
        consultar('UPDATE tokens_api SET revocado = 1 WHERE usuario_id = ?', [$usuario_id]);
        consultar('UPDATE bitacora SET origen = NULL WHERE usuario_id = ?', [$usuario_id]);
    }
}

/**
 * Plazos de conservación: borra lo que ya no hace falta guardar. Corre una vez
 * por día, con la primera visita del día (y se puede correr por consola).
 */
function aplicar_retencion(bool $forzar = false): array
{
    $marca = CARPETA_DATOS . '/retencion.txt';
    if (!$forzar && is_file($marca) && trim((string) file_get_contents($marca)) === hoy()) {
        return ['ip_borradas' => 0, 'tokens_borrados' => 0, 'corrio' => false];
    }
    $limite_ip = (new DateTimeImmutable())->modify('-' . (int) config_privacidad()['dias_ip_bitacora'] . ' days')->format('Y-m-d H:i:s');
    $ip = consultar('UPDATE bitacora SET origen = NULL WHERE origen IS NOT NULL AND fecha_hora < ?', [$limite_ip])->rowCount();
    // Sesiones de la app vencidas hace más de 90 días: ya no sirven ni como historial.
    $tokens = consultar('DELETE FROM tokens_api WHERE expira_en < ?', [(new DateTimeImmutable())->modify('-90 days')->format('Y-m-d H:i:s')])->rowCount();
    consultar('DELETE FROM intentos_ingreso WHERE momento < ?', [(new DateTimeImmutable())->modify('-1 day')->format('Y-m-d H:i:s')]);
    if (!is_dir(CARPETA_DATOS)) {
        @mkdir(CARPETA_DATOS, 0755, true);
    }
    @file_put_contents($marca, hoy());
    return ['ip_borradas' => $ip, 'tokens_borrados' => $tokens, 'corrio' => true];
}

/** Solicitudes pendientes de la organización, para el aviso del administrador. */
function solicitudes_pendientes(int $org): int
{
    return (int) valor("SELECT COUNT(*) FROM solicitudes_datos WHERE organizacion_id = ? AND estado = 'PENDIENTE'", [$org]);
}
