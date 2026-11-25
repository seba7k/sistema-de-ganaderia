<?php
declare(strict_types=1);

/**
 * Cálculo automático de raciones.
 *
 * Cuánto alimento necesita cada animal sale de cuánta materia seca (MS) come
 * por día. El modelo es el de manejo habitual: el consumo de MS es un
 * porcentaje del peso vivo que depende de la categoría y sube con la ganancia
 * diaria de peso (un animal que gana más, come más):
 *
 *     % del peso vivo = base de la categoría + 0,3 × ganancia diaria (kg/día)
 *     kg MS/día       = peso × % / 100
 *     kg tal cual     = kg MS / (MS de la ración / 100)
 *
 * El resultado se acota entre 1,6 % y 3,2 % del peso vivo, que es el rango
 * real de consumo de bovinos de carne. Las bases están calibradas contra las
 * tablas del NRC para bovinos de carne (novillo de 400 kg ganando 1,3 kg/día
 * ≈ 9,6 kg MS/día). Es una estimación para planificar la entrega del día, no
 * reemplaza la formulación de un nutricionista: si la ración cambia de
 * energía, el consumo real también cambia, y por eso el sistema compara lo
 * planificado con lo entregado en «Cumplimiento».
 *
 * La ganancia diaria es la MEDIDA cuando el animal tiene dos pesajes separados
 * por al menos 14 días en los últimos 120; si no, la ESPERADA para su categoría.
 */

/** % del peso vivo que consume en MS, sin contar la ganancia. */
const CONSUMO_BASE_PV = [
    'TERNERO' => 2.4, 'TERNERA' => 2.4,
    'NOVILLO' => 2.0, 'VAQUILLA' => 2.1,
    'VACA'    => 1.9, 'TORO'    => 1.7, 'BUEY' => 1.9,
];

/** Ganancia diaria esperada (kg/día) cuando no hay pesajes para medirla. */
const GDP_ESPERADA = [
    'TERNERO' => 0.8, 'TERNERA' => 0.7,
    'NOVILLO' => 1.2, 'VAQUILLA' => 0.7,
    'VACA'    => 0.2, 'TORO'    => 0.3, 'BUEY' => 0.5,
];

const CONSUMO_PV_MINIMO = 1.6;
const CONSUMO_PV_MAXIMO = 3.2;
const AUMENTO_PV_POR_GDP = 0.3;

/**
 * Kilos de materia seca por día de un animal.
 *
 * @return array{porcentaje_pv: float, kg_ms: float}
 */
function consumo_materia_seca(float $peso_kg, string $categoria, float $gdp): array
{
    if ($peso_kg <= 0) {
        return ['porcentaje_pv' => 0.0, 'kg_ms' => 0.0];
    }
    // Una pérdida de peso no baja el consumo por debajo de la base: el animal
    // que pierde necesita, como mínimo, su mantención.
    $porcentaje = (CONSUMO_BASE_PV[$categoria] ?? 2.0) + AUMENTO_PV_POR_GDP * max($gdp, 0.0);
    $porcentaje = min(max($porcentaje, CONSUMO_PV_MINIMO), CONSUMO_PV_MAXIMO);
    return ['porcentaje_pv' => round($porcentaje, 2), 'kg_ms' => round($peso_kg * $porcentaje / 100, 2)];
}

/** Kilos tal cual (como salen del mixer) que llevan esos kilos de materia seca. */
function kg_tal_cual(float $kg_ms, float $materia_seca_porcentaje): float
{
    return $materia_seca_porcentaje > 0 ? round($kg_ms / ($materia_seca_porcentaje / 100), 1) : round($kg_ms, 1);
}

/**
 * Ganancia diaria medida de cada animal, a partir de sus pesajes.
 * Solo incluye a los que tienen dos pesajes separados por 14 días o más.
 *
 * @param list<int> $animal_ids
 * @return array<int, float> id => kg/día
 */
