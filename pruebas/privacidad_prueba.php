<?php
declare(strict_types=1);

/* Ley 21.719: política, derechos del titular, supresión y plazos de conservación. */

require_once __DIR__ . '/../src/privacidad.php';

$org = 1;
$admin = id_usuario('admin@ganaderia.cl');
$operario = id_usuario('operario@ganaderia.cl');

prueba('la política nombra al responsable real y el plazo de respuesta', function () use ($org): void {
    $texto = json_encode(politica_privacidad($org), JSON_UNESCAPED_UNICODE);
    $organizacion = (string) valor('SELECT nombre FROM organizaciones WHERE id = ?', [$org]);
    afirmar(str_contains($texto, $organizacion), 'no nombra a la organización');
    afirmar(str_contains($texto, (int) config_privacidad()['dias_respuesta'] . ' días corridos'), 'no dice el plazo');
    afirmar(str_contains($texto, 'Agencia de Protección de Datos Personales'), 'no menciona la Agencia');
});

prueba('la política queda pendiente hasta recibirla, una vez por versión', function () use ($operario): void {
    afirmar(privacidad_pendiente($operario), 'debería estar pendiente');
    aceptar_privacidad($operario);
    aceptar_privacidad($operario); // repetir no duplica
    afirmar(!privacidad_pendiente($operario), 'sigue pendiente');
    afirmar_igual(1, (int) valor('SELECT COUNT(*) FROM aceptaciones_privacidad WHERE usuario_id = ?', [$operario]), 'constancias');
});

prueba('acceso y portabilidad: los datos propios, sin contraseña', function () use ($operario): void {
    $datos = datos_personales_de($operario);
    afirmar_igual('operario@ganaderia.cl', $datos['persona']['email'], 'correo');
    afirmar(!str_contains(json_encode($datos), 'password'), 'incluye la contraseña');
    afirmar($datos['organizaciones'] !== [], 'sin organizaciones');
});

prueba('una solicitud queda con plazo y no se duplica mientras esté pendiente', function () use ($org, $operario): void {
    $r = op_solicitud_datos($org, $operario, ['tipo' => 'ACCESO']);
    $s = fila('SELECT * FROM solicitudes_datos WHERE id = ?', [$r['id']]);
    $esperado = (new DateTimeImmutable(hoy()))->modify('+' . (int) config_privacidad()['dias_respuesta'] . ' days')->format('Y-m-d');
    afirmar_igual($esperado, $s['vence_el'], 'plazo');
    afirmar_rechazo(fn () => op_solicitud_datos($org, $operario, ['tipo' => 'ACCESO']), 'pendiente');
    afirmar_rechazo(fn () => op_solicitud_datos($org, $operario, ['tipo' => 'RECTIFICACION']), 'qué dato');
    afirmar_igual(1, solicitudes_pendientes($org), 'pendientes');
});

prueba('responder exige un texto y queda en la bitácora', function () use ($org, $admin): void {
    $id = (int) valor("SELECT id FROM solicitudes_datos WHERE tipo = 'ACCESO' LIMIT 1");
    afirmar_rechazo(fn () => op_responder_solicitud($org, $admin, ['solicitud_id' => $id, 'estado' => 'RESPONDIDA', 'respuesta' => '']), 'respuesta');
    op_responder_solicitud($org, $admin, ['solicitud_id' => $id, 'estado' => 'RESPONDIDA', 'respuesta' => 'Tus datos están en «Mis datos».']);
    afirmar_igual('RESPONDIDA', valor('SELECT estado FROM solicitudes_datos WHERE id = ?', [$id]), 'estado');
    afirmar((int) valor("SELECT COUNT(*) FROM bitacora WHERE accion = 'RESPONDE_SOLICITUD_DATOS'") === 1, 'bitácora');
});

prueba('una supresión aceptada anonimiza y conserva los registros sanitarios', function () use ($org, $admin): void {
    $vet = id_usuario('vet@ganaderia.cl');
    $validados = (int) valor('SELECT COUNT(*) FROM tratamientos WHERE validado_por = ?', [$vet]);
    $r = op_solicitud_datos($org, $vet, ['tipo' => 'SUPRESION', 'detalle' => 'Ya no trabajo acá']);
    op_responder_solicitud($org, $admin, ['solicitud_id' => $r['id'], 'estado' => 'RESPONDIDA', 'respuesta' => 'Listo', 'anonimizar' => '1']);
    $u = fila('SELECT * FROM usuarios WHERE id = ?', [$vet]);
    $otras = (int) valor('SELECT COUNT(*) FROM membresias WHERE usuario_id = ? AND organizacion_id <> ? AND activa = 1', [$vet, $org]);
    if ($otras === 0) {
        afirmar(!str_contains((string) $u['email'], 'vet@'), 'el correo sigue');
        afirmar_igual(0, (int) $u['activo'], 'activo');
        afirmar(password_verify('demo1234', (string) $u['password_hash']) === false, 'la clave sigue sirviendo');
    }
    afirmar_igual(0, (int) valor('SELECT activa FROM membresias WHERE usuario_id = ? AND organizacion_id = ?', [$vet, $org]), 'membresía');
    afirmar_igual($validados, (int) valor('SELECT COUNT(*) FROM tratamientos WHERE validado_por = ?', [$vet]), 'tratamientos conservados');
});

prueba('un administrador no puede anonimizarse a sí mismo', function () use ($org, $admin): void {
    $r = op_solicitud_datos($org, $admin, ['tipo' => 'SUPRESION']);
    afirmar_rechazo(fn () => op_responder_solicitud($org, $admin, ['solicitud_id' => $r['id'], 'estado' => 'RESPONDIDA', 'respuesta' => 'Listo', 'anonimizar' => '1']), 'propia cuenta');
});

prueba('las IP de la bitácora se borran pasado el plazo', function () use ($org, $admin): void {
    $vieja = (new DateTimeImmutable())->modify('-' . ((int) config_privacidad()['dias_ip_bitacora'] + 5) . ' days')->format('Y-m-d H:i:s');
    consultar("INSERT INTO bitacora (organizacion_id, usuario_id, fecha_hora, accion, origen) VALUES (?, ?, ?, 'PRUEBA', '200.1.2.3'), (?, ?, ?, 'PRUEBA', '200.1.2.4')",
        [$org, $admin, $vieja, $org, $admin, ahora()]);
    $r = aplicar_retencion(true);
    afirmar($r['ip_borradas'] >= 1, 'no borró');
    afirmar(valor("SELECT origen FROM bitacora WHERE origen = '200.1.2.3'") === null, 'la IP vieja sigue');
    afirmar(valor("SELECT origen FROM bitacora WHERE origen = '200.1.2.4'") !== null, 'borró una IP reciente');
    afirmar_igual(false, aplicar_retencion()['corrio'], 'corre una sola vez por día');
});
