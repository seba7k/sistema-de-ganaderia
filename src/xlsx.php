<?php
declare(strict_types=1);

/**
 * Planillas Excel (.xlsx) sin librerías externas.
 *
 * Un .xlsx es un ZIP con unos pocos XML adentro. En un hosting compartido no
 * siempre está la extensión `zip` de PHP, así que el ZIP también se arma acá:
 * cabeceras locales, directorio central y CRC32. Si está zlib (casi siempre),
 * los archivos van comprimidos; si no, guardados tal cual, que también es válido.
 *
 * Reglas de los valores, para que Excel no «corrija» nada:
 *  · int y float de PHP van como número;
 *  · todo lo demás va como texto, aunque parezca número: un DIIO o un RUP con
 *    ceros adelante tiene que seguir teniéndolos.
 */

/**
 * @param list<array{nombre: string, encabezados: list<string>, filas: list<array<int|string, mixed>>, anchos?: list<float>}> $hojas
 */
function xlsx_libro(array $hojas, string $titulo = 'Gestión ganadera'): string
{
    $hojas = $hojas === [] ? [['nombre' => 'Hoja1', 'encabezados' => [], 'filas' => []]] : array_values($hojas);

    $archivos = [];
    $tipos = '';
    $hojas_libro = '';
    $relaciones = '';
    $usados = [];
    foreach ($hojas as $i => $hoja) {
        $n = $i + 1;
        $archivos["xl/worksheets/sheet{$n}.xml"] = xlsx_hoja($hoja);
        $tipos .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $nombre = xlsx_nombre_hoja((string) $hoja['nombre'], $usados);
        $hojas_libro .= '<sheet name="' . xlsx_xml($nombre) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $relaciones .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    }
    $n_estilos = count($hojas) + 1;

    $archivos = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . $tipos . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>',
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . xlsx_xml($titulo) . '</dc:title><dc:creator>Gestión ganadera</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
            . '</cp:coreProperties>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $hojas_libro . '</sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relaciones
            . '<Relationship Id="rId' . $n_estilos . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        // Estilo 0: normal. Estilo 1: encabezado en negrita sobre verde claro.
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCEFE2"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '</styleSheet>',
    ] + $archivos;

    return zip_armar($archivos);
}

/** XML de una hoja: fila de títulos congelada, autofiltro y anchos razonables. */
function xlsx_hoja(array $hoja): string
{
    $encabezados = array_values($hoja['encabezados']);
    $filas = array_map('array_values', $hoja['filas']);
    $columnas = max(1, count($encabezados), ...array_map('count', $filas ?: [[]]));

    // Ancho de cada columna según el texto más largo (con tope), si no viene dado.
    $anchos = $hoja['anchos'] ?? [];
    if ($anchos === []) {
        for ($c = 0; $c < $columnas; $c++) {
            $largo = mb_strlen((string) ($encabezados[$c] ?? ''));
            foreach (array_slice($filas, 0, 500) as $fila) {
                $largo = max($largo, mb_strlen((string) ($fila[$c] ?? '')));
            }
            $anchos[] = min(max($largo + 2, 8), 50);
        }
    }
    $cols = '';
    foreach ($anchos as $c => $ancho) {
        $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . number_format((float) $ancho, 1, '.', '') . '" customWidth="1"/>';
    }

    $xml = '';
    $r = 0;
    if ($encabezados !== []) {
        $r++;
        $xml .= xlsx_fila($r, $encabezados, 1);
    }
    foreach ($filas as $fila) {
        $r++;
        $xml .= xlsx_fila($r, $fila, 0);
    }

    $ultima = xlsx_columna($columnas - 1) . max($r, 1);
    $congelar = $encabezados !== []
        ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        : '';
    $filtro = $encabezados !== [] && $filas !== [] ? '<autoFilter ref="A1:' . $ultima . '"/>' : '';

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<dimension ref="A1:' . $ultima . '"/>' . $congelar
        . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
        . '<sheetData>' . $xml . '</sheetData>' . $filtro . '</worksheet>';
}

