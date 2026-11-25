<?php
declare(strict_types=1);

/* Validación de tratamientos por Médico Veterinario Autorizado (MVA). */

$org = 1;
$vet = id_usuario('vet@ganaderia.cl');
$admin = id_usuario('admin@ganaderia.cl');
$operario = id_usuario('operario@ganaderia.cl');

prueba('la demo trae dos tratamientos validados con huella válida', function () use ($org): void {
    $validados = filas('SELECT * FROM tratamientos WHERE organizacion_id = ? AND validado_en IS NOT NULL', [$org]);
    afirmar_igual(2, count($validados), 'tratamientos validados');
    foreach ($validados as $t) {
        afirmar_igual('VALIDADO', estado_validacion($org, $t), 'estado del tratamiento ' . $t['id']);
    }
});

prueba('un administrador sin registro MVA no puede validar', function () use ($org, $admin): void {
    afirmar_rechazo(fn () => op_validar_tratamiento($org, $admin, ['tratamiento_id' => tratamiento_pendiente()]), 'registro MVA vigente');
});

prueba('un operario sin registro MVA no puede validar', function () use ($org, $operario): void {
    afirmar_rechazo(fn () => op_validar_tratamiento($org, $operario, ['tratamiento_id' => tratamiento_pendiente()]), 'registro MVA vigente');
});

prueba('el MVA vigente valida: copia su RUT y número y deja la huella', function () use ($org, $vet): void {
    $id = tratamiento_pendiente();
    op_validar_tratamiento($org, $vet, ['tratamiento_id' => $id]);
    $t = tratamiento($id);
    afirmar_igual($vet, (int) $t['validado_por'], 'validado_por');
    afirmar_igual('12345678-5', $t['validado_mva_rut'], 'RUT copiado');
    afirmar_igual('MVA-08-1234', $t['validado_mva_numero'], 'número copiado');
    afirmar_igual(64, strlen((string) $t['huella']), 'largo de la huella');
    afirmar_igual('VALIDADO', estado_validacion($org, $t), 'estado');
    afirmar_igual(1, (int) valor("SELECT COUNT(*) FROM bitacora WHERE accion = 'VALIDA_TRATAMIENTO' AND entidad_id = ?", [$id]), 'bitácora');
});

prueba('un tratamiento ya validado no se valida dos veces', function () use ($org, $vet): void {
    $id = (int) valor('SELECT MAX(id) FROM tratamientos WHERE organizacion_id = ? AND validado_en IS NOT NULL', [$org]);
    afirmar_rechazo(fn () => op_validar_tratamiento($org, $vet, ['tratamiento_id' => $id]), 'ya estaba validado');
});

prueba('cambiar la dosis de un validado lo marca como alterado', function () use ($org): void {
    $id = (int) valor('SELECT MIN(id) FROM tratamientos WHERE organizacion_id = ? AND validado_en IS NOT NULL', [$org]);
    consultar('UPDATE tratamientos SET dosis_por_animal = dosis_por_animal + 1 WHERE id = ?', [$id]);
    afirmar_igual('ALTERADO', estado_validacion($org, tratamiento($id)), 'con la dosis cambiada');
    consultar('UPDATE tratamientos SET dosis_por_animal = dosis_por_animal - 1 WHERE id = ?', [$id]);
    afirmar_igual('VALIDADO', estado_validacion($org, tratamiento($id)), 'con la dosis devuelta');
});

prueba('quitar un animal de un validado lo marca como alterado', function () use ($org): void {
    $id = (int) valor('SELECT MIN(id) FROM tratamientos WHERE organizacion_id = ? AND validado_en IS NOT NULL', [$org]);
    $aplicacion = fila('SELECT * FROM aplicaciones_tratamiento WHERE tratamiento_id = ? LIMIT 1', [$id]);
    consultar('DELETE FROM aplicaciones_tratamiento WHERE id = ?', [$aplicacion['id']]);
    afirmar_igual('ALTERADO', estado_validacion($org, tratamiento($id)), 'sin el animal');
    consultar(
        'INSERT INTO aplicaciones_tratamiento (id, organizacion_id, tratamiento_id, animal_id) VALUES (?, ?, ?, ?)',
        [$aplicacion['id'], $org, $id, $aplicacion['animal_id']],
    );
    afirmar_igual('VALIDADO', estado_validacion($org, tratamiento($id)), 'con el animal devuelto');
});

prueba('sin registro MVA no se anula un tratamiento validado', function () use ($org, $admin): void {
    $id = (int) valor('SELECT MIN(id) FROM tratamientos WHERE organizacion_id = ? AND validado_en IS NOT NULL', [$org]);
    afirmar_rechazo(
        fn () => op_anular($org, $admin, ['tipo' => 'TRATAMIENTO', 'registro_id' => $id, 'motivo' => 'prueba']),
        'solo un MVA',
    );
    afirmar_igual(0, (int) tratamiento($id)['anulada'], 'sigue sin anular');
});

