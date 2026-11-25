<?php
declare(strict_types=1);

/* Exportación con filtros: cada filtro acota lo que se descarga. */

require_once __DIR__ . '/../src/exportacion.php';
require_once __DIR__ . '/../src/xlsx.php';

$org = 1;
$predio = (int) valor('SELECT establecimiento_id FROM animales WHERE organizacion_id = ? GROUP BY establecimiento_id ORDER BY COUNT(*) DESC LIMIT 1', [$org]);
$contar = static fn (array $f): int => count(filas_exportacion($org, 'animales', $f, $predio)['filas']);
$activos = (int) valor("SELECT COUNT(*) FROM animales WHERE organizacion_id = ? AND establecimiento_id = ? AND estado = 'ACTIVO'", [$org, $predio]);

prueba('sin filtros trae los activos del predio', function () use ($contar, $activos): void {
    afirmar($activos > 0, 'no hay animales en la demo');
    afirmar_igual($activos, $contar([]), 'activos');
});

prueba('filtrar por categoría trae solo esa categoría', function () use ($org, $predio, $contar): void {
    $vacas = (int) valor("SELECT COUNT(*) FROM animales WHERE organizacion_id = ? AND establecimiento_id = ? AND estado = 'ACTIVO' AND categoria = 'VACA'", [$org, $predio]);
    afirmar_igual($vacas, $contar(['categoria' => ['VACA']]), 'vacas');
    $filas = filas_exportacion($org, 'animales', ['categoria' => ['VACA']], $predio)['filas'];
    foreach ($filas as $fila) {
        afirmar_igual('VACA', $fila[4], 'categoría');
    }
});

prueba('terneros recién nacidos: categoría y fecha de nacimiento juntas', function () use ($org, $predio, $contar): void {
    $esperado = (int) valor(
        "SELECT COUNT(*) FROM animales WHERE organizacion_id = ? AND establecimiento_id = ? AND estado = 'ACTIVO'
            AND categoria IN ('TERNERO', 'TERNERA') AND fecha_nacimiento >= ?",
        [$org, $predio, hace_dias(30)],
    );
    afirmar_igual($esperado, $contar(atajos_inventario()['Terneros nacidos en los últimos 30 días']), 'terneros');
});

prueba('rango de peso', function () use ($org, $predio, $contar): void {
    $esperado = (int) valor(
        "SELECT COUNT(*) FROM animales WHERE organizacion_id = ? AND establecimiento_id = ? AND estado = 'ACTIVO' AND peso_actual_kg BETWEEN 300 AND 450",
        [$org, $predio],
    );
    afirmar_igual($esperado, $contar(['peso_min' => '300', 'peso_max' => '450']), 'peso');
});

prueba('con y sin resguardo suman el total', function () use ($contar, $activos): void {
    afirmar_igual($activos, $contar(['resguardo' => 'CON']) + $contar(['resguardo' => 'SIN']), 'total');
});

prueba('un valor desconocido o malicioso se ignora, no rompe la consulta', function () use ($contar, $activos): void {
    afirmar_igual($activos, $contar(['categoria' => ["VACA') OR 1=1 --"], 'sexo' => 'X', 'corral_id' => '1 OR 1=1', 'nacido_desde' => 'ayer']), 'ignorados');
});

prueba('los filtros aplicados se describen para el PDF', function () use ($org, $predio): void {
    $r = filas_exportacion($org, 'animales', ['categoria' => ['TERNERO'], 'sexo' => 'MACHO'], $predio);
    afirmar(in_array('Ternero', $r['aplicados'], true) && in_array('Macho', $r['aplicados'], true), json_encode($r['aplicados']));
});

prueba('los listados con fecha aceptan rango', function () use ($org, $predio): void {
    $todas = count(filas_exportacion($org, 'partos', [], $predio)['filas']);
    $recientes = count(filas_exportacion($org, 'partos', ['desde' => hace_dias(30)], $predio)['filas']);
    afirmar($recientes <= $todas, 'el rango no acota');
});

prueba('el Excel del inventario deja los pesos como número', function () use ($org, $predio): void {
    $filas = filas_para_excel('animales', filas_exportacion($org, 'animales', [], $predio)['filas']);
    $con_peso = array_values(array_filter($filas, static fn (array $f): bool => is_float($f[13])));
    afirmar($con_peso !== [], 'ningún peso quedó como número');
    $libro = leer_zip(xlsx_libro([['nombre' => 'Inventario', 'encabezados' => listados_exportables()['animales']['titulo'], 'filas' => $filas]]));
    afirmar(simplexml_load_string($libro['xl/worksheets/sheet1.xml']) !== false, 'hoja inválida');
});
