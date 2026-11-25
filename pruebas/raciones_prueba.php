<?php
declare(strict_types=1);

/* Cálculo automático de raciones y aviso de stock mínimo al entregar. */

$org = 1;
$admin = id_usuario('admin@ganaderia.cl');

prueba('consumo de MS: novillo de 400 kg ganando 1,3 kg/día ≈ 9,6 kg', function (): void {
    $c = consumo_materia_seca(400, 'NOVILLO', 1.3);
    afirmar(abs($c['kg_ms'] - 9.56) < 0.05, 'kg MS: ' . $c['kg_ms']);
});

prueba('más ganancia, más consumo; y nunca fuera del rango real', function (): void {
    afirmar(consumo_materia_seca(300, 'NOVILLO', 1.5)['kg_ms'] > consumo_materia_seca(300, 'NOVILLO', 0.5)['kg_ms'], 'no sube con la ganancia');
    afirmar_igual(consumo_materia_seca(300, 'NOVILLO', 0.0)['kg_ms'], consumo_materia_seca(300, 'NOVILLO', -0.4)['kg_ms'], 'una pérdida baja de la mantención');
    afirmar(consumo_materia_seca(200, 'TERNERO', 9.0)['porcentaje_pv'] <= CONSUMO_PV_MAXIMO, 'supera el máximo');
    afirmar_igual(0.0, consumo_materia_seca(0, 'VACA', 1)['kg_ms'], 'sin peso');
});

prueba('tal cual a partir de MS y de la humedad de la ración', function (): void {
    afirmar_igual(25.0, kg_tal_cual(10, 40), 'silo al 40%');
});

prueba('la ganancia medida sale de dos pesajes separados 14 días o más', function () use ($org): void {
    $animal = (int) valor("SELECT id FROM animales WHERE organizacion_id = ? AND estado = 'ACTIVO' ORDER BY id LIMIT 1", [$org]);
    consultar('UPDATE pesadas SET anulada = 1 WHERE animal_id = ?', [$animal]);
    consultar("INSERT INTO pesadas (organizacion_id, animal_id, fecha, peso_kg) VALUES (?, ?, ?, 300), (?, ?, ?, 330)",
        [$org, $animal, hace_dias(30), $org, $animal, hace_dias(0)]);
    afirmar_igual(1.0, gdp_medidas($org, [$animal])[$animal] ?? null, 'GDP');
});

/** Un corral con animales y una ración con receta y stock. */
$corral = fila(
    "SELECT c.* FROM corrales c WHERE c.organizacion_id = ?
        AND (SELECT COUNT(*) FROM animales a WHERE a.corral_actual_id = c.id AND a.estado = 'ACTIVO') > 0 ORDER BY c.id LIMIT 1",
    [$org],
);
$racion = fila('SELECT r.* FROM raciones r WHERE r.organizacion_id = ? AND EXISTS (SELECT 1 FROM ingredientes_racion ir WHERE ir.racion_id = r.id) ORDER BY r.id LIMIT 1', [$org]);

prueba('la ración calculada del corral suma a sus animales y reparte por ingrediente', function () use ($org, $corral, $racion): void {
    $c = racion_calculada_corral($org, (int) $corral['id'], $racion);
    $cabezas = (int) valor("SELECT COUNT(*) FROM animales WHERE corral_actual_id = ? AND estado = 'ACTIVO'", [$corral['id']]);
    afirmar_igual($cabezas, $c['cabezas'], 'cabezas');
    afirmar($c['kg_ms_dia'] > 0, 'sin MS');
    afirmar_igual(kg_tal_cual($c['kg_ms_dia'], (float) $racion['materia_seca_porcentaje']), $c['kg_tal_cual_dia'], 'tal cual');
    $suma = array_sum(array_column($c['ingredientes'], 'porcentaje'));
    afirmar(abs($suma - 100) < 0.5, 'la receta no suma 100: ' . $suma);
});

prueba('sin kilos planificados, la entrega guarda el plan calculado y lo informa', function () use ($org, $admin, $corral, $racion): void {
    $plan = kg_planificados_calculados($org, $corral, null, $racion);
    $r = op_entrega($org, $admin, ['corral_id' => $corral['id'], 'racion_id' => $racion['id'], 'fecha' => hoy(), 'cantidad' => 100]);
    afirmar_igual($plan, (float) valor('SELECT kg_planificados FROM entregas_alimento WHERE id = ?', [$r['id']]), 'plan guardado');
    afirmar(str_contains($r['mensaje'], 'Plan calculado'), $r['mensaje']);
});

prueba('con kilos planificados a mano, se respeta lo cargado', function () use ($org, $admin, $corral, $racion): void {
    $r = op_entrega($org, $admin, ['corral_id' => $corral['id'], 'racion_id' => $racion['id'], 'fecha' => hoy(), 'cantidad' => 100, 'kg_planificados' => 123]);
    afirmar_igual(123.0, (float) valor('SELECT kg_planificados FROM entregas_alimento WHERE id = ?', [$r['id']]), 'plan');
});

prueba('la entrega descuenta bodega y avisa si un ingrediente queda bajo el mínimo', function () use ($org, $admin, $corral, $racion): void {
    $ingrediente = fila('SELECT i.*, ir.porcentaje FROM ingredientes_racion ir JOIN insumos i ON i.id = ir.insumo_id WHERE ir.racion_id = ? ORDER BY ir.porcentaje DESC LIMIT 1', [$racion['id']]);
    // Deja el stock justo por encima del mínimo: la entrega lo hace cruzar.
    consultar('UPDATE insumos SET stock_minimo = 1000, stock_actual = 1010 WHERE id = ?', [$ingrediente['id']]);
    $r = op_entrega($org, $admin, ['corral_id' => $corral['id'], 'racion_id' => $racion['id'], 'fecha' => hoy(), 'cantidad' => 100]);
    $esperado = round(1010 - 100 * (float) $ingrediente['porcentaje'] / 100, 2);
    afirmar(abs($esperado - (float) valor('SELECT stock_actual FROM insumos WHERE id = ?', [$ingrediente['id']])) < 0.01, 'no descontó');
    afirmar(str_contains($r['mensaje'], 'Stock bajo') && str_contains($r['mensaje'], $ingrediente['nombre']), $r['mensaje']);
});

prueba('sin cruzar el mínimo, no hay aviso', function () use ($org, $admin, $corral, $racion): void {
    consultar('UPDATE insumos SET stock_actual = 1000000 WHERE id IN (SELECT insumo_id FROM ingredientes_racion WHERE racion_id = ?)', [$racion['id']]);
    $r = op_entrega($org, $admin, ['corral_id' => $corral['id'], 'racion_id' => $racion['id'], 'fecha' => hoy(), 'cantidad' => 100]);
    afirmar(!str_contains($r['mensaje'], 'Stock bajo'), $r['mensaje']);
});