function gdp_medidas(int $org, array $animal_ids, ?string $hasta = null): array
{
    if ($animal_ids === []) {
        return [];
    }
    $hasta ??= hoy();
    $desde = (new DateTimeImmutable($hasta))->modify('-120 days')->format('Y-m-d');
    $ids = array_values(array_map('intval', $animal_ids));
    $pesajes = [];
    foreach (filas(
        'SELECT animal_id, fecha, peso_kg FROM pesadas
          WHERE organizacion_id = ? AND anulada = 0 AND fecha BETWEEN ? AND ?
            AND animal_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
          ORDER BY animal_id, fecha',
        array_merge([$org, $desde, $hasta . ' 23:59:59'], $ids),
    ) as $pesaje) {
        $pesajes[(int) $pesaje['animal_id']][] = $pesaje;
    }

    $gdp = [];
    foreach ($pesajes as $id => $lista) {
        $ultimo = end($lista);
        // El pesaje más antiguo que quede a 14 días o más del último.
        $anterior = null;
        foreach ($lista as $pesaje) {
            if (dias_entre(substr((string) $pesaje['fecha'], 0, 10), substr((string) $ultimo['fecha'], 0, 10)) >= 14) {
                $anterior = $pesaje;
                break;
            }
        }
        if ($anterior === null) {
            continue;
        }
        $valor = calcular_adg(
            (float) $anterior['peso_kg'], (float) $ultimo['peso_kg'],
            dias_entre(substr((string) $anterior['fecha'], 0, 10), substr((string) $ultimo['fecha'], 0, 10)),
        );
        // Un salto absurdo es un error de pesaje, no una ganancia: se descarta.
        if ($valor !== null && $valor > -1.0 && $valor < 3.0) {
            $gdp[$id] = $valor;
        }
    }
    return $gdp;
}

/**
 * Necesidad diaria de un grupo de animales.
 *
 * @param list<array> $animales filas de animales (peso_actual_kg, peso_ingreso_kg, categoria, id)
 * @return array{cabezas: int, peso_medio: float, gdp_media: float, con_gdp_medida: int, kg_ms_dia: float}
 */
function necesidad_grupo(int $org, array $animales): array
{
    $medidas = gdp_medidas($org, array_map(static fn (array $a): int => (int) $a['id'], $animales));
    $kg_ms = 0.0;
    $peso = 0.0;
    $gdp_total = 0.0;
    foreach ($animales as $animal) {
        $peso_animal = (float) ($animal['peso_actual_kg'] ?? 0) > 0 ? (float) $animal['peso_actual_kg'] : (float) $animal['peso_ingreso_kg'];
        $gdp = $medidas[(int) $animal['id']] ?? (GDP_ESPERADA[$animal['categoria']] ?? 0.5);
        $kg_ms += consumo_materia_seca($peso_animal, (string) $animal['categoria'], $gdp)['kg_ms'];
        $peso += $peso_animal;
        $gdp_total += $gdp;
    }
    $n = count($animales);
    return [
        'cabezas' => $n,
        'peso_medio' => $n > 0 ? round($peso / $n, 1) : 0.0,
        'gdp_media' => $n > 0 ? round($gdp_total / $n, 2) : 0.0,
        'con_gdp_medida' => count(array_intersect_key($medidas, array_flip(array_map(static fn (array $a): int => (int) $a['id'], $animales)))),
        'kg_ms_dia' => round($kg_ms, 1),
    ];
}

/** Ración que viene recibiendo el corral: la de su última entrega del último mes. */
function racion_habitual(int $org, int $corral_id): ?array
{
    return fila(
        'SELECT r.* FROM entregas_alimento e JOIN raciones r ON r.id = e.racion_id
          WHERE e.organizacion_id = ? AND e.corral_id = ? AND e.anulada = 0 AND e.fecha >= ?
          ORDER BY e.fecha DESC, e.id DESC LIMIT 1',
        [$org, $corral_id, hace_dias(30)],
    );
}

