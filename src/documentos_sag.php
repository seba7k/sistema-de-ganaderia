<?php
declare(strict_types=1);

require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/operaciones.php';

/**
 * Documentos para el SAG a partir de una salida ya registrada.
 *
 *  · Borrador del FMA (Formulario de Movimiento Animal): todos los datos que
 *    pide el formulario, ordenados como en él, para transcribirlos o revisarlos
 *    antes de emitir el FMA oficial. No reemplaza al FMA: ese lo emite SIPEC Web
 *    o la oficina del SAG, con su número.
 *  · Planilla para SIPEC Web: una fila por DIIO con lo que pide la carga de un
 *    movimiento. El SAG cambia sus plantillas de carga masiva: antes de subirla,
 *    conviene compararla con la plantilla vigente que entrega SIPEC.
 */

const MOTIVOS_FMA = [
    'VENTA'    => 'Venta a otro productor',
    'FAENA'    => 'Envío a matadero (faena)',
    'TRASLADO' => 'Traslado entre predios',
];

/** Cabezas por categoría y sexo, como el resumen del FMA. */
function resumen_categorias(array $animales): array
{
    $resumen = [];
    foreach ($animales as $animal) {
        $clave = legible($animal['categoria']);
        $resumen[$clave] = ($resumen[$clave] ?? 0) + 1;
    }
    ksort($resumen);
    return $resumen;
}

function pdf_borrador_fma(array $salida): string
{
    $titular = fila('SELECT nombre, rut FROM organizaciones WHERE id = ?', [(int) $salida['organizacion_id']]);
    $resumen = resumen_categorias($salida['animales']);

    return pdf_ficha([
        'titulo' => 'Borrador de Formulario de Movimiento Animal (FMA)',
        'subtitulo' => (MOTIVOS_FMA[$salida['tipo']] ?? legible($salida['tipo'])) . ' · ' . fecha_corta($salida['fecha'])
            . ' · Salida N° ' . $salida['id'],
        'pie' => 'BORRADOR — no válido como FMA oficial. El FMA se emite en SIPEC Web o en la oficina del SAG.',
        'bloques' => [
            ['tipo' => 'datos', 'titulo' => 'Movimiento', 'pares' => [
                ['Fecha de movimiento', fecha_corta($salida['fecha'])],
                ['Motivo', MOTIVOS_FMA[$salida['tipo']] ?? legible($salida['tipo'])],
                ['Especie', 'Bovino'],
                ['Cantidad de animales', numero((int) $salida['cantidad_animales'])],
                ['Peso total', $salida['peso_total_kg'] !== null ? kg((float) $salida['peso_total_kg']) : '—'],
                ['N° FMA', '(lo asigna el SAG)'],
            ]],
            ['tipo' => 'datos', 'titulo' => 'Origen', 'pares' => [
                ['RUP', (string) $salida['origen_rup']],
                ['Predio', (string) $salida['origen_nombre']],
                ['Comuna', (string) ($salida['origen_comuna'] ?? '—')],
                ['Región', (string) ($salida['origen_region'] ?? '—')],
                ['Titular', (string) ($titular['nombre'] ?? '—')],
                ['RUT titular', (string) ($titular['rut'] ?? '—')],
            ]],
            ['tipo' => 'datos', 'titulo' => 'Destino', 'pares' => [
                ['RUP', (string) $salida['destino_rup']],
                ['Predio o planta', (string) ($salida['destino_nombre'] ?? '—')],
                ['Comuna', (string) ($salida['destino_comuna'] ?? '—')],
                ['Comprador', (string) ($salida['comprador'] ?? '—')],
            ]],
            ['tipo' => 'datos', 'titulo' => 'Transporte', 'pares' => [
                ['Transportista', (string) ($salida['transportista'] ?? '—')],
                ['RUT transportista', (string) ($salida['transportista_rut'] ?? '—')],
                ['Patente', (string) ($salida['patente'] ?? '—')],
            ]],
            ['tipo' => 'tabla', 'titulo' => 'Resumen por categoría',
                'encabezados' => ['Categoría', 'Cabezas'],
                'filas' => array_map(static fn (string $c, int $n): array => [$c, numero($n)], array_keys($resumen), $resumen),
            ],
            ['tipo' => 'tabla', 'titulo' => 'Animales', 'detalle' => numero(count($salida['animales'])) . ' DIIO',
                'encabezados' => ['#', 'DIIO', 'EID', 'Sexo', 'Categoría', 'Raza', 'Nacimiento'],
                'filas' => array_map(static fn (int $i, array $a): array => [
                    (string) ($i + 1), $a['diio'], $a['eid'] ?? '', legible($a['sexo']), legible($a['categoria']),
                    $a['raza'] ?? '', $a['fecha_nacimiento'] !== null ? fecha_corta($a['fecha_nacimiento']) : '',
                ], array_keys($salida['animales']), $salida['animales']),
            ],
            ['tipo' => 'notas', 'titulo' => 'Declaración', 'items' => [[
                'encabezado' => 'Bloqueo sanitario verificado',
                'texto' => 'Al registrar esta salida, el sistema verificó que ninguno de los animales tuviera un medicamento en período de resguardo a la fecha del movimiento. '
                    . 'Registrado por ' . ($salida['responsable'] !== '' ? $salida['responsable'] : '—') . ' el ' . fecha_corta(substr((string) $salida['creada_en'], 0, 10)) . '.'
                    . ($salida['observaciones'] !== null ? ' Observaciones: ' . $salida['observaciones'] : ''),
            ]]],
        ],
    ]);
}

/** Filas de la planilla de movimiento: una por DIIO. */
function filas_sipec(array $salida): array
{
    return array_map(static fn (array $a): array => [
        $a['diio'], 'BOVINO', $a['sexo'] === 'MACHO' ? 'M' : 'H', $a['categoria'], $a['raza'] ?? '',
        $a['fecha_nacimiento'] ?? '', $salida['origen_rup'], $salida['destino_rup'], $salida['fecha'],
        $salida['tipo'], $a['eid'] ?? '',
    ], $salida['animales']);
}

function xlsx_planilla_sipec(array $salida): string
{
    return xlsx_libro([
        [
            'nombre' => 'Movimiento',
            'encabezados' => ['DIIO', 'Especie', 'Sexo', 'Categoría', 'Raza', 'Fecha nacimiento', 'RUP origen',
                              'RUP destino', 'Fecha movimiento', 'Motivo', 'EID (RFID)'],
            'filas' => filas_sipec($salida),
        ],
        [
            'nombre' => 'Datos del FMA',
            'encabezados' => ['Dato', 'Valor'],
            'filas' => [
                ['Motivo', MOTIVOS_FMA[$salida['tipo']] ?? $salida['tipo']],
                ['Fecha de movimiento', $salida['fecha']],
                ['RUP origen', $salida['origen_rup']],
                ['Predio origen', $salida['origen_nombre']],
                ['RUP destino', $salida['destino_rup']],
                ['Destino', $salida['destino_nombre'] ?? ''],
                ['Comuna destino', $salida['destino_comuna'] ?? ''],
                ['Transportista', $salida['transportista'] ?? ''],
                ['RUT transportista', $salida['transportista_rut'] ?? ''],
                ['Patente', $salida['patente'] ?? ''],
                ['Cantidad de animales', (int) $salida['cantidad_animales']],
                ['Aviso', 'Compará las columnas con la plantilla de carga vigente de SIPEC Web antes de subirla.'],
            ],
        ],
    ], 'Movimiento SIPEC ' . $salida['id']);
}
