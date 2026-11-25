<?php
declare(strict_types=1);

/* Salidas del predio: bloqueo sanitario por resguardo, RUP, DIIO y efectos. */

$org = 1;
$admin = id_usuario('admin@ganaderia.cl');

/** Un animal activo de la organización sin ningún tratamiento en el último año. */
$animal_libre = static function (int $saltar = 0) use ($org): array {
    return fila(
        "SELECT a.* FROM animales a
          WHERE a.organizacion_id = ? AND a.estado = 'ACTIVO' AND a.caravana_visual IS NOT NULL
            AND a.establecimiento_id = (SELECT id FROM establecimientos WHERE organizacion_id = ? AND rup IS NOT NULL ORDER BY id LIMIT 1)
            AND NOT EXISTS (SELECT 1 FROM aplicaciones_tratamiento ap WHERE ap.animal_id = a.id)
          ORDER BY a.id LIMIT 1 OFFSET " . $saltar,
        [$org, $org],
    );
};
$producto = (int) valor(
    "SELECT id FROM insumos WHERE organizacion_id = ? AND tipo = 'VETERINARIO' AND dias_carencia_carne >= 10 AND stock_actual > 50 ORDER BY id LIMIT 1",
    [$org],
);
$datos = static fn (array $cambios = []): array => $cambios + [
    'tipo' => 'VENTA', 'fecha' => hoy(), 'destino_rup' => '08.1.02.0044', 'destino_nombre' => 'Fundo Comprador',
    'comprador' => 'Agrícola Sur', 'transportista' => 'Juan Pérez', 'patente' => 'ab-cd-12',
];

prueba('hay datos para probar', function () use ($animal_libre, $producto): void {
    afirmar($animal_libre() !== null, 'no hay animal libre de tratamientos');
    afirmar($producto > 0, 'no hay producto veterinario con carencia');
});

prueba('un animal tratado hoy queda bloqueado para venta y dice hasta cuándo', function () use ($org, $admin, $animal_libre, $producto, $datos): void {
    $animal = $animal_libre();
    op_tratamiento($org, $admin, ['insumo_id' => $producto, 'fecha' => hoy(), 'dosis' => 5, 'identificacion' => $animal['caravana_visual']]);
    $error = error_de(fn () => op_salida($org, $admin, $datos(['identificaciones' => $animal['caravana_visual']])));
    afirmar($error !== null, 'la salida no se rechazó');
    afirmar(str_contains($error['identificaciones'] ?? '', 'resguardo'), 'mensaje: ' . json_encode($error));
    afirmar(str_contains($error['identificaciones'] ?? '', 'puede salir desde'), 'no dice la fecha');
    afirmar_igual('ACTIVO', valor('SELECT estado FROM animales WHERE id = ?', [$animal['id']]), 'estado');
});

prueba('el bloqueo vale también para faena y traslado, aunque el tratamiento no esté validado', function () use ($org, $admin, $datos): void {
    $tratado = fila(
        "SELECT a.* FROM animales a JOIN aplicaciones_tratamiento ap ON ap.animal_id = a.id
           JOIN tratamientos t ON t.id = ap.tratamiento_id
          WHERE t.fecha = ? AND t.validado_en IS NULL AND a.estado = 'ACTIVO' LIMIT 1",
        [hoy()],
    );
    foreach (['FAENA', 'TRASLADO'] as $tipo) {
        afirmar_rechazo(fn () => op_salida($org, $admin, $datos(['tipo' => $tipo, 'identificaciones' => $tratado['caravana_visual']])), 'resguardo');
    }
});

prueba('una salida fechada después de cumplido el plazo pasa (se mira la fecha de salida)', function () use ($org, $producto): void {
    // Tratamiento de hace 60 días con carencia ≤ 60: hoy ya está apto.
    $dias = (int) valor('SELECT dias_carencia_carne FROM insumos WHERE id = ?', [$producto]);
    $retiro = estado_retiro(hace_dias($dias + 1), $dias);
    afirmar($retiro['apto'], 'el retiro debería estar cumplido');
    $retiro_antes = estado_retiro(hace_dias($dias + 1), $dias, hace_dias($dias));
    afirmar(!$retiro_antes['apto'], 'el día siguiente al tratamiento todavía no está apto');
});

prueba('una venta válida egresa al animal, guarda el FMA y deja bitácora', function () use ($org, $admin, $animal_libre, $datos): void {
    $animal = $animal_libre();
    $r = op_salida($org, $admin, $datos(['identificaciones' => $animal['caravana_visual'], 'peso_total_kg' => 480, 'precio_por_kg' => 2000]));
    afirmar_igual('VENDIDO', valor('SELECT estado FROM animales WHERE id = ?', [$animal['id']]), 'estado');
    $salida = salida_completa($org, $r['id']);
    afirmar_igual('08.1.02.0044', $salida['destino_rup'], 'RUP destino normalizado');
    afirmar_igual('ABCD12', $salida['patente'], 'patente');
    afirmar_igual(960000.0, (float) $salida['monto_neto'], 'monto');
    afirmar_igual($animal['caravana_visual'], $salida['animales'][0]['diio'], 'DIIO en el detalle');
    afirmar((int) valor("SELECT COUNT(*) FROM bitacora WHERE accion = 'SALIDA' AND entidad_id = ?", [$r['id']]) === 1, 'bitácora');
});

