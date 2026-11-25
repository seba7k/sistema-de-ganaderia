<?php
declare(strict_types=1);

require_once __DIR__ . '/formato.php';

/**
 * Generación de PDF sin librerías externas.
 *
 * En un hosting compartido no hay Composer ni forma cómoda de instalar FPDF o
 * similares, así que el archivo se arma acá: un PDF es texto plano con una tabla
 * de posiciones al final, y para una planilla impresa alcanza con dibujar
 * rectángulos y escribir líneas de texto.
 *
 * Se usan las tipografías base de PDF (Helvetica normal y negrita), que todo
 * lector trae incorporadas: no hay que empotrar ningún archivo de fuente y el
 * PDF pesa unos pocos kilobytes. El texto va en Windows-1252, que es la
 * codificación que entienden esas tipografías; las tildes y la ñ entran ahí.
 *
 * Lo que no hace, porque este sistema no lo necesita: imágenes, colores por
 * celda, texto en varias líneas dentro de una celda (se recorta con puntos
 * suspensivos) y alfabetos fuera del latino.
 */

const PDF_A4_LARGO = 841.89;
const PDF_A4_CORTO = 595.28;
const PDF_MARGEN = 32.0;

/* ============================================================
   Medición de texto
   ============================================================ */

/**
 * Ancho de cada carácter en Helvetica, en milésimas del tamaño de letra.
 *
 * Son los valores de la tipografía; con ellos se sabe cuánto va a ocupar un
 * texto antes de dibujarlo, que es lo que permite repartir las columnas y
 * recortar lo que no entra.
 *
 * @return array<int, int>
 */
function pdf_anchos(): array
{
    static $anchos = null;
    if ($anchos !== null) {
        return $anchos;
    }

    $base = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,  // espacio a /
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556,                                 // 0 a 9
        278, 278, 584, 584, 584, 556, 1015,                                               // : a @
        667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611,                            // A a Z
        278, 278, 278, 469, 556, 333,                                                     // [ a `
        556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500,                            // a a z
        334, 260, 334, 584,                                                               // { a ~
    ];

    $anchos = [];
    foreach ($base as $posicion => $ancho) {
        $anchos[32 + $posicion] = $ancho;
    }

    // Los acentuados de Windows-1252 que aparecen en castellano.
    foreach ([
        133 => 1000, 161 => 333, 170 => 370, 176 => 400, 186 => 365, 191 => 611,
        193 => 667, 201 => 667, 205 => 278, 209 => 722, 211 => 778, 218 => 722, 220 => 722,
        199 => 722, 231 => 500,
        225 => 556, 233 => 556, 237 => 278, 241 => 556, 243 => 556, 250 => 556, 252 => 556,
    ] as $codigo => $ancho) {
        $anchos[$codigo] = $ancho;
    }

    return $anchos;
}

/** Pasa el texto a Windows-1252, que es lo que entienden las tipografías base. */
function pdf_texto_plano(string $texto): string
{
    $convertido = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto);
    if ($convertido === false) {
        $convertido = mb_convert_encoding($texto, 'Windows-1252', 'UTF-8');
    }
    // Los saltos de línea dentro de una celda se ven como un espacio.
    return (string) preg_replace('/[\r\n\t]+/', ' ', (string) $convertido);
}

/** Cuánto mide el texto dibujado, en puntos. Recibe el texto ya convertido. */
function pdf_ancho_texto(string $plano, float $tamano, bool $negrita = false): float
{
    $anchos = pdf_anchos();
    $total = 0;
    $largo = strlen($plano);
    for ($i = 0; $i < $largo; $i++) {
        $total += $anchos[ord($plano[$i])] ?? 556;
    }
    // La negrita de Helvetica es algo más ancha; alcanza con este ajuste.
    return $total / 1000 * $tamano * ($negrita ? 1.06 : 1.0);
}

/** Recorta el texto para que entre en el ancho dado, con puntos suspensivos. */
function pdf_recortar(string $plano, float $maximo, float $tamano, bool $negrita = false): string
{
    if (pdf_ancho_texto($plano, $tamano, $negrita) <= $maximo) {
        return $plano;
    }
    $puntos = "\x85"; // … en Windows-1252
    $recortado = $plano;
    while ($recortado !== '' && pdf_ancho_texto($recortado . $puntos, $tamano, $negrita) > $maximo) {
        $recortado = substr($recortado, 0, -1);
    }
    return $recortado === '' ? '' : $recortado . $puntos;
}

