<?php
declare(strict_types=1);

/* Validación MVA desde la app de terreno: la operación VALIDACION de la API. */

$org = 1;
$vet = id_usuario('vet@ganaderia.cl');
$uuid = static fn (int $n): string => sprintf('%08d-0000-4000-8000-000000000000', $n);
$validacion = static fn (int $n, int $tratamiento): array => [
    'uuid' => $uuid($n), 'tipo' => 'VALIDACION', 'datos' => ['tratamiento_id' => $tratamiento],
    'registrado_en' => ahora(),
];

prueba('solo los roles que validan reciben la operación y la lista de pendientes', function () use ($org, $vet): void {
    afirmar(in_array('VALIDACION', operaciones_del_rol('VETERINARIO'), true), 'veterinario');
    afirmar(!in_array('VALIDACION', operaciones_del_rol('OPERARIO'), true), 'operario');
    $catalogo = validaciones_app($org, $vet);
    afirmar_igual('MVA-08-1234', $catalogo['mva']['numero'], 'número MVA');
    afirmar(count($catalogo['por_validar']) > 0, 'hay pendientes');
    afirmar(
        !in_array(true, array_map(static fn (array $t): bool => $t['id'] === (int) valor('SELECT MIN(id) FROM tratamientos WHERE validado_en IS NOT NULL'), $catalogo['por_validar']), true),
        'un tratamiento ya validado no aparece como pendiente',
    );
});

prueba('un operario no puede validar desde la app', function () use ($org, $validacion): void {
    $r = sincronizar_como('operario@ganaderia.cl', $org, $validacion(1, tratamiento_pendiente()));
    afirmar_igual('RECHAZADA', $r['estado'], 'estado');
    afirmar(str_contains($r['mensaje'], 'Tu rol no permite'), $r['mensaje']);
});

prueba('con el registro vencido la validación llega rechazada', function () use ($org, $vet, $validacion): void {
    consultar('UPDATE membresias SET mva_vigente_hasta = ? WHERE organizacion_id = ? AND usuario_id = ?', [hace_dias(1), $org, $vet]);
    $r = sincronizar_como('vet@ganaderia.cl', $org, $validacion(2, tratamiento_pendiente()));
    consultar('UPDATE membresias SET mva_vigente_hasta = ? WHERE organizacion_id = ? AND usuario_id = ?', ['2099-12-31', $org, $vet]);
    afirmar_igual('RECHAZADA', $r['estado'], 'estado');
    afirmar(str_contains($r['mensaje'], 'venció'), $r['mensaje']);
    afirmar_igual(0, (int) valor('SELECT COUNT(*) FROM sincronizaciones WHERE uuid = ?', [$validacion(2, 0)['uuid']]), 'lo rechazado no queda registrado');
});

prueba('vigente: valida, y el mismo envío repetido no se duplica', function () use ($org, $validacion): void {
    $id = tratamiento_pendiente();
    $primera = sincronizar_como('vet@ganaderia.cl', $org, $validacion(3, $id));
    afirmar_igual('OK', $primera['estado'], 'primer envío');
    afirmar(tratamiento($id)['validado_en'] !== null, 'quedó validado');
    $repetida = sincronizar_como('vet@ganaderia.cl', $org, $validacion(3, $id));
    afirmar_igual('DUPLICADA', $repetida['estado'], 'mismo uuid');
    $otra = sincronizar_como('vet@ganaderia.cl', $org, $validacion(4, $id));
    afirmar_igual('RECHAZADA', $otra['estado'], 'otro uuid, mismo tratamiento');
    afirmar(str_contains($otra['mensaje'], 'ya estaba validado'), $otra['mensaje']);
});

prueba('un id de tratamiento de otra organización se rechaza sin datos ajenos', function () use ($vet, $validacion): void {
    consultar(
        'UPDATE membresias SET mva_rut = ?, mva_numero = ?, mva_vigente_hasta = ? WHERE organizacion_id = 2 AND usuario_id = ?',
        ['12345678-5', 'MVA-OTRA', '2099-12-31', $vet],
    );
    $ajeno = tratamiento_pendiente(1);
    $r = sincronizar_como('vet@ganaderia.cl', 2, $validacion(5, $ajeno));
    afirmar_igual('RECHAZADA', $r['estado'], 'estado');
    afirmar(str_contains($r['mensaje'], 'no existe en esta organización'), $r['mensaje']);
    afirmar_igual(null, tratamiento($ajeno)['validado_en'], 'sigue sin validar');
});

prueba('un uuid inválido se rechaza antes de tocar nada', function () use ($org): void {
    $r = sincronizar_como('vet@ganaderia.cl', $org, ['uuid' => 'no-es-uuid', 'tipo' => 'VALIDACION', 'datos' => ['tratamiento_id' => tratamiento_pendiente()]]);
    afirmar_igual('RECHAZADA', $r['estado'], 'estado');
});

prueba('el catálogo informa el total de pendientes aunque la lista se corte', function () use ($org, $vet): void {
    $catalogo = validaciones_app($org, $vet);
    $total = (int) valor('SELECT COUNT(*) FROM tratamientos WHERE organizacion_id = ? AND anulada = 0 AND validado_en IS NULL', [$org]);
    afirmar_igual($total, $catalogo['por_validar_total'], 'total');
    afirmar(count($catalogo['por_validar']) <= $catalogo['por_validar_total'], 'la lista no supera el total');
});