function xlsx_fila(int $r, array $valores, int $estilo): string
{
    $xml = '<row r="' . $r . '">';
    foreach (array_values($valores) as $c => $valor) {
        if ($valor === null || $valor === '') {
            continue;
        }
        $ref = xlsx_columna($c) . $r;
        $s = $estilo > 0 ? ' s="' . $estilo . '"' : '';
        if ((is_int($valor) || is_float($valor)) && is_finite((float) $valor)) {
            $xml .= '<c r="' . $ref . '"' . $s . '><v>' . (is_int($valor) ? $valor : rtrim(rtrim(sprintf('%.6F', $valor), '0'), '.')) . '</v></c>';
        } else {
            $texto = is_bool($valor) ? ($valor ? 'Sí' : 'No') : (string) $valor;
            $xml .= '<c r="' . $ref . '" t="inlineStr"' . $s . '><is><t xml:space="preserve">' . xlsx_xml($texto) . '</t></is></c>';
        }
    }
    return $xml . '</row>';
}

/** 0 → A, 25 → Z, 26 → AA. */
function xlsx_columna(int $indice): string
{
    $letras = '';
    for ($n = $indice + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $letras = chr(65 + ($n - 1) % 26) . $letras;
    }
    return $letras;
}

/** Texto seguro para XML: escapado y sin caracteres de control que Excel rechaza. */
function xlsx_xml(string $texto): string
{
    $limpio = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $texto) ?? '';
    return htmlspecialchars($limpio, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Excel limita el nombre de la hoja a 31 caracteres, sin []:*?/\ y sin repetir. */
function xlsx_nombre_hoja(string $nombre, array &$usados): string
{
    $base = mb_substr(trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $nombre)) ?: 'Hoja', 0, 31);
    $final = $base;
    for ($i = 2; in_array(mb_strtolower($final), $usados, true); $i++) {
        $final = mb_substr($base, 0, 31 - strlen((string) $i) - 1) . ' ' . $i;
    }
    $usados[] = mb_strtolower($final);
    return $final;
}

/**
 * Arma un ZIP en memoria.
 *
 * @param array<string, string> $archivos ruta dentro del zip => contenido
 */
function zip_armar(array $archivos): string
{
    $comprimir = function_exists('gzdeflate');
    $momento = getdate();
    $hora_dos = ($momento['hours'] << 11) | ($momento['minutes'] << 5) | intdiv($momento['seconds'], 2);
    $fecha_dos = (max($momento['year'] - 1980, 0) << 9) | ($momento['mon'] << 5) | $momento['mday'];

    $cuerpo = '';
    $directorio = '';
    foreach ($archivos as $nombre => $contenido) {
        $crc = crc32($contenido);
        $datos = $comprimir ? (string) gzdeflate($contenido, 6) : $contenido;
        $metodo = $comprimir ? 8 : 0;
        $desplazamiento = strlen($cuerpo);

        // Bit 11: nombres en UTF-8.
        // versión, banderas, método, hora, fecha (16 bits c/u); CRC y tamaños (32 bits).
        $comun = pack('vvvvvVVV', 20, 0x0800, $metodo, $hora_dos, $fecha_dos, $crc, strlen($datos), strlen($contenido))
            . pack('v', strlen($nombre));
        $cuerpo .= "PK\x03\x04" . $comun . pack('v', 0) . $nombre . $datos;
        $directorio .= "PK\x01\x02" . pack('v', 20) . $comun
            . pack('vvvvVV', 0, 0, 0, 0, 0, $desplazamiento) . $nombre;
    }

    return $cuerpo . $directorio
        . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($archivos), count($archivos), strlen($directorio), strlen($cuerpo), 0);
}

/** Manda la planilla al navegador como descarga. */
function xlsx_descargar(string $xlsx, string $archivo): never
{
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    header('Content-Length: ' . strlen($xlsx));
    header('Cache-Control: no-store');
    echo $xlsx;
    exit;
}