/** Escapa lo que el formato PDF trata como sintaxis. */
function pdf_escapar(string $plano): string
{
    return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $plano);
}

/* ============================================================
   Dibujo
   ============================================================ */

/**
 * Una línea de texto. La coordenada Y se mide desde arriba, como se lee, y acá
 * se da vuelta: el PDF cuenta desde abajo.
 */
function pdf_texto(float $x, float $y_desde_arriba, string $plano, float $tamano, array $opciones = []): string
{
    $alto = (float) ($opciones['alto_pagina'] ?? PDF_A4_CORTO);
    $fuente = !empty($opciones['negrita']) ? '/F2' : '/F1';
    $color = $opciones['color'] ?? [0.1, 0.13, 0.1];

    return sprintf(
        "%.3f %.3f %.3f rg BT %s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
        $color[0], $color[1], $color[2], $fuente, $tamano, $x, $alto - $y_desde_arriba, pdf_escapar($plano),
    );
}

/** Un rectángulo relleno: las bandas del encabezado y las filas alternadas. */
function pdf_rectangulo(float $x, float $y_desde_arriba, float $ancho, float $alto, array $color, float $alto_pagina): string
{
    return sprintf(
        "%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n",
        $color[0], $color[1], $color[2], $x, $alto_pagina - $y_desde_arriba - $alto, $ancho, $alto,
    );
}

/** Una línea horizontal fina, para separar el pie. */
function pdf_linea(float $x1, float $x2, float $y_desde_arriba, array $color, float $alto_pagina): string
{
    return sprintf(
        "%.3f %.3f %.3f RG 0.5 w %.2f %.2f m %.2f %.2f l S\n",
        $color[0], $color[1], $color[2], $x1, $alto_pagina - $y_desde_arriba, $x2, $alto_pagina - $y_desde_arriba,
    );
}

/* ============================================================
   Armado del archivo
   ============================================================ */

/**
 * Une las páginas ya dibujadas en un PDF válido.
 *
 * @param list<string> $paginas contenido de cada página
 */
