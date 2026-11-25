<?php
declare(strict_types=1);

/* Registro MVA cargado por un administrador (op_guardar_mva). */

$org = 1;
$admin = id_usuario('admin@ganaderia.cl');
$vet = id_usuario('vet@ganaderia.cl');
$operario = id_usuario('operario@ganaderia.cl');
$registro = static fn (int $usuario, array $cambios = []): array => $cambios + [
    'usuario_id' => $usuario, 'mva_rut' => '12.345.678-5', 'mva_numero' => 'MVA-99', 'mva_vigente_hasta' => '2030-06-30',
];

prueba('el administrador carga el registro MVA de otra persona, normalizado', function () use ($org, $admin, $operario, $registro): void {
    op_guardar_mva($org, $admin, $registro($operario));
    $m = membresia_mva($org, $operario);
    afirmar_igual('12345678-5', $m['mva_rut'], 'RUT');
    afirmar_igual('MVA-99', $m['mva_numero'], 'número');
    afirmar_igual('2030-06-30', substr((string) $m['mva_vigente_hasta'], 0, 10), 'vigencia');
    afirmar_igual(1, (int) valor("SELECT COUNT(*) FROM bitacora WHERE accion = 'ACTUALIZA_MVA' AND entidad_id = ?", [$operario]), 'bitácora');
});

prueba('nadie se carga su propio registro MVA', function () use ($org, $admin, $registro): void {
    afirmar_rechazo(fn () => op_guardar_mva($org, $admin, $registro($admin)), 'No podés cargar tu propio registro');
});

prueba('los errores vuelven por campo', function () use ($org, $admin, $vet, $registro): void {
    $errores = error_de(fn () => op_guardar_mva($org, $admin, $registro($vet, ['mva_rut' => '12.345.678-9', 'mva_numero' => '', 'mva_vigente_hasta' => '2030-02-31'])));
    afirmar(isset($errores['mva_rut'], $errores['mva_numero'], $errores['mva_vigente_hasta']), 'errores: ' . json_encode($errores));
});

prueba('no se toca el registro de alguien de otra organización', function () use ($admin, $registro): void {
    // La persona existe, pero no trabaja en la organización 2 con este rol de administrador... usamos un id inexistente en la org.
    $ajeno = id_usuario('tractor@ganaderia.cl');
    afirmar_rechazo(fn () => op_guardar_mva(2, $admin, $registro($ajeno)), 'no trabaja en esta organización');
});

prueba('los tres campos vacíos quitan el registro, y si no había se avisa', function () use ($org, $admin, $vet): void {
    $vacio = ['usuario_id' => $vet, 'mva_rut' => '', 'mva_numero' => '', 'mva_vigente_hasta' => ''];
    op_guardar_mva($org, $admin, $vacio);
    afirmar_igual(null, membresia_mva($org, $vet)['mva_numero'], 'quitado');
    afirmar_rechazo(fn () => op_guardar_mva($org, $admin, $vacio), 'no tenía registro MVA');
});
