<?php
declare(strict_types=1);

/**
 * Indicadores productivos: ganancia diaria (ADG) y conversión (FCR).
 *
 * Ambos se calculan sobre materia seca, no sobre kilos tal cual: un silo al 35%
 * de MS y un concentrado al 90% no son comparables.
 */

/** Ganancia diaria de peso vivo, en kg/día. Null si el período no lo permite. */
function calcular_adg(float $peso_inicial, float $peso_final, int $dias): ?float
{
    if ($dias <= 0) {
        return null;
    }
    return round(($peso_final - $peso_inicial) / $dias, 3);
}

/** Kilos de materia seca a partir de kilos tal cual. */
function kg_materia_seca(float $kg_tal_cual, float $materia_seca_porcentaje): float
{
    return round($kg_tal_cual * ($materia_seca_porcentaje / 100), 2);
}

/**
 * Conversión alimenticia: kg de materia seca por kg de peso vivo ganado.
 * Menor es mejor. Null si no hubo ganancia (el cociente no sería interpretable).
 */
function calcular_fcr(float $kg_materia_seca, float $kg_ganados): ?float
{
    if ($kg_ganados <= 0) {
        return null;
    }
    return round($kg_materia_seca / $kg_ganados, 2);
}

/**
 * Ganancia diaria sobre la cual un pesaje se considera anormal.
 *
 * Un novillo en terminación bien alimentado gana entre 1,2 y 1,8 kg por día. Más
 * de 2,5 sostenido no es biológico: casi siempre es un error de tipeo o de
 * identificación (se pesó otro animal).
 */
const GDP_MAXIMA_NORMAL = 2.5;

/**
 * Compara un pesaje con el anterior del mismo animal.
 *
 * Devuelve null si la variación es normal, o el motivo de la alerta. Se marca
 * cualquier pérdida de peso y cualquier ganancia imposible: las dos piden que
 * alguien confirme el dato antes de guardarlo.
 *
 * @return array{tipo: string, gdp: float|null, mensaje: string}|null
 */
function evaluar_variacion_peso(float $peso_anterior, string $fecha_anterior, float $peso, string $fecha): ?array
{
    $dias = dias_entre($fecha_anterior, $fecha);
    $diferencia = $peso - $peso_anterior;

    if ($dias <= 0) {
        // Dos pesajes el mismo día solo difieren por lo que comió o tomó.
        if (abs($diferencia) > 15) {
            return [
                'tipo'    => 'MISMO_DIA',
                'gdp'     => null,
                'mensaje' => 'Hay otro pesaje del mismo día con ' . decimal_es(abs($diferencia), 1) . ' kg de diferencia.',
            ];
        }
        return null;
    }

    $gdp = round($diferencia / $dias, 2);

    if ($diferencia < 0) {
        return [
            'tipo'    => 'NEGATIVA',
            'gdp'     => $gdp,
            'mensaje' => 'El animal perdió ' . decimal_es(abs($diferencia), 1) . ' kg en ' . $dias . ' día(s) (GDP ' . decimal_es($gdp, 2) . ' kg/día).',
        ];
    }
    if ($gdp > GDP_MAXIMA_NORMAL) {
        return [
            'tipo'    => 'EXCESIVA',
            'gdp'     => $gdp,
            'mensaje' => 'Ganancia de ' . decimal_es($gdp, 2) . ' kg/día desde el pesaje anterior: más de lo posible (' . decimal_es(GDP_MAXIMA_NORMAL, 1) . ').',
        ];
    }
    return null;
}

/** Número con coma decimal, como se escribe en Chile. */
function decimal_es(float $numero, int $decimales): string
{
    return number_format($numero, $decimales, ',', '.');
}

function dias_entre(string $desde, string $hasta): int
{
    $a = (new DateTimeImmutable($desde))->setTime(0, 0);
    $b = (new DateTimeImmutable($hasta))->setTime(0, 0);
    return (int) $a->diff($b)->format('%r%a');
}
