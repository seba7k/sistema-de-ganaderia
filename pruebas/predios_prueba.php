<?php
declare(strict_types=1);

/* Alta y edición de predios: RUP, duplicados y organización. */

$org = 1;
$admin = id_usuario('admin@ganaderia.cl');
$sauce = (int) valor("SELECT id FROM establecimientos WHERE organizacion_id = 1 AND nombre = 'Fundo El Sauce'");
$ajeno = (int) valor('SELECT id FROM establecimientos WHERE organizacion_id = 2 LIMIT 1');
$datos = static fn (array $cambios = []): array => $cambios + [
    'nombre' => 'Fundo El Sauce', 'tipo' => 'CRIANZA', 'rup' => '12.3.47.0003', 'comuna' => 'Gorbea', 'region' => 'La Araucanía',
];

prueba('un predio nuevo guarda el RUP normalizado', function () use ($org, $admin): void {
    $r = op_crear_predio($org, $admin, ['nombre' => 'Hijuela Norte', 'tipo' => 'MIXTO', 'rup' => '091010009']);
    afirmar_igual('09.1.01.0009', valor('SELECT rup FROM establecimientos WHERE id = ?', [$r['id']]), 'RUP');
});

prueba('un RUP sin nueve dígitos se rechaza', function () use ($org, $admin, $sauce, $datos): void {
    afirmar_rechazo(fn () => op_editar_predio($org, $admin, $datos(['predio_id' => $sauce, 'rup' => '12345'])), '9 dígitos');
});

prueba('un RUP repetido entre predios del mismo dueño se rechaza', function () use ($org, $admin, $sauce, $datos): void {
    afirmar_rechazo(fn () => op_editar_predio($org, $admin, $datos(['predio_id' => $sauce, 'rup' => '12.3.45.0001'])), 'ya está cargado');
});

prueba('editar cambia el predio y anota en la bitácora qué cambió', function () use ($org, $admin, $sauce, $datos): void {
    op_editar_predio($org, $admin, $datos(['predio_id' => $sauce, 'rup' => '09-1-01-0003']));
    afirmar_igual('09.1.01.0003', valor('SELECT rup FROM establecimientos WHERE id = ?', [$sauce]), 'RUP');
    $detalle = (string) valor("SELECT detalle FROM bitacora WHERE accion = 'EDITA_PREDIO' AND entidad_id = ? ORDER BY id DESC LIMIT 1", [$sauce]);
    afirmar(str_contains($detalle, '12.3.47.0003 → 09.1.01.0003'), 'bitácora: ' . $detalle);
});

prueba('editar sin cambiar nada se avisa y no ensucia la bitácora', function () use ($org, $admin, $sauce, $datos): void {
    $antes = (int) valor("SELECT COUNT(*) FROM bitacora WHERE accion = 'EDITA_PREDIO'");
    afirmar_rechazo(fn () => op_editar_predio($org, $admin, $datos(['predio_id' => $sauce, 'rup' => '09.1.01.0003'])), 'No cambiaste');
    afirmar_igual($antes, (int) valor("SELECT COUNT(*) FROM bitacora WHERE accion = 'EDITA_PREDIO'"), 'bitácora');
});

prueba('no se puede editar un predio de otra organización', function () use ($org, $admin, $ajeno, $datos): void {
    $antes = fila('SELECT * FROM establecimientos WHERE id = ?', [$ajeno]);
    afirmar_rechazo(fn () => op_editar_predio($org, $admin, $datos(['predio_id' => $ajeno, 'nombre' => 'Tomado', 'rup' => ''])), 'no es de esta organización');
    afirmar_igual($antes, fila('SELECT * FROM establecimientos WHERE id = ?', [$ajeno]), 'predio ajeno intacto');
});

prueba('un tipo de predio inventado se rechaza', function () use ($org, $admin): void {
    afirmar_rechazo(fn () => op_crear_predio($org, $admin, ['nombre' => 'Otro', 'tipo' => 'CASTILLO']), 'Ese tipo de predio no existe');
});

prueba('un texto demasiado largo vuelve como error del campo, no como error del servidor', function () use ($org, $admin): void {
    afirmar_rechazo(fn () => op_crear_predio($org, $admin, ['nombre' => str_repeat('a', 151), 'tipo' => 'MIXTO']), '150');
    afirmar_rechazo(fn () => op_crear_predio($org, $admin, ['nombre' => 'Largo', 'tipo' => 'MIXTO', 'comuna' => str_repeat('b', 81)]), '80');
});

prueba('crear nunca edita, aunque llegue un predio_id', function () use ($org, $admin, $sauce): void {
    $antes = fila('SELECT * FROM establecimientos WHERE id = ?', [$sauce]);
    $r = op_crear_predio($org, $admin, ['predio_id' => $sauce, 'nombre' => 'Potrero Nuevo', 'tipo' => 'MIXTO']);
    afirmar($r['id'] !== $sauce, 'creó uno nuevo');
    afirmar_igual($antes, fila('SELECT * FROM establecimientos WHERE id = ?', [$sauce]), 'el existente quedó intacto');
});