prueba('sin RUP de destino o con uno inválido se rechaza', function () use ($org, $admin, $animal_libre, $datos): void {
    $animal = $animal_libre();
    afirmar_rechazo(fn () => op_salida($org, $admin, $datos(['identificaciones' => $animal['caravana_visual'], 'destino_rup' => ''])), 'RUP de destino');
    afirmar_rechazo(fn () => op_salida($org, $admin, $datos(['identificaciones' => $animal['caravana_visual'], 'destino_rup' => '123'])), '9 dígitos');
});

prueba('un animal sin DIIO no puede salir', function () use ($org, $admin, $animal_libre, $datos): void {
    $animal = $animal_libre();
    consultar("UPDATE animales SET caravana_visual = NULL, numero_interno = 'SIN-DIIO-1' WHERE id = ?", [$animal['id']]);
    afirmar_rechazo(fn () => op_salida($org, $admin, $datos(['identificaciones' => 'SIN-DIIO-1'])), 'no tienen DIIO');
});

prueba('traslado a un predio propio deja al animal activo en ese predio', function () use ($org, $admin, $animal_libre, $datos): void {
    $animal = $animal_libre();
    $otro = fila('SELECT * FROM establecimientos WHERE organizacion_id = ? AND id <> ? AND rup IS NOT NULL LIMIT 1', [$org, $animal['establecimiento_id']]);
    if ($otro === null) {
        $r = op_crear_predio($org, $admin, ['nombre' => 'Hijuela Sur', 'tipo' => 'MIXTO', 'rup' => '091010077']);
        $otro = fila('SELECT * FROM establecimientos WHERE id = ?', [$r['id']]);
    }
    afirmar_rechazo(fn () => op_salida($org, $admin, $datos(['identificaciones' => $animal['caravana_visual'], 'destino_rup' => $otro['rup']])), 'registralo como traslado');
    op_salida($org, $admin, $datos(['tipo' => 'TRASLADO', 'identificaciones' => $animal['caravana_visual'], 'destino_rup' => $otro['rup']]));
    $despues = fila('SELECT * FROM animales WHERE id = ?', [$animal['id']]);
    afirmar_igual('ACTIVO', $despues['estado'], 'estado');
    afirmar_igual((int) $otro['id'], (int) $despues['establecimiento_id'], 'predio');
});

prueba('la salida entra también por la app, con el permiso de venta', function () use ($org, $animal_libre, $datos): void {
    $animal = $animal_libre();
    $r = sincronizar_como('operario@ganaderia.cl', $org, ['uuid' => '11111111-2222-4333-8444-555555555555', 'tipo' => 'SALIDA',
        'datos' => $datos(['identificaciones' => [$animal['caravana_visual']]])]);
    afirmar_igual('RECHAZADA', $r['estado'], 'el operario no vende');
});

prueba('cada salida da un borrador de FMA en PDF y una planilla SIPEC válida', function () use ($org): void {
    require_once __DIR__ . '/../src/documentos_sag.php';
    $id = (int) valor('SELECT id FROM salidas WHERE organizacion_id = ? ORDER BY id LIMIT 1', [$org]);
    $salida = salida_completa($org, $id);
    $pdf = pdf_borrador_fma($salida);
    afirmar(str_starts_with($pdf, '%PDF-'), 'no es un PDF');
    // Las páginas van comprimidas: se descomprimen para buscar el texto.
    preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $flujos);
    $texto = implode('', array_map(static fn (string $f): string => (string) @gzuncompress($f), $flujos[1]));
    afirmar(str_contains($texto, 'BORRADOR'), 'falta el aviso de borrador');
    afirmar(str_contains($texto, $salida['animales'][0]['diio']), 'falta el DIIO en el FMA');

    $archivos = leer_zip(xlsx_planilla_sipec($salida));
    foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'xl/styles.xml'] as $parte) {
        afirmar(isset($archivos[$parte]), 'falta ' . $parte);
        afirmar(simplexml_load_string($archivos[$parte]) !== false, $parte . ' no es XML válido');
    }
    afirmar(str_contains($archivos['xl/worksheets/sheet1.xml'], '>' . $salida['animales'][0]['diio'] . '<'), 'el DIIO no está en la hoja');
    afirmar(str_contains($archivos['xl/worksheets/sheet1.xml'], $salida['destino_rup']), 'el RUP de destino no está');
});

prueba('el Excel guarda como texto lo que parece número (DIIO con ceros)', function (): void {
    $archivos = leer_zip(xlsx_libro([['nombre' => 'X', 'encabezados' => ['DIIO', 'Peso'], 'filas' => [['000123', 450.5]]]]));
    $hoja = $archivos['xl/worksheets/sheet1.xml'];
    afirmar(str_contains($hoja, 't="inlineStr"><is><t xml:space="preserve">000123</t>'), 'el DIIO no quedó como texto');
    afirmar(str_contains($hoja, '<v>450.5</v>'), 'el peso no quedó como número');
});