prueba('un tratamiento pendiente lo anula cualquiera con permiso, y anulado ya no se valida', function () use ($org, $admin, $vet): void {
    $id = tratamiento_pendiente();
    op_anular($org, $admin, ['tipo' => 'TRATAMIENTO', 'registro_id' => $id, 'motivo' => 'cargado dos veces']);
    afirmar_igual(1, (int) tratamiento($id)['anulada'], 'anulada');
    afirmar_rechazo(fn () => op_validar_tratamiento($org, $vet, ['tratamiento_id' => $id]), 'anulado');
});

prueba('el MVA vigente sí anula un tratamiento validado', function () use ($org, $vet): void {
    $id = (int) valor('SELECT MIN(id) FROM tratamientos WHERE organizacion_id = ? AND validado_en IS NOT NULL AND anulada = 0', [$org]);
    op_anular($org, $vet, ['tipo' => 'TRATAMIENTO', 'registro_id' => $id, 'motivo' => 'error de carga']);
    afirmar_igual(1, (int) tratamiento($id)['anulada'], 'anulada');
});

prueba('con el registro MVA vencido no se valida', function () use ($org, $vet): void {
    consultar('UPDATE membresias SET mva_vigente_hasta = ? WHERE organizacion_id = ? AND usuario_id = ?', [hace_dias(1), $org, $vet]);
    afirmar_rechazo(fn () => op_validar_tratamiento($org, $vet, ['tratamiento_id' => tratamiento_pendiente()]), 'venció');
    consultar('UPDATE membresias SET mva_vigente_hasta = ? WHERE organizacion_id = ? AND usuario_id = ?', ['2099-12-31', $org, $vet]);
});

prueba('el registro MVA vale solo en la organización donde se cargó', function () use ($vet): void {
    // El veterinario también trabaja en la organización 2, donde no tiene registro.
    afirmar_rechazo(fn () => op_validar_tratamiento(2, $vet, ['tratamiento_id' => tratamiento_pendiente(1)]), 'registro MVA vigente');
});

prueba('con MVA en otra organización, un tratamiento ajeno no aparece', function () use ($vet): void {
    consultar(
        'UPDATE membresias SET mva_rut = ?, mva_numero = ?, mva_vigente_hasta = ? WHERE organizacion_id = 2 AND usuario_id = ?',
        ['12345678-5', 'MVA-OTRA', '2099-12-31', $vet],
    );
    $id = tratamiento_pendiente(1);
    afirmar_rechazo(fn () => op_validar_tratamiento(2, $vet, ['tratamiento_id' => $id]), 'no existe en esta organización');
    afirmar_igual(null, tratamiento($id)['validado_en'], 'el tratamiento ajeno sigue sin validar');
});

prueba('el RUT se valida con su dígito verificador', function (): void {
    afirmar_igual('12345678-5', normalizar_rut('12.345.678-5'), 'RUT válido');
    afirmar_igual('12345678-5', normalizar_rut('123456785'), 'RUT sin puntos ni guion');
    afirmar_igual(null, normalizar_rut('12.345.678-9'), 'dígito verificador incorrecto');
    afirmar_igual(null, normalizar_rut('123'), 'muy corto');
    afirmar_igual('12.345.678-5', rut_legible('12345678-5'), 'RUT para mostrar');
});

prueba('sin fecha de vigencia el registro no se da por vigente', function (): void {
    afirmar(!mva_vigente(['mva_rut' => '12345678-5', 'mva_numero' => 'X', 'mva_vigente_hasta' => null], hoy()));
    afirmar(mva_vigente(['mva_rut' => '12345678-5', 'mva_numero' => 'X', 'mva_vigente_hasta' => hoy()], hoy()), 'vence hoy: todavía vale');
});

prueba('una huella recalculada sin la clave del servidor se detecta como alterada', function () use ($org, $vet): void {
    $id = tratamiento_pendiente();
    op_validar_tratamiento($org, $vet, ['tratamiento_id' => $id]);
    // Quien solo tiene acceso a la base cambia la dosis y recalcula la huella
    // con un SHA-256 común: no conoce la clave del servidor.
    consultar('UPDATE tratamientos SET dosis_por_animal = 1 WHERE id = ?', [$id]);
    $alterado = tratamiento($id);
    $sin_clave = hash('sha256', contenido_huella($alterado, animales_del_tratamiento($org, $id)));
    consultar('UPDATE tratamientos SET huella = ? WHERE id = ?', [$sin_clave, $id]);
    afirmar_igual('ALTERADO', estado_validacion($org, tratamiento($id)), 'estado');
});

prueba('un diagnóstico con bytes inválidos no rompe la huella', function () use ($org): void {
    $t = tratamiento((int) valor('SELECT MIN(id) FROM tratamientos WHERE organizacion_id = ?', [$org]));
    $t['diagnostico'] = "neumon\xC3(a";
    afirmar_igual(64, strlen(huella_tratamiento($t, [1, 2], 'clave')), 'largo de la huella');
});
