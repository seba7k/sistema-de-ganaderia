<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../formulario.php';
require_once __DIR__ . '/../pdf.php';
require_once __DIR__ . '/../vistas/layout.php';

/**
 * Exportar: planilla o PDF.
 *
 * La planilla sale en CSV separado por punto y coma y con marca de orden de
 * bytes: así Excel en español lo abre en columnas de una, sin el asistente de
 * importación y sin romper las tildes. Sirve para seguir trabajando los datos.
 *
 * El PDF es para lo otro: imprimir, archivar o entregar. Se arma en
 * `src/pdf.php`, sin librerías, porque el hosting no tiene Composer.
 *
 * Lo que se exporta es lo del predio activo, y cada listado exige el mismo
 * permiso que su pantalla.
 */

$org = organizacion_actual();
$tipo = (string) ($_GET['tipo'] ?? '');
$formato = ($_GET['formato'] ?? 'csv') === 'pdf' ? 'pdf' : 'csv';

$listados = [
    'animales' => [
        'nombre' => 'Inventario de animales',
        'descripcion' => 'Todos los activos con su identificación, pesos y ubicación',
        'permiso' => 'ver_hacienda',
        'titulo'  => ['DIIO', 'Número interno', 'EID', 'Sexo', 'Categoría', 'Raza', 'Etapa', 'Corral',
                      'Nacimiento', 'Destete', 'Secado', 'Ingreso', 'Peso ingreso', 'Peso actual', 'Último pesaje', 'Estado'],
        'consulta' => "SELECT a.caravana_visual, a.numero_interno, a.eid, a.sexo, a.categoria, a.raza, a.etapa,
                              c.nombre AS corral, a.fecha_nacimiento, a.fecha_destete, a.fecha_secado, a.fecha_ingreso,
                              a.peso_ingreso_kg, a.peso_actual_kg, a.fecha_ultima_pesada, a.estado
                         FROM animales a
                         LEFT JOIN corrales c ON c.id = a.corral_actual_id
                        WHERE a.organizacion_id = ? AND a.estado = 'ACTIVO'",
        'orden'   => ' ORDER BY COALESCE(a.caravana_visual, a.numero_interno)',
        'alias'   => 'a',
    ],
    'partos' => [
        'nombre' => 'Partos',
        'descripcion' => 'Fecha, vaca, resultado, dificultad y ternero',
        'permiso' => 'ver_reproduccion',
        'titulo'  => ['Fecha', 'Vaca', 'Resultado', 'Dificultad', 'Ternero débil', 'Ternero', 'Peso al nacer', 'Observaciones'],
        'consulta' => "SELECT p.fecha, COALESCE(m.caravana_visual, m.numero_interno) AS vaca, p.resultado, p.dificultad,
                              CASE WHEN p.ternero_debil = 1 THEN 'Sí' ELSE 'No' END AS debil,
                              COALESCE(t.caravana_visual, t.numero_interno) AS ternero,
                              p.peso_nacimiento_kg, p.observaciones
                         FROM partos p
                         JOIN animales m ON m.id = p.vaca_id
                         LEFT JOIN animales t ON t.id = p.ternero_id
                        WHERE p.organizacion_id = ?",
        'orden'   => ' ORDER BY p.fecha DESC',
        'alias'   => 'm',
    ],
    'entregas' => [
        'nombre' => 'Entregas de alimento',
        'descripcion' => 'Qué se entregó, a qué corral o animal y cuánta materia seca',
        'permiso' => 'ver_alimentacion',
        'titulo'  => ['Fecha', 'Corral', 'Animal', 'Ración', 'Kilos entregados', 'Kilos materia seca', 'Entrega del día'],
        'consulta' => 'SELECT e.fecha, c.nombre AS corral, COALESCE(a.caravana_visual, a.numero_interno) AS animal,
                              r.nombre AS racion, e.kg_entregados, e.kg_materia_seca, e.numero_entrega
                         FROM entregas_alimento e
                         JOIN raciones r ON r.id = e.racion_id
                         LEFT JOIN corrales c ON c.id = e.corral_id
                         LEFT JOIN animales a ON a.id = e.animal_id
                        WHERE e.organizacion_id = ? AND e.anulada = 0',
        'orden'   => ' ORDER BY e.fecha DESC',
        'alias'   => 'c',
    ],
    'notas' => [
        'nombre' => 'Notas de las fichas',
        'descripcion' => 'Las observaciones escritas en cada animal',
        'permiso' => 'ver_hacienda',
        'titulo'  => ['Fecha', 'Animal', 'Nota', 'Quién la escribió'],
        'consulta' => 'SELECT n.fecha, COALESCE(a.caravana_visual, a.numero_interno) AS animal, n.texto,
                              COALESCE(u.nombre, \'\') AS autor
                         FROM notas_animal n
                         JOIN animales a ON a.id = n.animal_id
                         LEFT JOIN usuarios u ON u.id = n.usuario_id
                        WHERE n.organizacion_id = ? AND n.anulada = 0',
        'orden'   => ' ORDER BY n.fecha DESC',
        'alias'   => 'a',
    ],
    'bitacora' => [
        'nombre' => 'Bitácora',
        'descripcion' => 'Correcciones, anulaciones, accesos y exportaciones',
        'permiso' => 'ver_bitacora',
        'titulo'  => ['Cuándo', 'Qué pasó', 'Quién', 'Entidad', 'Detalle', 'Origen'],
        'consulta' => "SELECT b.fecha_hora, b.accion, COALESCE(u.nombre, '') AS quien, b.entidad, b.detalle, b.origen
                         FROM bitacora b
                         LEFT JOIN usuarios u ON u.id = b.usuario_id
                        WHERE (b.organizacion_id = ? OR b.organizacion_id IS NULL)",
        'orden'   => ' ORDER BY b.fecha_hora DESC',
        'alias'   => null,
    ],
];

/* Sin listado elegido se muestra la pantalla con los que hay. Antes esta ruta
   solo servía para descargar, y entrar desde el menú daba 404. */
if ($tipo === '') {
    abrir_pagina(
        'Exportar',
        'Llevarse los datos del predio activo: planilla para seguir trabajándolos, PDF para imprimir o entregar.',
        '/exportar',
        ['Exportar'],
    );
    echo '<div class="bloque"><div class="tabla-scroll"><table data-sin-buscador>'
        . '<thead><tr><th>Listado</th><th>Qué trae</th><th></th></tr></thead><tbody>';
    $alguno = false;
    foreach ($listados as $clave => $definicion) {
        if (!puede($definicion['permiso'])) {
            continue;
        }
        $alguno = true;
        echo '<tr><td><strong>' . h($definicion['nombre']) . '</strong></td>'
            . '<td>' . h($definicion['descripcion']) . '</td>'
            . '<td class="acciones-fila">'
            . '<a class="boton menudo suave" href="' . h(url_con('/exportar', ['tipo' => $clave])) . '">Planilla</a> '
            . '<a class="boton menudo" href="' . h(url_con('/exportar', ['tipo' => $clave, 'formato' => 'pdf'])) . '">PDF</a>'
            . '</td></tr>';
    }
    if (!$alguno) {
        echo '<tr><td colspan="3" class="vacio">Tu rol no exporta ningún listado.</td></tr>';
    }
    echo '</tbody></table></div></div>';
    cerrar_pagina('/exportar');
    exit;
}

if (!isset($listados[$tipo])) {
    http_response_code(404);
    require __DIR__ . '/../vistas/error-404.php';
    exit;
}

$listado = $listados[$tipo];
exigir_permiso($listado['permiso']);

$filas = filas(
    $listado['consulta'] . ($listado['alias'] !== null ? filtro_predio($listado['alias']) : '') . $listado['orden'],
    [$org],
);

$filas = array_map(
    static fn (array $fila): array => array_map('valor_exportado', array_values($fila)),
    $filas,
);

registrar_bitacora(
    $org, (int) usuario_actual()['id'], 'EXPORTA', $tipo, null,
    count($filas) . ' filas en ' . mb_strtoupper($formato),
);

if ($formato === 'pdf') {
    pdf_descargar(
        pdf_listado([
            'titulo'      => $listado['nombre'],
            // El RUP va en el encabezado: es con lo que el SAG identifica el predio.
            'subtitulo'   => usuario_actual()['organizacion'] . ' · ' . nombre_predio()
                             . (($rup = valor('SELECT rup FROM establecimientos WHERE id = ? AND organizacion_id = ?', [predio_actual() ?? 0, $org])) !== null ? ' · RUP ' . $rup : '')
                             . ' · ' . count($filas) . ' registro(s)',
            'pie'         => 'Gestión ganadera · ' . $listado['descripcion'],
            'encabezados' => $listado['titulo'],
            'filas'       => $filas,
        ]),
        'ganaderia-' . $tipo . '-' . hoy() . '.pdf',
    );
}

$archivo = 'ganaderia-' . $tipo . '-' . hoy() . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $archivo . '"');
header('Cache-Control: no-store');

$salida = fopen('php://output', 'w');
// Marca de orden de bytes: sin esto Excel muestra las tildes rotas.
fwrite($salida, "\xEF\xBB\xBF");
fputcsv($salida, $listado['titulo'], ';', '"', '');
foreach ($filas as $fila_csv) {
    fputcsv($salida, array_map(
        // Coma decimal solo en los números: un texto con puntos queda como está.
        static function (string $valor): string {
            if (is_numeric($valor)) {
                return str_replace('.', ',', $valor);
            }
            return preg_match('/^(?:[\t\r\n]|\s*[=+@-])/u', $valor) === 1 ? "'" . $valor : $valor;
        },
        $fila_csv,
    ), ';', '"', '');
}
fclose($salida);