/** Cuántas entregas por día recibe el corral (la mayor de la última semana; 1 si no hay). */
function entregas_por_dia(int $org, int $corral_id): int
{
    return max(1, (int) valor(
        'SELECT MAX(numero_entrega) FROM entregas_alimento WHERE organizacion_id = ? AND corral_id = ? AND anulada = 0 AND fecha >= ?',
        [$org, $corral_id, hace_dias(7)],
    ));
}

/**
 * La ración calculada de un corral, completa: necesidad del día, kilos tal
 * cual por entrega y kilos de cada ingrediente con los días de bodega que
 * quedan a ese ritmo.
 *
 * @return array<string, mixed>|null null si el corral no tiene animales activos
 */
function racion_calculada_corral(int $org, int $corral_id, ?array $racion = null): ?array
{
    $animales = filas(
        "SELECT id, categoria, peso_actual_kg, peso_ingreso_kg FROM animales
          WHERE organizacion_id = ? AND corral_actual_id = ? AND estado = 'ACTIVO'",
        [$org, $corral_id],
    );
    if ($animales === []) {
        return null;
    }
    $necesidad = necesidad_grupo($org, $animales);
    $racion ??= racion_habitual($org, $corral_id);
    $entregas = entregas_por_dia($org, $corral_id);

    $resultado = $necesidad + [
        'corral_id' => $corral_id,
        'racion' => $racion,
        'entregas_por_dia' => $entregas,
        'kg_tal_cual_dia' => null,
        'kg_por_entrega' => null,
        'ingredientes' => [],
    ];
    if ($racion === null) {
        return $resultado;
    }

    $tal_cual = kg_tal_cual($necesidad['kg_ms_dia'], (float) $racion['materia_seca_porcentaje']);
    $resultado['kg_tal_cual_dia'] = $tal_cual;
    $resultado['kg_por_entrega'] = round($tal_cual / $entregas, 1);

    foreach (filas(
        'SELECT ir.porcentaje, i.id, i.nombre, i.stock_actual, i.stock_minimo FROM ingredientes_racion ir
           JOIN insumos i ON i.id = ir.insumo_id
          WHERE ir.organizacion_id = ? AND ir.racion_id = ? ORDER BY ir.porcentaje DESC',
        [$org, (int) $racion['id']],
    ) as $ingrediente) {
        $kg_dia = round($tal_cual * (float) $ingrediente['porcentaje'] / 100, 1);
        $resultado['ingredientes'][] = [
            'id' => (int) $ingrediente['id'],
            'nombre' => $ingrediente['nombre'],
            'porcentaje' => (float) $ingrediente['porcentaje'],
            'kg_dia' => $kg_dia,
            'stock' => (float) $ingrediente['stock_actual'],
            'stock_minimo' => $ingrediente['stock_minimo'] !== null ? (float) $ingrediente['stock_minimo'] : null,
            // Días de bodega que quedan si solo comiera este corral.
            'dias_cubiertos' => $kg_dia > 0 ? (int) floor((float) $ingrediente['stock_actual'] / $kg_dia) : null,
        ];
    }
    return $resultado;
}

/**
 * Kilos tal cual planificados para UNA entrega, según el destino.
 * Null si no hay datos para calcular (corral vacío, animal sin peso).
 */
function kg_planificados_calculados(int $org, ?array $corral, ?array $animal, array $racion): ?float
{
    if ($corral !== null) {
        $calculo = racion_calculada_corral($org, (int) $corral['id'], $racion);
        return $calculo['kg_por_entrega'] ?? null;
    }
    if ($animal !== null) {
        $necesidad = necesidad_grupo($org, [$animal]);
        return $necesidad['kg_ms_dia'] > 0 ? kg_tal_cual($necesidad['kg_ms_dia'], (float) $racion['materia_seca_porcentaje']) : null;
    }
    return null;
}
