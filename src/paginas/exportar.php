<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../formulario.php';
require_once __DIR__ . '/../pdf.php';
require_once __DIR__ . '/../xlsx.php';
require_once __DIR__ . '/../exportacion.php';
require_once __DIR__ . '/../vistas/layout.php';

/**
 * Exportar: elegir el listado, filtrarlo, ver cuántos registros trae y bajarlo.
 *
 *  · Excel (.xlsx): para seguir trabajando los datos; los números quedan como
 *    números y la fila de títulos con filtro.
 *  · CSV: separado por punto y coma y con marca de orden de bytes, para
 *    programas que no leen .xlsx.
 *  · PDF: para imprimir, archivar o entregar. Lleva en el encabezado los filtros
 *    aplicados, para que se sepa qué es lo que se está mirando.
 *
 * Las definiciones de los listados y los filtros viven en src/exportacion.php.
 */

$org = organizacion_actual();
$predio = predio_actual();
$tipo = (string) ($_GET['tipo'] ?? '');
$formato = (string) ($_GET['formato'] ?? '');
$listados = listados_exportables();

/* ---------- Sin listado elegido: el menú de listados ---------- */
if ($tipo === '') {
    abrir_pagina(
        'Exportar',
        'Llevarse los datos del predio activo, filtrados: Excel para seguir trabajándolos, PDF para imprimir o entregar.',
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
            . '<a class="boton menudo" href="' . h(url_con('/exportar', ['tipo' => $clave])) . '">Filtrar y exportar</a>'
            . '</td></tr>';
    }
    if (!$alguno) {
        echo '<tr><td colspan="3" class="vacio">Tu rol no exporta ningún listado.</td></tr>';
    }
    echo '</tbody></table></div></div>';

    if (puede('ver_hacienda')) {
        echo '<div class="bloque"><header><div><h2>Atajos del inventario</h2>'
            . '<div class="sub">Un clic arma el filtro; después se puede ajustar</div></div></header><div style="display:flex;flex-wrap:wrap;gap:8px">';
        foreach (atajos_inventario() as $nombre => $parametros) {
            echo '<a class="boton menudo suave" href="' . h(url_con('/exportar', ['tipo' => 'animales'] + $parametros)) . '">' . h($nombre) . '</a>';
        }
        echo '</div></div>';
    }
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

$resultado = filas_exportacion($org, $tipo, $_GET, $predio);
$crudas = $resultado['filas'];
$aplicados = $resultado['aplicados'];
$descripcion_filtros = $aplicados === [] ? 'sin filtros' : implode(' · ', $aplicados);

/* ---------- Descarga ---------- */
if (in_array($formato, ['csv', 'xlsx', 'pdf'], true)) {
    registrar_bitacora(
        $org, (int) usuario_actual()['id'], 'EXPORTA', $tipo, null,
        count($crudas) . ' filas en ' . mb_strtoupper($formato) . ' (' . $descripcion_filtros . ')',
    );
    $archivo = 'ganaderia-' . $tipo . '-' . hoy();
    $filas = array_map(static fn (array $fila): array => array_map('valor_exportado', $fila), $crudas);

    if ($formato === 'pdf') {
        $rup = valor('SELECT rup FROM establecimientos WHERE id = ? AND organizacion_id = ?', [$predio ?? 0, $org]);
        pdf_descargar(
            pdf_listado([
                'titulo'      => $listado['nombre'],
                // El RUP va en el encabezado: es con lo que el SAG identifica el predio.
                'subtitulo'   => usuario_actual()['organizacion'] . ' · ' . nombre_predio()
                                 . ($rup !== null ? ' · RUP ' . $rup : '')
                                 . ' · ' . count($filas) . ' registro(s)'
                                 . ($aplicados !== [] ? ' · ' . $descripcion_filtros : ''),
                'pie'         => 'Gestión ganadera · ' . $listado['nombre'] . ' · ' . $descripcion_filtros,
                'encabezados' => $listado['titulo'],
                'filas'       => $filas,
            ]),
            $archivo . '.pdf',
        );
    }

    if ($formato === 'xlsx') {
        xlsx_descargar(
            xlsx_libro([
                ['nombre' => $listado['nombre'], 'encabezados' => $listado['titulo'], 'filas' => filas_para_excel($tipo, $crudas)],
                ['nombre' => 'Filtros', 'encabezados' => ['Dato', 'Valor'], 'filas' => [
                    ['Listado', $listado['nombre']],
                    ['Predio', nombre_predio()],
                    ['Filtros aplicados', $descripcion_filtros],
                    ['Registros', count($crudas)],
                    ['Exportado el', fecha_corta(hoy())],
                ]],
            ], $listado['nombre']),
            $archivo . '.xlsx',
        );
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $archivo . '.csv"');
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
    exit;
}

/* ---------- Pantalla de filtros con vista previa ---------- */
$g = static fn (string $campo): string => filtro_texto($_GET, $campo);
$seleccionadas = is_array($_GET['categoria'] ?? null) ? array_map('strval', $_GET['categoria']) : [];

abrir_pagina($listado['nombre'], $listado['descripcion'], '/exportar', ['Exportar', $listado['nombre']]);

$opciones = static function (array $items, string $actual, string $todos = 'Todos'): string {
    $html = '<option value="">' . h($todos) . '</option>';
    foreach ($items as $valor => $texto) {
        $html .= '<option value="' . h((string) $valor) . '"' . ((string) $valor === $actual ? ' selected' : '') . '>' . h((string) $texto) . '</option>';
    }
    return $html;
};
$campo_filtro = static fn (string $etiqueta, string $control): string => '<div class="campo"><label>' . h($etiqueta) . '</label>' . $control . '</div>';
?>
<div class="bloque">
  <header>
    <div>
      <h2>Filtros</h2>
      <div class="sub">Elegí qué traer. La vista previa de abajo muestra exactamente lo que se va a descargar.</div>
    </div>
  </header>
  <form method="get" action="<?= h(url('/exportar')) ?>" class="formulario">
    <?= campo_ruta('/exportar') ?>
    <input type="hidden" name="tipo" value="<?= h($tipo) ?>">

    <?php if ($tipo === 'animales'):
        $corrales = filas('SELECT id, nombre FROM corrales WHERE organizacion_id = ?' . ($predio !== null ? ' AND establecimiento_id = ' . (int) $predio : '') . ' ORDER BY nombre', [$org]);
        $razas = array_column(filas("SELECT DISTINCT raza FROM animales WHERE organizacion_id = ? AND raza IS NOT NULL AND raza <> '' ORDER BY raza", [$org]), 'raza');
        $marcas = '';
        foreach (CATEGORIAS_ANIMAL as $valor => $texto) {
            $marcas .= '<label style="display:inline-flex;gap:4px;align-items:center;margin-right:12px;font-weight:500">'
                . '<input type="checkbox" name="categoria[]" value="' . h($valor) . '"' . (in_array($valor, $seleccionadas, true) ? ' checked' : '') . '> ' . h($texto) . '</label>';
        }
        echo '<div class="campo" style="grid-column: 1 / -1"><label>Categoría</label><div>' . $marcas . '</div></div>';
        echo $campo_filtro('Sexo', '<select name="sexo">' . $opciones(SEXOS_ANIMAL, $g('sexo')) . '</select>');
        echo $campo_filtro('Etapa', '<select name="etapa">' . $opciones(ETAPAS_ANIMAL, $g('etapa'), 'Todas') . '</select>');
        echo $campo_filtro('Corral', '<select name="corral_id">' . $opciones(array_column($corrales, 'nombre', 'id'), $g('corral_id')) . '</select>');
        echo $campo_filtro('Raza', '<select name="raza">' . $opciones(array_combine($razas, $razas) ?: [], $g('raza'), 'Todas') . '</select>');
        echo $campo_filtro('Nacidos desde', '<input type="date" name="nacido_desde" value="' . h($g('nacido_desde')) . '">');
        echo $campo_filtro('Nacidos hasta', '<input type="date" name="nacido_hasta" value="' . h($g('nacido_hasta')) . '">');
        echo $campo_filtro('Peso desde (kg)', '<input type="number" min="0" step="1" name="peso_min" value="' . h($g('peso_min')) . '">');
        echo $campo_filtro('Peso hasta (kg)', '<input type="number" min="0" step="1" name="peso_max" value="' . h($g('peso_max')) . '">');
        echo $campo_filtro('Estado', '<select name="estado">' . str_replace('<option value="">Activos</option>', '', $opciones(ESTADOS_ANIMAL, $g('estado') !== '' ? $g('estado') : 'ACTIVO', 'Activos')) . '</select>');
        echo $campo_filtro('Resguardo sanitario', '<select name="resguardo">' . $opciones(['SIN' => 'Sin resguardo (aptos para salir)', 'CON' => 'En resguardo'], $g('resguardo'), 'Indistinto') . '</select>');
    endif; ?>
    <?php if ($listado['fecha'] !== null): ?>
      <?= $campo_filtro('Desde', '<input type="date" name="desde" value="' . h($g('desde')) . '">') ?>
      <?= $campo_filtro('Hasta', '<input type="date" name="hasta" value="' . h($g('hasta')) . '">') ?>
    <?php endif; ?>
    <div class="acciones">
      <button class="boton" type="submit">Aplicar filtros</button>
      <a class="boton suave" href="<?= h(url_con('/exportar', ['tipo' => $tipo])) ?>">Limpiar</a>
    </div>
  </form>
  <?php if ($tipo === 'animales'): ?>
  <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px">
    <span class="secundario" style="align-self:center">Atajos:</span>
    <?php foreach (atajos_inventario() as $nombre => $parametros): ?>
      <a class="boton menudo suave" href="<?= h(url_con('/exportar', ['tipo' => 'animales'] + $parametros)) ?>"><?= h($nombre) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php
// Los enlaces de descarga llevan los mismos filtros que la vista previa.
$con_filtros = array_diff_key($_GET, ['formato' => true, 'r' => true]);
$descarga = static fn (string $f): string => url_con('/exportar', ['formato' => $f] + $con_filtros);
?>
<div class="bloque">
  <header>
    <div>
      <h2><?= numero(count($crudas)) ?> registro<?= count($crudas) === 1 ? '' : 's' ?></h2>
      <div class="sub"><?= h(nombre_predio()) ?> · <?= h($descripcion_filtros) ?></div>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
      <a class="boton" href="<?= h($descarga('xlsx')) ?>">Excel</a>
      <a class="boton suave" href="<?= h($descarga('pdf')) ?>">PDF</a>
      <a class="boton suave" href="<?= h($descarga('csv')) ?>">CSV</a>
    </div>
  </header>
  <div class="tabla-scroll">
    <table data-sin-buscador>
      <thead><tr><?php foreach ($listado['titulo'] as $titulo): ?><th><?= h($titulo) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach (array_slice($crudas, 0, 25) as $fila): ?>
        <tr><?php foreach ($fila as $valor): ?><td><?= h(valor_exportado($valor)) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      <?php if ($crudas === []): ?>
        <tr><td colspan="<?= count($listado['titulo']) ?>" class="vacio">Ningún registro cumple esos filtros.</td></tr>
      <?php elseif (count($crudas) > 25): ?>
        <tr><td colspan="<?= count($listado['titulo']) ?>" class="vacio">Y <?= numero(count($crudas) - 25) ?> más: la descarga trae todos.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php cerrar_pagina('/exportar'); ?>
