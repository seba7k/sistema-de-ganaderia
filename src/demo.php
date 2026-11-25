<?php
declare(strict_types=1);

/**
 * Datos de demostración.
 *
 * Genera un feedlot completo y coherente: hacienda con pesadas, entregas de
 * alimento de los últimos 30 días, tratamientos en los tres estados de retiro,
 * mortalidades y un lote ya vendido con su cierre económico.
 *
 * Las fechas son relativas al día en que se cargan. Para refrescarlas antes de
 * una presentación, borrar datos/ganaderia.sqlite: la próxima visita la recrea.
 */

// Las contraseñas se generan con el mismo hash que el ingreso.
require_once __DIR__ . '/auth.php';
// Los datos de cría usan la duración de la gestación para calcular los partos.
require_once __DIR__ . '/dominio/reproduccion.php';
// Las validaciones de ejemplo llevan la huella del MVA.
require_once __DIR__ . '/dominio/mva.php';

function cargar_datos_demo(PDO $pdo): void
{
    $hash = hash_password('demo1234');
    $hoy = new DateTimeImmutable('today');
    $f = static fn (DateTimeImmutable $fecha): string => $fecha->format('Y-m-d');

    /* ---------- Organizaciones ---------- */

    consultar('INSERT INTO organizaciones (nombre, rut) VALUES (?, ?)', ['Ganadera Los Robles', '76.543.210-K']);
    $org = (int) $pdo->lastInsertId();

    consultar('INSERT INTO organizaciones (nombre, rut) VALUES (?, ?)', ['Organización Demo', '77.111.222-3']);
    $org2 = (int) $pdo->lastInsertId();

    /* ---------- Usuarios ---------- */

    $usuarios = [
        ['admin@ganaderia.cl',    'Ada',    'Ruiz',    'ADMINISTRADOR', '+56 9 6123 4567'],
        ['vet@ganaderia.cl',      'Tomás',  'Belmar',  'VETERINARIO',   '+56 9 9456 7890'],
        ['operario@ganaderia.cl', 'Rosa',   'Curiñir', 'OPERARIO',      '+56 9 8345 6789'],
        ['tractor@ganaderia.cl',  'Manuel', 'Antileo', 'OPERARIO',      '+56 9 7234 5678'],
    ];

    $ids_usuario = [];
    foreach ($usuarios as [$email, $nombre, $apellido, $rol, $telefono]) {
        consultar(
            'INSERT INTO usuarios (email, password_hash, nombre, apellido, telefono) VALUES (?, ?, ?, ?, ?)',
            [$email, $hash, $nombre, $apellido, $telefono],
        );
        $id = (int) $pdo->lastInsertId();
        $ids_usuario[$email] = $id;
        consultar(
            'INSERT INTO membresias (organizacion_id, usuario_id, rol) VALUES (?, ?, ?)',
            [$org, $id, $rol],
        );
    }

    // El veterinario atiende también el otro campo: al entrar tiene que elegir cuál.
    consultar(
        'INSERT INTO membresias (organizacion_id, usuario_id, rol) VALUES (?, ?, ?)',
        [$org2, $ids_usuario['vet@ganaderia.cl'], 'VETERINARIO'],
    );
    consultar(
        'INSERT INTO membresias (organizacion_id, usuario_id, rol) VALUES (?, ?, ?)',
        [$org2, $ids_usuario['admin@ganaderia.cl'], 'ADMINISTRADOR'],
    );

    /* ---------- Establecimiento y corrales ---------- */

    consultar(
        'INSERT INTO establecimientos (organizacion_id, nombre, tipo, rup, comuna, region) VALUES (?, ?, ?, ?, ?, ?)',
        [$org, 'Feedlot Los Robles', 'FEEDLOT', '12.3.45.0001', 'Loncoche', 'La Araucanía'],
    );
    $est = (int) $pdo->lastInsertId();

    consultar(
        'INSERT INTO establecimientos (organizacion_id, nombre, tipo, rup, comuna, region) VALUES (?, ?, ?, ?, ?, ?)',
        [$org2, 'Campo Demo', 'CAMPO_PASTORIL', '12.3.46.0002', 'Villarrica', 'La Araucanía'],
    );

    $definicion_corrales = [
        ['Corral 1', 'CORRAL', 120, 42.0],
        ['Corral 2', 'CORRAL', 120, 42.0],
        ['Corral 3', 'CORRAL', 90,  32.0],
        ['Corral 4', 'CORRAL', 90,  32.0],
        ['Enfermería', 'ENFERMERIA', 20, 10.0],
        ['Potrero Norte', 'POTRERO', 60, null],
    ];
    $corrales = [];
    foreach ($definicion_corrales as [$nombre, $tipo, $capacidad, $comedero]) {
        consultar(
            'INSERT INTO corrales (organizacion_id, establecimiento_id, nombre, tipo, capacidad_maxima, metros_comedero)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$org, $est, $nombre, $tipo, $capacidad, $comedero],
        );
        $corrales[$nombre] = (int) $pdo->lastInsertId();
    }

    /* ---------- Lotes ---------- */

    $definicion_lotes = [
        ['L-2026-01', 'Novillos Angus abril', 96, 118, 235.0, 'Feria Temuco', 'EN_ENGORDA'],
        ['L-2026-02', 'Vaquillas Hereford mayo', 62, 74, 208.0, 'Estancia El Roble', 'EN_ENGORDA'],
        ['L-2025-11', 'Novillos noviembre', 210, 48, 262.0, 'Feria Osorno', 'CERRADO'],
    ];
    $lotes = [];
    foreach ($definicion_lotes as [$codigo, $nombre, $dias_atras, $cabezas, $peso_prom, $proveedor, $estado]) {
        $ingreso = $hoy->modify("-{$dias_atras} days");
        $peso_total = $cabezas * $peso_prom;
        // Precio de compra por kilo vivo, típico de feria.
        $costo = $peso_total * 2150;

        consultar(
            'INSERT INTO lotes (organizacion_id, establecimiento_id, codigo, nombre, origen, proveedor,
                                fecha_ingreso, fecha_cierre, estado, cabezas_iniciales, cabezas_actuales,
                                peso_ingreso_total_kg, costo_adquisicion_total, peso_objetivo_kg)
             VALUES (?, ?, ?, ?, \'COMPRA\', ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $org, $est, $codigo, $nombre, $proveedor, $f($ingreso),
                $estado === 'CERRADO' ? $f($hoy->modify('-14 days')) : null,
                $estado, $cabezas, $estado === 'CERRADO' ? 0 : $cabezas,
                $peso_total, $costo, 480.0,
            ],
        );
        $lotes[$codigo] = ['id' => (int) $pdo->lastInsertId(), 'ingreso' => $ingreso, 'peso' => $peso_prom];
    }

    /* ---------- Hacienda ---------- */

    mt_srand(2026); // misma demo en cada corrida

    $reparto = [
        ['L-2026-01', 'Corral 1', 60, 'NOVILLO', 'MACHO_CASTRADO', 'Angus'],
        ['L-2026-01', 'Corral 2', 55, 'NOVILLO', 'MACHO_CASTRADO', 'Angus'],
        ['L-2026-02', 'Corral 3', 71, 'VAQUILLA', 'HEMBRA', 'Hereford'],
        ['L-2026-02', 'Enfermería', 3, 'VAQUILLA', 'HEMBRA', 'Hereford'],
    ];

    $numero = 1;
    $animales_por_corral = [];

    foreach ($reparto as [$codigo_lote, $corral, $cantidad, $categoria, $sexo, $raza]) {
        $lote = $lotes[$codigo_lote];
        for ($i = 0; $i < $cantidad; $i++) {
            $caravana = sprintf('A-%04d', $numero);
            $eid = '152' . str_pad((string) (100000000000 + $numero * 7919), 12, '0', STR_PAD_LEFT);
            $peso_ingreso = $lote['peso'] + mt_rand(-25, 25);

            $dias = (int) $lote['ingreso']->diff($hoy)->format('%a');
            // Ganancia diaria realista de feedlot, con variación individual.
            $adg = 1.25 + mt_rand(-35, 35) / 100;
            $peso_actual = round($peso_ingreso + $adg * $dias, 1);

            consultar(
                'INSERT INTO animales (organizacion_id, establecimiento_id, corral_actual_id, lote_actual_id,
                                       caravana_visual, eid, sexo, categoria, raza, fecha_ingreso,
                                       peso_ingreso_kg, peso_actual_kg, fecha_ultima_pesada)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $org, $est, $corrales[$corral], $lote['id'], $caravana, $eid, $sexo, $categoria, $raza,
                    $f($lote['ingreso']), $peso_ingreso, $peso_actual, $f($hoy->modify('-4 days')),
                ],
            );
            $id_animal = (int) $pdo->lastInsertId();
            $animales_por_corral[$corral][] = $id_animal;

            // Pesada de ingreso y última de control.
            consultar(
                'INSERT INTO pesadas (organizacion_id, animal_id, fecha, peso_kg, motivo) VALUES (?, ?, ?, ?, \'INGRESO\')',
                [$org, $id_animal, $f($lote['ingreso']), $peso_ingreso],
            );
            consultar(
                'INSERT INTO pesadas (organizacion_id, animal_id, fecha, peso_kg, motivo) VALUES (?, ?, ?, ?, \'CONTROL\')',
                [$org, $id_animal, $f($hoy->modify('-4 days')), $peso_actual],
            );
            $numero++;
        }
    }

    /* Las vaquillas de enfermería salieron del Corral 3 con síntomas
       respiratorios: el traslado queda en el historial de cada una. */
    consultar(
        'INSERT INTO movimientos_hacienda (organizacion_id, tipo, fecha, corral_origen_id, corral_destino_id,
                                           cantidad_animales, motivo, responsable_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $org, 'TRASLADO_INTERNO', $f($hoy->modify('-10 days')), $corrales['Corral 3'], $corrales['Enfermería'],
            count($animales_por_corral['Enfermería']), 'Apartadas con síntomas respiratorios',
            $ids_usuario['operario@ganaderia.cl'],
        ],
    );
    $movimiento = (int) $pdo->lastInsertId();
    foreach ($animales_por_corral['Enfermería'] as $id_animal) {
        consultar(
            'INSERT INTO detalles_movimiento (organizacion_id, movimiento_id, animal_id) VALUES (?, ?, ?)',
            [$org, $movimiento, $id_animal],
        );
    }

    /* Un pesaje que no cuadra: el novillo pesó menos que en el control
       anterior. Es el caso que el sistema tiene que marcar para revisar. */
    $id_revisar = $animales_por_corral['Corral 2'][4];
    $peso_previo = (float) valor('SELECT peso_actual_kg FROM animales WHERE id = ?', [$id_revisar]);
    consultar(
        "INSERT INTO pesadas (organizacion_id, animal_id, fecha, peso_kg, motivo) VALUES (?, ?, ?, ?, 'CONTROL')",
        [$org, $id_revisar, $f($hoy->modify('-1 days')), $peso_previo - 14],
    );
    consultar(
        'UPDATE animales SET peso_actual_kg = ?, fecha_ultima_pesada = ? WHERE id = ?',
        [$peso_previo - 14, $f($hoy->modify('-1 days')), $id_revisar],
    );

    /* ---------- Insumos ---------- */

    $definicion_insumos = [
        ['Maíz partido',          'ALIMENTO',    'KILOGRAMO', 18500, 5000, 285,   null, null, 88.0],
        ['Afrechillo de trigo',   'ALIMENTO',    'KILOGRAMO', 4200,  4000, 245,   null, null, 87.5],
        ['Silo de maíz',          'ALIMENTO',    'KILOGRAMO', 62000, 15000, 62,   null, null, 34.0],
        ['Núcleo proteico 38%',   'ALIMENTO',    'KILOGRAMO', 1850,  2000, 690,   null, null, 90.0],
        ['Heno de pradera',       'ALIMENTO',    'KILOGRAMO', 9400,  3000, 155,   null, null, 86.0],
        ['Oxitetraciclina LA',    'VETERINARIO', 'MILILITRO', 2400,  500,  38,    28,   'Oxitetraciclina', null],
        ['Ivermectina 1%',        'VETERINARIO', 'MILILITRO', 1750,  400,  24,    35,   'Ivermectina',     null],
        ['Vacuna clostridial',    'VETERINARIO', 'DOSIS',     180,   200,  1450,  21,   'Toxoide',         null],
        ['Complejo vitamínico ADE','VETERINARIO','MILILITRO', 3100,  600,  19,    0,    'Vitaminas A-D-E', null],
    ];
    $insumos = [];
    $datos_insumo = [];
    foreach ($definicion_insumos as [$nombre, $tipo, $unidad, $stock, $minimo, $costo, $carencia, $principio, $ms]) {
        consultar(
            'INSERT INTO insumos (organizacion_id, establecimiento_id, nombre, tipo, unidad, stock_actual,
                                  stock_minimo, costo_unitario, dias_carencia_carne, principio_activo, materia_seca_porcentaje)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$org, $est, $nombre, $tipo, $unidad, $stock, $minimo, $costo, $carencia, $principio, $ms],
        );
        $insumos[$nombre] = (int) $pdo->lastInsertId();
        $datos_insumo[$nombre] = ['ms' => (float) ($ms ?? 100), 'costo' => (float) $costo];
    }

    /* ---------- Raciones ---------- */

    $definicion_raciones = [
        ['Recepción 60/40', 'RECEPCION',   52.0, 168],
        ['Crecimiento',     'CRECIMIENTO', 58.0, 192],
        ['Terminación',     'TERMINACION', 68.0, 214],
    ];
    $raciones = [];
    foreach ($definicion_raciones as [$nombre, $etapa, $ms, $costo]) {
        consultar(
            'INSERT INTO raciones (organizacion_id, nombre, etapa, materia_seca_porcentaje, costo_por_kg)
             VALUES (?, ?, ?, ?, ?)',
            [$org, $nombre, $etapa, $ms, $costo],
        );
        $raciones[$nombre] = ['id' => (int) $pdo->lastInsertId(), 'costo' => $costo, 'ms' => $ms];
    }

    /* Recetas: cada entrega nueva descuenta estos ingredientes de bodega. La
       materia seca y el costo de la ración salen de la mezcla. */
    $recetas = [
        'Recepción 60/40' => ['Heno de pradera' => 40, 'Silo de maíz' => 30, 'Maíz partido' => 20, 'Núcleo proteico 38%' => 10],
        'Crecimiento'     => ['Silo de maíz' => 45, 'Maíz partido' => 30, 'Afrechillo de trigo' => 15, 'Núcleo proteico 38%' => 10],
        'Terminación'     => ['Maíz partido' => 55, 'Silo de maíz' => 30, 'Afrechillo de trigo' => 8, 'Núcleo proteico 38%' => 7],
    ];
    foreach ($recetas as $nombre_racion => $ingredientes) {
        $ms_racion = 0.0;
        $costo_racion = 0.0;
        foreach ($ingredientes as $insumo => $porcentaje) {
            consultar(
                'INSERT INTO ingredientes_racion (organizacion_id, racion_id, insumo_id, porcentaje) VALUES (?, ?, ?, ?)',
                [$org, $raciones[$nombre_racion]['id'], $insumos[$insumo], $porcentaje],
            );
            $ms_racion += $porcentaje / 100 * $datos_insumo[$insumo]['ms'];
            $costo_racion += $porcentaje / 100 * $datos_insumo[$insumo]['costo'];
        }
        consultar(
            'UPDATE raciones SET materia_seca_porcentaje = ?, costo_por_kg = ? WHERE id = ?',
            [round($ms_racion, 1), round($costo_racion, 2), $raciones[$nombre_racion]['id']],
        );
        $raciones[$nombre_racion]['ms'] = round($ms_racion, 1);
        $raciones[$nombre_racion]['costo'] = round($costo_racion, 2);
    }

    /* ---------- Entregas de alimento: últimos 30 días, dos por día ---------- */

    $plan_corrales = [
        ['Corral 1', 'Terminación', 'L-2026-01', 60],
        ['Corral 2', 'Terminación', 'L-2026-01', 55],
        ['Corral 3', 'Crecimiento', 'L-2026-02', 71],
        ['Enfermería', 'Recepción 60/40', 'L-2026-02', 3],
    ];

    for ($dia = 30; $dia >= 0; $dia--) {
        $fecha = $f($hoy->modify("-{$dia} days"));
        foreach ($plan_corrales as [$corral, $racion, $codigo_lote, $cabezas]) {
            // Consumo diario ≈ 2,4% del peso vivo, repartido en dos entregas.
            $kg_dia = $cabezas * 11.5;
            foreach ([1, 2] as $entrega) {
                $planificado = round($kg_dia / 2, 1);
                $real = round($planificado + mt_rand(-25, 15), 1);
                consultar(
                    'INSERT INTO entregas_alimento (organizacion_id, corral_id, lote_id, racion_id, fecha, numero_entrega,
                                                    kg_planificados, kg_entregados, kg_materia_seca, costo_total, responsable_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $org, $corrales[$corral], $lotes[$codigo_lote]['id'], $raciones[$racion]['id'],
                        $fecha, $entrega, $planificado, $real, round($real * $raciones[$racion]['ms'] / 100, 1),
                        round($real * $raciones[$racion]['costo']), $ids_usuario['tractor@ganaderia.cl'],
                    ],
                );
            }
        }
    }

    /* ---------- Tratamientos: uno por cada estado de retiro ---------- */

    $definicion_tratamientos = [
        // [producto, días atrás, tipo, vía, corral, lote, dosis, diagnóstico, cabezas]
        ['Vacuna clostridial', 96, 'VACUNACION', 'SUBCUTANEA', 'Corral 1', 'L-2026-01', 2, null, 60],
        ['Ivermectina 1%',     34, 'DESPARASITACION', 'SUBCUTANEA', 'Corral 3', 'L-2026-02', 6, null, 71],
        ['Oxitetraciclina LA',  9, 'CURATIVO', 'INTRAMUSCULAR', 'Enfermería', 'L-2026-02', 20, 'Neumonía (complejo respiratorio bovino)', 3],
        ['Oxitetraciclina LA', 26, 'CURATIVO', 'INTRAMUSCULAR', 'Corral 2', 'L-2026-01', 20, 'Queratoconjuntivitis', 4],
    ];

    foreach ($definicion_tratamientos as [$producto, $dias_atras, $tipo, $via, $corral, $codigo_lote, $dosis, $diagnostico, $cabezas]) {
        $carencia = (int) valor('SELECT dias_carencia_carne FROM insumos WHERE id = ?', [$insumos[$producto]]);
        $costo_unitario = (float) valor('SELECT costo_unitario FROM insumos WHERE id = ?', [$insumos[$producto]]);

        consultar(
            'INSERT INTO tratamientos (organizacion_id, establecimiento_id, corral_id, lote_id, insumo_id,
                                       tipo, fecha, via, dosis_por_animal, cantidad_animales,
                                       dias_carencia_carne, diagnostico, responsable_id, costo_total)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $org, $est, $corrales[$corral], $lotes[$codigo_lote]['id'], $insumos[$producto],
                $tipo, $f($hoy->modify("-{$dias_atras} days")), $via, $dosis, $cabezas,
                $carencia, $diagnostico, $ids_usuario['vet@ganaderia.cl'],
                round($dosis * $cabezas * $costo_unitario),
            ],
        );
        $id_tratamiento = (int) $pdo->lastInsertId();

        foreach (array_slice($animales_por_corral[$corral] ?? [], 0, $cabezas) as $id_animal) {
            consultar(
                'INSERT INTO aplicaciones_tratamiento (organizacion_id, tratamiento_id, animal_id) VALUES (?, ?, ?)',
                [$org, $id_tratamiento, $id_animal],
            );
        }
    }

    /* ---------- Registro MVA del veterinario ----------
       Vigente por un año. Los dos tratamientos más antiguos quedan validados y
       los recientes pendientes, para que se vea la bandeja de validación. */

    consultar(
        'UPDATE membresias SET mva_rut = ?, mva_numero = ?, mva_vigente_hasta = ? WHERE organizacion_id = ? AND usuario_id = ?',
        ['12345678-5', 'MVA-08-1234', $f($hoy->modify('+1 year')), $org, $ids_usuario['vet@ganaderia.cl']],
    );
    foreach (filas(
        'SELECT * FROM tratamientos WHERE organizacion_id = ? ORDER BY fecha LIMIT 2',
        [$org],
    ) as $tratamiento) {
        $tratamiento['validado_por'] = $ids_usuario['vet@ganaderia.cl'];
        $tratamiento['validado_en'] = $tratamiento['fecha'] . ' 18:30:00';
        $tratamiento['validado_mva_rut'] = '12345678-5';
        $tratamiento['validado_mva_numero'] = 'MVA-08-1234';
        $animales = array_map('intval', array_column(filas(
            'SELECT animal_id FROM aplicaciones_tratamiento WHERE tratamiento_id = ?',
            [(int) $tratamiento['id']],
        ), 'animal_id'));
        consultar(
            'UPDATE tratamientos SET validado_por = ?, validado_en = ?, validado_mva_rut = ?, validado_mva_numero = ?, huella = ?
              WHERE id = ?',
            [
                $tratamiento['validado_por'], $tratamiento['validado_en'], $tratamiento['validado_mva_rut'],
                $tratamiento['validado_mva_numero'], huella_tratamiento($tratamiento, $animales), (int) $tratamiento['id'],
            ],
        );
    }

    /* ---------- Mortalidades y venta del lote cerrado ---------- */

    foreach ([['Corral 1', 22, 'Timpanismo'], ['Corral 3', 51, 'Neumonía']] as [$corral, $dias_atras, $causa]) {
        $id_animal = $animales_por_corral[$corral][count($animales_por_corral[$corral]) - 1];
        consultar(
            'INSERT INTO mortalidades (organizacion_id, animal_id, lote_id, fecha, causa_presunta)
             VALUES (?, ?, (SELECT lote_actual_id FROM animales WHERE id = ?), ?, ?)',
            [$org, $id_animal, $id_animal, $f($hoy->modify("-{$dias_atras} days")), $causa],
        );
        consultar("UPDATE animales SET estado = 'MUERTO' WHERE id = ?", [$id_animal]);
    }

    /* El lote de noviembre ya cerró su ciclo: se le cargan los costos de engorda
       agregados por semana (no entrega por entrega, que serían cientos de filas) y
       un par de tratamientos, para que su cierre económico sea realista. */
    $lote_cerrado = $lotes['L-2025-11'];
    $cabezas_cerrado = 48;

    for ($semana = 28; $semana >= 2; $semana--) {
        $fecha_semana = $f($hoy->modify('-' . ($semana * 7) . ' days'));
        // Consumo semanal del corral entero, a razón de ~11 kg por cabeza y día.
        $kg_semana = round($cabezas_cerrado * 11.2 * 7, 1);
        consultar(
            'INSERT INTO entregas_alimento (organizacion_id, corral_id, lote_id, racion_id, fecha, numero_entrega,
                                            kg_planificados, kg_entregados, kg_materia_seca, costo_total, responsable_id)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)',
            [
                $org, $corrales['Corral 4'], $lote_cerrado['id'], $raciones['Terminación']['id'],
                $fecha_semana, $kg_semana, $kg_semana, round($kg_semana * $raciones['Terminación']['ms'] / 100, 1),
                round($kg_semana * $raciones['Terminación']['costo']), $ids_usuario['tractor@ganaderia.cl'],
            ],
        );
    }

    foreach ([['Vacuna clostridial', 200, 2], ['Ivermectina 1%', 190, 6]] as [$producto, $dias_atras, $dosis]) {
        $carencia = (int) valor('SELECT dias_carencia_carne FROM insumos WHERE id = ?', [$insumos[$producto]]);
        $costo_unitario = (float) valor('SELECT costo_unitario FROM insumos WHERE id = ?', [$insumos[$producto]]);
        consultar(
            'INSERT INTO tratamientos (organizacion_id, establecimiento_id, corral_id, lote_id, insumo_id,
                                       tipo, fecha, via, dosis_por_animal, cantidad_animales,
                                       dias_carencia_carne, diagnostico, responsable_id, costo_total)
             VALUES (?, ?, ?, ?, ?, \'PREVENTIVO\', ?, \'SUBCUTANEA\', ?, ?, ?, NULL, ?, ?)',
            [
                $org, $est, $corrales['Corral 4'], $lote_cerrado['id'], $insumos[$producto],
                $f($hoy->modify("-{$dias_atras} days")), $dosis, $cabezas_cerrado, $carencia,
                $ids_usuario['vet@ganaderia.cl'], round($dosis * $cabezas_cerrado * $costo_unitario),
            ],
        );
    }
    consultar(
        'INSERT INTO ventas_hacienda (organizacion_id, lote_id, fecha, destino, comprador,
                                      cantidad_animales, peso_total_kg, precio_por_kg, monto_neto)
         VALUES (?, ?, ?, \'FRIGORIFICO\', ?, ?, ?, ?, ?)',
        [$org, $lote_cerrado['id'], $f($hoy->modify('-14 days')), 'Frigorífico Temuco', 46, 46 * 512.0, 2680, 46 * 512.0 * 2680 * 0.97],
    );
    consultar(
        'INSERT INTO mortalidades (organizacion_id, animal_id, lote_id, fecha, causa_presunta)
         SELECT ?, id, ?, ?, ? FROM animales WHERE organizacion_id = ? LIMIT 2',
        [$org, $lote_cerrado['id'], $f($hoy->modify('-120 days')), 'Muerte súbita', $org],
    );

    /* ---------- Rodeo de cría: genealogía, servicios y partos ----------
       El establecimiento es de ciclo completo: los terneros nacen acá, se
       recrían y terminan en engorda. Por eso las temporadas viejas dejan
       animales que hoy están en otra etapa del ciclo. */

    foreach ([['Potrero Cría', 120], ['Potrero Recría', 150]] as [$nombre_potrero, $capacidad_potrero]) {
        consultar(
            'INSERT INTO corrales (organizacion_id, establecimiento_id, nombre, tipo, capacidad_maxima)
             VALUES (?, ?, ?, ?, ?)',
            [$org, $est, $nombre_potrero, 'POTRERO', $capacidad_potrero],
        );
        $corrales[$nombre_potrero] = (int) $pdo->lastInsertId();
    }

    $alta_animal = static function (array $datos) use ($pdo, $org, $est): int {
        consultar(
            'INSERT INTO animales (organizacion_id, establecimiento_id, corral_actual_id, caravana_visual,
                                   numero_interno, eid, sexo, categoria, raza, madre_id, padre_id, pajuela_id,
                                   origen, etapa, fecha_nacimiento, fecha_destete, fecha_ingreso,
                                   peso_ingreso_kg, peso_actual_kg, fecha_ultima_pesada, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $org, $est, $datos['corral'], $datos['caravana'] ?? null, $datos['interno'] ?? null,
                $datos['eid'] ?? null, $datos['sexo'], $datos['categoria'], $datos['raza'],
                $datos['madre'] ?? null, $datos['padre'] ?? null, $datos['pajuela'] ?? null,
                $datos['origen'], $datos['etapa'], $datos['nacimiento'] ?? null, $datos['destete'] ?? null,
                $datos['ingreso'], $datos['peso_ingreso'], $datos['peso_actual'] ?? null,
                $datos['fecha_peso'] ?? null, $datos['estado'] ?? 'ACTIVO',
            ],
        );
        return (int) $pdo->lastInsertId();
    };

    // Toros del plantel, para la monta natural.
    $toros = [];
    foreach ([['T-01', 'Angus', 6, 920], ['T-02', 'Hereford', 4, 870]] as [$caravana, $raza, $anios, $peso]) {
        $toros[] = $alta_animal([
            'corral' => $corrales['Potrero Cría'], 'caravana' => $caravana, 'sexo' => 'MACHO',
            'categoria' => 'TORO', 'raza' => $raza, 'origen' => 'COMPRADO', 'etapa' => 'REPRODUCCION',
            'nacimiento' => $f($hoy->modify("-{$anios} years")), 'ingreso' => $f($hoy->modify('-3 years')),
            'peso_ingreso' => $peso - 120, 'peso_actual' => $peso, 'fecha_peso' => $f($hoy->modify('-20 days')),
        ]);
    }

    // Pajuelas de semen, para la inseminación.
    $pajuelas = [];
    foreach ([
        ['PJ-ANG-118', 'Rock Solid 118', 'Angus', 'Genética Sur', 25, 18500],
        ['PJ-HER-204', 'Domino 204', 'Hereford', 'Genética Sur', 18, 16200],
        ['PJ-LIM-077', 'Ulysse 077', 'Limousin', 'ABS Chile', 12, 24800],
    ] as [$codigo, $toro_donante, $raza_pajuela, $proveedor, $dosis, $costo_pajuela]) {
        consultar(
            'INSERT INTO pajuelas (organizacion_id, codigo, toro, raza, proveedor, dosis_disponibles, costo_unitario)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$org, $codigo, $toro_donante, $raza_pajuela, $proveedor, $dosis, $costo_pajuela],
        );
        $pajuelas[] = (int) $pdo->lastInsertId();
    }

    $numero_ternero = 1;
    for ($v = 1; $v <= 24; $v++) {
        $edad_vaca = mt_rand(4, 9);
        $raza_vaca = $v % 3 === 0 ? 'Hereford' : 'Angus';

        $vaca = $alta_animal([
            'corral' => $corrales['Potrero Cría'],
            'caravana' => sprintf('V-%03d', $v),
            'eid' => '152' . str_pad((string) (200000000000 + $v * 6131), 12, '0', STR_PAD_LEFT),
            'sexo' => 'HEMBRA', 'categoria' => 'VACA', 'raza' => $raza_vaca,
            'origen' => $v <= 6 ? 'NACIDO_EN_PREDIO' : 'COMPRADO', 'etapa' => 'REPRODUCCION',
            'nacimiento' => $f($hoy->modify("-{$edad_vaca} years")),
            'ingreso' => $f($hoy->modify('-' . min($edad_vaca, 5) . ' years')),
            'peso_ingreso' => mt_rand(380, 430), 'peso_actual' => mt_rand(440, 530),
            'fecha_peso' => $f($hoy->modify('-20 days')),
        ]);

        // Dos vacas arrastran problemas reproductivos tres años seguidos (ternero
        // débil, aborto, ternero débil): son las que el sistema tiene que avisar
        // al administrador para descarte.
        $problematica = $v >= 23;

        /* Las primeras cuatro vacas están por parir: su parto anterior fue hace
           casi un año. Las demás parieron hace unos cinco meses, tienen el
           ternero al pie y ya se volvieron a servir. Con fechas así, ninguna
           vaca aparece sirviéndose antes de su último parto. */
        $desfase = $v <= 4 ? 268 : 60;

        foreach ([3, 2, 1] as $temporada) {
            $fecha_servicio = $hoy->modify('-' . ($temporada * 365 + $desfase) . ' days');
            $fecha_parto = $fecha_servicio->modify('+' . DIAS_GESTACION . ' days');

            $por_inseminacion = $v % 2 === 0;
            $pajuela_usada = $por_inseminacion ? $pajuelas[$v % count($pajuelas)] : null;
            $toro_usado = $por_inseminacion ? null : $toros[$v % count($toros)];

            consultar(
                'INSERT INTO servicios (organizacion_id, vaca_id, tipo, fecha, toro_id, pajuela_id,
                                        resultado, fecha_diagnostico, responsable_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $org, $vaca, $por_inseminacion ? 'INSEMINACION' : 'MONTA_NATURAL', $f($fecha_servicio),
                    $toro_usado, $pajuela_usada, 'PRENADA', $f($fecha_servicio->modify('+60 days')),
                    $ids_usuario['vet@ganaderia.cl'],
                ],
            );
            $servicio = (int) $pdo->lastInsertId();

            // Qué pasó en el parto de esta temporada.
            $resultado = 'VIVO';
            $dificultad = 'NORMAL';
            $debil = 0;
            if ($problematica) {
                if ($temporada === 3) {
                    $dificultad = 'DISTOCIA';
                    $debil = 1;
                } elseif ($temporada === 2) {
                    $resultado = 'ABORTO';
                } else {
                    $debil = 1;
                }
            } elseif ($v === 7 && $temporada === 2) {
                $resultado = 'MUERTO';
            }

            $ternero = null;
            $peso_nacimiento = null;

            if ($resultado === 'VIVO') {
                $peso_nacimiento = mt_rand(32, 42);
                $dias_desde_parto = (int) $fecha_parto->diff($hoy)->format('%a');
                $macho = $numero_ternero % 2 === 0;
                $destetado = $dias_desde_parto > 210;
                $en_engorda = $dias_desde_parto > 600;

                $fecha_destete = $destetado ? $f($fecha_parto->modify('+210 days')) : null;
                $peso_destete = $destetado ? mt_rand(190, 240) : null;
                $peso_hoy = $en_engorda
                    ? mt_rand(380, 460)
                    : ($destetado ? $peso_destete + mt_rand(20, 90) : $peso_nacimiento + (int) round($dias_desde_parto * 0.85));

                $ternero = $alta_animal([
                    'corral' => $en_engorda
                        ? $corrales['Corral 4']
                        : ($destetado ? $corrales['Potrero Recría'] : $corrales['Potrero Cría']),
                    // Los terneros se identifican con el número interno del predio
                    // hasta que se les pone el DIIO oficial, cerca del destete.
                    'caravana' => $destetado ? sprintf('N-%04d', $numero_ternero) : null,
                    'interno' => sprintf('P-%04d', $numero_ternero),
                    'sexo' => $macho ? 'MACHO' : 'HEMBRA',
                    'categoria' => $en_engorda
                        ? ($macho ? 'NOVILLO' : 'VAQUILLA')
                        : ($macho ? 'TERNERO' : 'TERNERA'),
                    'raza' => $raza_vaca,
                    'madre' => $vaca,
                    'padre' => $toro_usado,
                    'pajuela' => $pajuela_usada,
                    'origen' => 'NACIDO_EN_PREDIO',
                    'etapa' => $en_engorda ? 'ENGORDA' : ($destetado ? 'RECRIA' : 'CRIA'),
                    'nacimiento' => $f($fecha_parto),
                    'destete' => $fecha_destete,
                    'ingreso' => $f($fecha_parto),
                    'peso_ingreso' => $peso_nacimiento,
                    'peso_actual' => $peso_hoy,
                    'fecha_peso' => $f($hoy->modify('-20 days')),
                ]);

                // Los hitos de peso que pidió el productor: nacimiento y destete.
                consultar(
                    'INSERT INTO pesadas (organizacion_id, animal_id, fecha, peso_kg, motivo) VALUES (?, ?, ?, ?, ?)',
                    [$org, $ternero, $f($fecha_parto), $peso_nacimiento, 'NACIMIENTO'],
                );
                if ($destetado) {
                    consultar(
                        'INSERT INTO pesadas (organizacion_id, animal_id, fecha, peso_kg, motivo) VALUES (?, ?, ?, ?, ?)',
                        [$org, $ternero, $fecha_destete, $peso_destete, 'DESTETE'],
                    );
                }
                $numero_ternero++;
            }

            consultar(
                'INSERT INTO partos (organizacion_id, vaca_id, servicio_id, fecha, resultado, dificultad,
                                     ternero_id, peso_nacimiento_kg, ternero_debil, observaciones)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $org, $vaca, $servicio, $f($fecha_parto), $resultado, $dificultad,
                    $ternero, $peso_nacimiento, $debil,
                    $debil === 1 ? 'Ternero débil, necesitó asistencia para mamar' : null,
                ],
            );
        }

        /* Temporada actual: la mayoría gestando, algunas vacías y una sin
           diagnosticar. Sin esto no habría próximos partos que mostrar. */
        /* Las primeras vacas quedan cerca de la fecha de parto a propósito: sin
           eso no habría avisos de parición que mostrar, que es justamente lo que
           el productor mira todos los días en temporada. */
        $dias_desde_servicio = $v <= 4 ? mt_rand(266, 281) : mt_rand(40, 110);
        $fecha_actual = $hoy->modify('-' . $dias_desde_servicio . ' days');
        $resultado_actual = 'PRENADA';
        if ($problematica || $v >= 20) {
            $resultado_actual = 'VACIA';
        } elseif ($v === 19) {
            $resultado_actual = 'PENDIENTE';
        }

        consultar(
            'INSERT INTO servicios (organizacion_id, vaca_id, tipo, fecha, toro_id, pajuela_id,
                                    resultado, fecha_diagnostico, responsable_id, observaciones)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $org, $vaca, $v % 2 === 0 ? 'INSEMINACION' : 'MONTA_NATURAL', $f($fecha_actual),
                $v % 2 === 0 ? null : $toros[$v % count($toros)],
                $v % 2 === 0 ? $pajuelas[$v % count($pajuelas)] : null,
                $resultado_actual,
                $resultado_actual === 'PENDIENTE' ? null : $f($fecha_actual->modify('+60 days')),
                $ids_usuario['vet@ganaderia.cl'],
                $resultado_actual === 'VACIA' ? 'Diagnóstico negativo' : null,
            ],
        );
    }

    /* ---------- Notas escritas a mano y secados ---------- */

    $notas_demo = [
        ['V-001', 6,  'Mastitis en el cuarto trasero izquierdo. La revisó el veterinario.'],
        ['V-007', 21, 'Cojera de la pata derecha, se recuperó sola en una semana.'],
        ['V-012', 40, 'Le costó que el ternero mamara los dos primeros días.'],
        ['A-0005', 12, 'Le costó entrar a la ración, comió poco la primera semana.'],
    ];
    foreach ($notas_demo as [$identificacion, $dias_atras, $texto]) {
        $id_anotado = valor(
            'SELECT id FROM animales WHERE organizacion_id = ? AND caravana_visual = ?',
            [$org, $identificacion],
        );
        if ($id_anotado === null) {
            continue;
        }
        consultar(
            'INSERT INTO notas_animal (organizacion_id, animal_id, fecha, texto, usuario_id, creado_en)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $org, (int) $id_anotado, $f($hoy->modify("-{$dias_atras} days")), $texto,
                $ids_usuario['vet@ganaderia.cl'], ahora(),
            ],
        );
    }

    // Vacas secadas: dejaron de ordeñarse para descansar antes del próximo parto.
    foreach ([['V-001', 30], ['V-002', 45], ['V-003', 60]] as [$identificacion, $dias_atras]) {
        consultar(
            'UPDATE animales SET fecha_secado = ? WHERE organizacion_id = ? AND caravana_visual = ?',
            [$f($hoy->modify("-{$dias_atras} days")), $org, $identificacion],
        );
    }

    /* ---------- Segundo predio del mismo propietario ----------
       El sistema muestra un predio a la vez: este sirve para ver el selector
       de la barra funcionando. */

    consultar(
        'INSERT INTO establecimientos (organizacion_id, nombre, tipo, rup, comuna, region) VALUES (?, ?, ?, ?, ?, ?)',
        [$org, 'Fundo El Sauce', 'CRIANZA', '12.3.47.0003', 'Gorbea', 'La Araucanía'],
    );
    $est_sauce = (int) $pdo->lastInsertId();

    consultar(
        'INSERT INTO corrales (organizacion_id, establecimiento_id, nombre, tipo, capacidad_maxima, metros_comedero)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$org, $est_sauce, 'Potrero El Sauce', 'POTRERO', 90, null],
    );
    $corral_sauce = (int) $pdo->lastInsertId();

    for ($s = 1; $s <= 18; $s++) {
        $nacimiento = $hoy->modify('-' . (600 + $s * 5) . ' days');
        $peso_sauce = mt_rand(255, 320);
        consultar(
            'INSERT INTO animales (organizacion_id, establecimiento_id, corral_actual_id, caravana_visual,
                                   sexo, categoria, raza, origen, etapa, fecha_nacimiento, fecha_destete,
                                   fecha_ingreso, peso_ingreso_kg, peso_actual_kg, fecha_ultima_pesada)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $org, $est_sauce, $corral_sauce, sprintf('S-%03d', $s),
                'HEMBRA', 'VAQUILLA', 'Angus', 'NACIDO_EN_PREDIO', 'RECRIA',
                $f($nacimiento), $f($nacimiento->modify('+210 days')),
                $f($nacimiento->modify('+210 days')), $peso_sauce - 60, $peso_sauce, $f($hoy->modify('-9 days')),
            ],
        );
    }

    // Las dosis usadas se descuentan del stock de pajuelas.
    consultar(
        'UPDATE pajuelas SET dosis_disponibles = ' . sql_no_negativo('dosis_disponibles - 4') . ' WHERE organizacion_id = ?',
        [$org],
    );
}