function pdf_documento(array $paginas, float $ancho, float $alto, string $titulo): string
{
    $objetos = [];

    // 1: catálogo · 2: árbol de páginas · 3 y 4: las dos tipografías.
    $objetos[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objetos[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
    $objetos[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

    $numero = 5;
    $hijos = [];
    foreach ($paginas as $contenido) {
        $obj_pagina = $numero++;
        $obj_flujo = $numero++;
        $hijos[] = $obj_pagina . ' 0 R';

        $objetos[$obj_pagina] = sprintf(
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] "
            . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>",
            $ancho, $alto, $obj_flujo,
        );

        // Se comprime si el servidor tiene zlib, que es lo habitual: un listado
        // largo baja de cientos de kilobytes a unas pocas decenas.
        if (function_exists('gzcompress')) {
            $datos = (string) gzcompress($contenido, 6);
            $objetos[$obj_flujo] = "<< /Length " . strlen($datos) . " /Filter /FlateDecode >>\nstream\n"
                . $datos . "\nendstream";
        } else {
            $objetos[$obj_flujo] = "<< /Length " . strlen($contenido) . " >>\nstream\n" . $contenido . "\nendstream";
        }
    }

    $objetos[2] = "<< /Type /Pages /Kids [" . implode(' ', $hijos) . "] /Count " . count($paginas) . " >>";

    $obj_info = $numero++;
    $objetos[$obj_info] = "<< /Title (" . pdf_escapar(pdf_texto_plano($titulo)) . ") "
        . "/Producer (Gestion ganadera) /CreationDate (D:" . date('YmdHis') . ") >>";

    ksort($objetos);

    $pdf = "%PDF-1.4\n";
    $posiciones = [];
    foreach ($objetos as $id => $cuerpo) {
        $posiciones[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $cuerpo . "\nendobj\n";
    }

    /* La tabla de referencias cruzadas dice en qué byte empieza cada objeto: es
       lo que permite al lector abrir el archivo sin leerlo entero. */
    $inicio_tabla = strlen($pdf);
    $total = count($objetos) + 1;
    $pdf .= "xref\n0 " . $total . "\n0000000000 65535 f \n";
    for ($id = 1; $id < $total; $id++) {
        $pdf .= sprintf("%010d 00000 n \n", $posiciones[$id] ?? 0);
    }
    $pdf .= "trailer\n<< /Size " . $total . " /Root 1 0 R /Info " . $obj_info . " 0 R >>\n"
        . "startxref\n" . $inicio_tabla . "\n%%EOF";

    return $pdf;
}

/* ============================================================
   Piezas que comparten la planilla y la ficha
   ============================================================ */

/**
 * Corta un texto largo en líneas que entren en el ancho dado.
 *
 * Es lo que permite que una nota de tres renglones se imprima entera en vez de
 * quedar recortada con puntos suspensivos como una celda de tabla.
 *
 * @return list<string>
 */
function pdf_envolver(string $plano, float $ancho, float $tamano): array
{
    $lineas = [];
    $actual = '';
    foreach (explode(' ', $plano) as $palabra) {
        $prueba = $actual === '' ? $palabra : $actual . ' ' . $palabra;
        if (pdf_ancho_texto($prueba, $tamano) <= $ancho) {
            $actual = $prueba;
            continue;
        }
        if ($actual !== '') {
            $lineas[] = $actual;
        }
        // Una palabra sola más ancha que la columna (un código largo) se recorta.
        $actual = pdf_ancho_texto($palabra, $tamano) > $ancho
            ? pdf_recortar($palabra, $ancho, $tamano)
            : $palabra;
    }
    if ($actual !== '') {
        $lineas[] = $actual;
    }
    return $lineas === [] ? [''] : $lineas;
}

/**
 * Reparte el ancho disponible entre las columnas y decide cuáles van alineadas
 * a la derecha: las que solo tienen números, que así se leen por la unidad.
 *
 * @param list<string>              $encabezados
 * @param list<list<string>>        $filas
 * @return array{0: list<float>, 1: list<bool>}
 */
function pdf_columnas(array $encabezados, array $filas, float $util, float $tamano): array
{
    $anchos = [];
    $derecha = [];
    foreach ($encabezados as $c => $titulo) {
        $ancho = pdf_ancho_texto($titulo, $tamano, true);
        $numerica = true;
        $con_datos = false;
        foreach ($filas as $fila) {
            $celda = $fila[$c] ?? '';
            $ancho = max($ancho, pdf_ancho_texto($celda, $tamano));
            if ($celda !== '') {
                $con_datos = true;
                if (!is_numeric(str_replace(',', '.', $celda))) {
                    $numerica = false;
                }
            }
        }
        $anchos[$c] = min($ancho + 8.0, $util * 0.28);
        $derecha[$c] = $numerica && $con_datos;
    }

    $suma = array_sum($anchos);
    $factor = $suma > 0 ? $util / $suma : 1.0;
    foreach ($anchos as $c => $ancho) {
        $anchos[$c] = $ancho * $factor;
    }

    return [$anchos, $derecha];
}

/** Título, subtítulo y fecha de emisión, arriba de cada hoja. */
function pdf_encabezado_hoja(string $titulo, string $subtitulo, float $ancho_pagina, float $alto_pagina): string
{
    $gris = [0.42, 0.45, 0.42];
    $c = pdf_texto(PDF_MARGEN, 46, pdf_texto_plano($titulo), 15, [
        'negrita' => true, 'alto_pagina' => $alto_pagina, 'color' => [0.06, 0.14, 0.09],
    ]);
    if ($subtitulo !== '') {
        $c .= pdf_texto(PDF_MARGEN, 62, pdf_texto_plano($subtitulo), 8.5, [
            'alto_pagina' => $alto_pagina, 'color' => $gris,
        ]);
    }
    $sello = pdf_texto_plano('Emitido el ' . fecha_corta(hoy()));
    return $c . pdf_texto(
        $ancho_pagina - PDF_MARGEN - pdf_ancho_texto($sello, 8.5),
        46, $sello, 8.5, ['alto_pagina' => $alto_pagina, 'color' => $gris],
    );
}

/** De dónde salió el documento y en qué hoja va. */
function pdf_pie_hoja(string $pie, int $hoja, int $total, float $ancho_pagina, float $alto_pagina): string
{
    $gris = [0.42, 0.45, 0.42];
    $y = $alto_pagina - 26;
    $c = pdf_linea(PDF_MARGEN, $ancho_pagina - PDF_MARGEN, $y - 10, [0.85, 0.87, 0.85], $alto_pagina);
    $c .= pdf_texto(PDF_MARGEN, $y, pdf_texto_plano($pie), 7.5, ['alto_pagina' => $alto_pagina, 'color' => $gris]);
    $hojas = pdf_texto_plano('Hoja ' . $hoja . ' de ' . $total);
    return $c . pdf_texto(
        $ancho_pagina - PDF_MARGEN - pdf_ancho_texto($hojas, 7.5),
        $y, $hojas, 7.5, ['alto_pagina' => $alto_pagina, 'color' => $gris],
    );
}

/** Deja cada celda como texto medible: sin nulos y con coma decimal. */
function pdf_celdas(array $filas): array
{
    $limpias = [];
    foreach ($filas as $fila) {
        $limpia = [];
        foreach (array_values((array) $fila) as $valor) {
            $texto = $valor === null ? '' : (string) $valor;
            if ($texto !== '' && is_numeric($texto)) {
                $texto = str_replace('.', ',', $texto);
            }
            $limpia[] = pdf_texto_plano($texto);
        }
        $limpias[] = $limpia;
    }
    return $limpias;
}

/* ============================================================
   Un listado impreso
   ============================================================ */

/**
 * Arma el PDF de una planilla: encabezado, tabla paginada y pie.
 *
 * @param array{titulo: string, subtitulo?: string, pie?: string, encabezados: list<string>,
 *              filas: list<array<int|string, scalar|null>>, apaisado?: bool} $o
 */
function pdf_listado(array $o): string
{
    $apaisado = $o['apaisado'] ?? true;
    $ancho_pagina = $apaisado ? PDF_A4_LARGO : PDF_A4_CORTO;
    $alto_pagina = $apaisado ? PDF_A4_CORTO : PDF_A4_LARGO;
    $util = $ancho_pagina - 2 * PDF_MARGEN;

    $encabezados = array_map('pdf_texto_plano', $o['encabezados']);
    $filas = pdf_celdas($o['filas']);
    $columnas = count($encabezados);

    $tamano = $columnas > 12 ? 6.5 : ($columnas > 8 ? 7.0 : 7.8);
    $alto_fila = $tamano + 6.0;
    [$anchos, $derecha] = pdf_columnas($encabezados, $filas, $util, $tamano);

    $arriba_tabla = 92.0;
    $por_pagina = (int) max(1, floor(($alto_pagina - $arriba_tabla - 34.0) / $alto_fila) - 1);
    $tandas = $filas === [] ? [[]] : array_chunk($filas, $por_pagina);
    $paginas = [];

    foreach ($tandas as $indice => $tanda) {
        $c = pdf_encabezado_hoja($o['titulo'], $o['subtitulo'] ?? '', $ancho_pagina, $alto_pagina);
        $c .= pdf_tabla_encabezado($encabezados, $anchos, $derecha, $arriba_tabla, $tamano, $alto_fila, $util, $alto_pagina);

        $y = $arriba_tabla + 3;
        foreach ($tanda as $numero_fila => $fila) {
            $c .= pdf_tabla_fila($fila, $encabezados, $anchos, $derecha, $y, $tamano, $alto_fila, $util, $alto_pagina, $numero_fila % 2 === 1);
            $y += $alto_fila;
        }
        if ($tanda === []) {
            $c .= pdf_texto(PDF_MARGEN + 4, $y + 12, pdf_texto_plano('No hay registros para exportar.'), 9, [
                'alto_pagina' => $alto_pagina, 'color' => [0.42, 0.45, 0.42],
            ]);
        }

        $c .= pdf_pie_hoja($o['pie'] ?? 'Gestión ganadera', $indice + 1, count($tandas), $ancho_pagina, $alto_pagina);
        $paginas[] = $c;
    }

    return pdf_documento($paginas, $ancho_pagina, $alto_pagina, $o['titulo']);
}

/** Banda verde con los títulos de columna. */
function pdf_tabla_encabezado(
    array $encabezados, array $anchos, array $derecha, float $y,
    float $tamano, float $alto_fila, float $util, float $alto_pagina,
): string {
    $c = pdf_rectangulo(PDF_MARGEN, $y - $alto_fila + 3, $util, $alto_fila, [0.082, 0.439, 0.227], $alto_pagina);
    $x = PDF_MARGEN;
    foreach ($encabezados as $col => $titulo) {
        $texto = pdf_recortar($titulo, $anchos[$col] - 8, $tamano, true);
        $posicion = $derecha[$col]
            ? $x + $anchos[$col] - 4 - pdf_ancho_texto($texto, $tamano, true)
            : $x + 4;
        $c .= pdf_texto($posicion, $y - 5, $texto, $tamano, [
            'negrita' => true, 'alto_pagina' => $alto_pagina, 'color' => [1, 1, 1],
        ]);
        $x += $anchos[$col];
    }
    return $c;
}

/** Una fila, con fondo alternado para no perder el renglón. */
function pdf_tabla_fila(
    array $fila, array $encabezados, array $anchos, array $derecha, float $y,
    float $tamano, float $alto_fila, float $util, float $alto_pagina, bool $sombreada,
): string {
    $c = $sombreada
        ? pdf_rectangulo(PDF_MARGEN, $y, $util, $alto_fila, [0.957, 0.969, 0.957], $alto_pagina)
        : '';
    $x = PDF_MARGEN;
    foreach ($encabezados as $col => $sin_usar) {
        $texto = pdf_recortar($fila[$col] ?? '', $anchos[$col] - 8, $tamano);
        $posicion = $derecha[$col]
            ? $x + $anchos[$col] - 4 - pdf_ancho_texto($texto, $tamano)
            : $x + 4;
        $c .= pdf_texto($posicion, $y + $alto_fila - 4, $texto, $tamano, ['alto_pagina' => $alto_pagina]);
        $x += $anchos[$col];
    }
    return $c;
}

/* ============================================================
   La ficha de un animal
   ============================================================ */

/**
 * Arma el PDF de una ficha: bloques que se van apilando y saltan de hoja solos.
 *
 * A diferencia de la planilla, acá no se sabe de antemano cuánto ocupa cada
 * cosa —una vaca puede tener dos partos o catorce—, así que se lleva un cursor
 * vertical y cada bloque pide el espacio que necesita antes de dibujarse.
 *
 * Bloques que entiende:
 * - `datos`: pares etiqueta/valor en tres columnas, para la identificación.
 * - `tabla`: encabezados y filas; si no entra, sigue en la hoja siguiente
 *   repitiendo la banda de títulos.
 * - `notas`: fecha, autor y el texto completo, envuelto en varias líneas.
 *
 * @param array{titulo: string, subtitulo?: string, pie?: string, bloques: list<array<string, mixed>>} $o
 */
function pdf_ficha(array $o): string
{
    // Vertical: una ficha se lee y se archiva como una hoja de oficio.
    $ancho_pagina = PDF_A4_CORTO;
    $alto_pagina = PDF_A4_LARGO;
    $util = $ancho_pagina - 2 * PDF_MARGEN;
    $limite = $alto_pagina - 46;
    $gris = [0.42, 0.45, 0.42];

    $paginas = [];
    $c = pdf_encabezado_hoja($o['titulo'], $o['subtitulo'] ?? '', $ancho_pagina, $alto_pagina);
    $y = 96.0;

    /** Cierra la hoja y empieza otra, sin repetir el subtítulo. */
    $saltar = static function () use (&$c, &$y, &$paginas, $o, $ancho_pagina, $alto_pagina): void {
        $paginas[] = $c;
        $c = pdf_encabezado_hoja($o['titulo'], 'Continuación', $ancho_pagina, $alto_pagina);
        $y = 96.0;
    };

    /** Pide lugar: si no queda, la hoja se cierra y sigue en la próxima. */
    $espacio = static function (float $necesario) use (&$y, $saltar, $limite): void {
        if ($y + $necesario > $limite) {
            $saltar();
        }
    };

    foreach ($o['bloques'] as $bloque) {
        $tipo = $bloque['tipo'] ?? 'datos';
        $filas = $tipo === 'tabla' ? pdf_celdas($bloque['filas'] ?? []) : [];

        // Un bloque no empieza al final de la hoja: el título iría solo.
        $espacio(46);
        $y += 12;
        $c .= pdf_texto(PDF_MARGEN, $y + 9, pdf_texto_plano((string) $bloque['titulo']), 10.5, [
            'negrita' => true, 'alto_pagina' => $alto_pagina, 'color' => [0.06, 0.14, 0.09],
        ]);
        if (($bloque['detalle'] ?? '') !== '') {
            $ancho_titulo = pdf_ancho_texto(pdf_texto_plano((string) $bloque['titulo']), 10.5, true);
            $c .= pdf_texto(PDF_MARGEN + $ancho_titulo + 8, $y + 9, pdf_texto_plano((string) $bloque['detalle']), 8, [
                'alto_pagina' => $alto_pagina, 'color' => $gris,
            ]);
        }
        $y += 14;
        $c .= pdf_linea(PDF_MARGEN, PDF_MARGEN + $util, $y, [0.82, 0.86, 0.82], $alto_pagina);
        $y += 6;

        if ($tipo === 'datos') {
            $por_fila = 3;
            $ancho_dato = $util / $por_fila;
            $posicion = 0;
            foreach ($bloque['pares'] as [$etiqueta, $valor]) {
                if ($posicion % $por_fila === 0) {
                    $espacio(30);
                }
                $x = PDF_MARGEN + ($posicion % $por_fila) * $ancho_dato;
                $c .= pdf_texto($x, $y + 8, pdf_texto_plano((string) $etiqueta), 7, [
                    'alto_pagina' => $alto_pagina, 'color' => $gris,
                ]);
                $texto = pdf_recortar(pdf_texto_plano((string) ($valor === '' ? '—' : $valor)), $ancho_dato - 8, 9.5);
                $c .= pdf_texto($x, $y + 20, $texto, 9.5, ['alto_pagina' => $alto_pagina]);
                $posicion++;
                if ($posicion % $por_fila === 0) {
                    $y += 28;
                }
            }
            if ($posicion % $por_fila !== 0) {
                $y += 28;
            }
            continue;
        }

        if ($tipo === 'tabla') {
            if ($filas === []) {
                $espacio(20);
                $c .= pdf_texto(PDF_MARGEN, $y + 12, pdf_texto_plano((string) ($bloque['vacio'] ?? 'Sin registros.')), 8.5, [
                    'alto_pagina' => $alto_pagina, 'color' => $gris,
                ]);
                $y += 20;
                continue;
            }

            $encabezados = array_map('pdf_texto_plano', $bloque['encabezados']);
            $tamano = count($encabezados) > 6 ? 7.0 : 7.8;
            $alto_fila = $tamano + 6.0;
            [$anchos, $derecha] = pdf_columnas($encabezados, $filas, $util, $tamano);

            $espacio($alto_fila * 3);
            $y += $alto_fila;
            $c .= pdf_tabla_encabezado($encabezados, $anchos, $derecha, $y, $tamano, $alto_fila, $util, $alto_pagina);
            $y += 3;

            foreach ($filas as $numero => $fila) {
                if ($y + $alto_fila > $limite) {
                    $saltar();
                    // La banda de títulos se repite: si no, la hoja siguiente
                    // sería una tabla de números sin saber de qué son.
                    $y += $alto_fila;
                    $c .= pdf_tabla_encabezado($encabezados, $anchos, $derecha, $y, $tamano, $alto_fila, $util, $alto_pagina);
                    $y += 3;
                }
                $c .= pdf_tabla_fila(
                    $fila, $encabezados, $anchos, $derecha, $y,
                    $tamano, $alto_fila, $util, $alto_pagina, $numero % 2 === 1,
                );
                $y += $alto_fila;
            }
            continue;
        }

        // Notas: el texto entero, no una celda recortada.
        foreach ($bloque['items'] as $item) {
            $lineas = pdf_envolver(pdf_texto_plano((string) $item['texto']), $util - 8, 8.5);
            $espacio(16 + count($lineas) * 11);
            $c .= pdf_texto(PDF_MARGEN, $y + 9, pdf_texto_plano((string) $item['encabezado']), 8, [
                'negrita' => true, 'alto_pagina' => $alto_pagina, 'color' => [0.06, 0.14, 0.09],
            ]);
            $y += 12;
            foreach ($lineas as $linea) {
                $c .= pdf_texto(PDF_MARGEN + 4, $y + 8, $linea, 8.5, ['alto_pagina' => $alto_pagina]);
                $y += 11;
            }
            $y += 4;
        }
        if ($bloque['items'] === []) {
            $c .= pdf_texto(PDF_MARGEN, $y + 12, pdf_texto_plano((string) ($bloque['vacio'] ?? 'Sin registros.')), 8.5, [
                'alto_pagina' => $alto_pagina, 'color' => $gris,
            ]);
            $y += 20;
        }
    }

    $paginas[] = $c;

    // El pie se agrega al final, cuando ya se sabe cuántas hojas quedaron.
    $total = count($paginas);
    foreach ($paginas as $indice => $contenido) {
        $paginas[$indice] = $contenido
            . pdf_pie_hoja($o['pie'] ?? 'Gestión ganadera', $indice + 1, $total, $ancho_pagina, $alto_pagina);
    }

    return pdf_documento($paginas, $ancho_pagina, $alto_pagina, $o['titulo']);
}

/** Manda el PDF al navegador como descarga. */
function pdf_descargar(string $pdf, string $archivo): never
{
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store');
    echo $pdf;
    exit;
}
