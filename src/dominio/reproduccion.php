<?php
declare(strict_types=1);

require_once __DIR__ . '/indicadores.php';

/**
 * Reglas de reproducción del rodeo de cría.
 *
 * Todas las funciones son puras: reciben datos y devuelven resultados, sin tocar
 * la base ni imprimir nada. Los valores de referencia son los del bovino de
 * carne y están acá arriba para poder ajustarlos a la realidad de cada predio.
 */

/** Gestación bovina. Varía unos días por raza; 283 es el promedio de uso corriente. */
const DIAS_GESTACION = 283;

/** Desde cuántos días antes del parto conviene tener la vaca vigilada. */
const DIAS_AVISO_PARTO = 21;

/** Edad habitual de destete, en meses. */
const MESES_DESTETE = 7;

/**
 * Intervalo entre partos que se considera aceptable, en días.
 *
 * El ideal productivo es un ternero por vaca por año (365). Por encima de 450 la
 * vaca está produciendo menos de lo que come.
 */
const IEP_ACEPTABLE = 450;

/** Años consecutivos con problemas reproductivos que disparan la alerta de descarte. */
const ANIOS_PARA_DESCARTE = 3;

/** Fecha probable de parto a partir del servicio. */
function fecha_probable_parto(string $fecha_servicio): string
{
    return (new DateTimeImmutable($fecha_servicio))
        ->modify('+' . DIAS_GESTACION . ' days')
        ->format('Y-m-d');
}

/**
 * Estado de una gestación en curso.
 *
 * @return array{estado: string, dias_gestacion: int, dias_para_parto: int, fecha_probable: string}
 */
function estado_gestacion(string $fecha_servicio, ?string $hoy = null): array
{
    $referencia = $hoy ?? date('Y-m-d');
    $probable = fecha_probable_parto($fecha_servicio);

    $transcurridos = dias_entre($fecha_servicio, $referencia);
    $faltan = dias_entre($referencia, $probable);

    if ($faltan < -15) {
        // Ya debería haber parido y no hay parto registrado.
        $estado = 'ATRASADA';
    } elseif ($faltan <= 0) {
        $estado = 'EN_FECHA';
    } elseif ($faltan <= DIAS_AVISO_PARTO) {
        $estado = 'POR_PARIR';
    } else {
        $estado = 'GESTANDO';
    }

    return [
        'estado'          => $estado,
        'dias_gestacion'  => $transcurridos,
        'dias_para_parto' => $faltan,
        'fecha_probable'  => $probable,
    ];
}

function etiqueta_gestacion(string $estado): string
{
    return match ($estado) {
        'GESTANDO'  => 'Gestando',
        'POR_PARIR' => 'Por parir',
        'EN_FECHA'  => 'En fecha de parto',
        'ATRASADA'  => 'Parto atrasado',
        default     => $estado,
    };
}

/** Edad en meses cumplidos. Null si no se conoce la fecha de nacimiento. */
function edad_meses(?string $fecha_nacimiento, ?string $hasta = null): ?int
{
    if ($fecha_nacimiento === null || $fecha_nacimiento === '') {
        return null;
    }
    $nacimiento = new DateTimeImmutable($fecha_nacimiento);
    $referencia = new DateTimeImmutable($hasta ?? 'today');
    if ($referencia < $nacimiento) {
        return 0;
    }
    $diferencia = $nacimiento->diff($referencia);
    return $diferencia->y * 12 + $diferencia->m;
}

/**
 * Intervalo entre partos (IEP) promedio, en días.
 *
 * Es el indicador que mejor resume la eficiencia de una vaca: cuánto tarda entre
 * un ternero y el siguiente. Necesita al menos dos partos para existir.
 *
 * @param string[] $fechas_de_parto
 */
function intervalo_entre_partos(array $fechas_de_parto): ?int
{
    if (count($fechas_de_parto) < 2) {
        return null;
    }

    $fechas = $fechas_de_parto;
    sort($fechas);

    $intervalos = [];
    for ($i = 1; $i < count($fechas); $i++) {
        $intervalos[] = dias_entre($fechas[$i - 1], $fechas[$i]);
    }

    return (int) round(array_sum($intervalos) / count($intervalos));
}

/**
 * Ganancia diaria del ternero entre el nacimiento y el destete.
 * Es la medida de cuánta leche y pasto convirtió en kilos su madre.
 */
function ganancia_predestete(float $peso_nacimiento, float $peso_destete, int $dias): ?float
{
    return calcular_adg($peso_nacimiento, $peso_destete, $dias);
}

/**
 * Evalúa si conviene sugerir el descarte de una vaca.
 *
 * La regla es la que fija la especificación: una vaca que acumula tres años
 * consecutivos con problemas reproductivos —abortos o terneros débiles— se
 * sugiere para la venta. Lo que importa no es cuántos problemas tuvo en total,
 * sino que se repitan temporada tras temporada.
 *
 * Otros datos que preocupan pero no bastan solos para descartar (un intervalo
 * entre partos largo, un ternero muerto aislado) se devuelven como observaciones:
 * se muestran al productor, pero no disparan la alerta.
 *
 * Devuelve los motivos en vez de un simple sí/no: la decisión la toma el
 * productor, y para eso necesita ver en qué se basa la sugerencia.
 *
 * @param array<int, array{fecha: string, resultado: string, dificultad?: string, ternero_debil?: bool}> $partos
 * @return array{descartar: bool, motivos: string[], observaciones: string[], anios_con_problemas: int, iep: int|null}
 */
function evaluar_descarte(
    array $partos,
    array $servicios = [],
    ?string $hoy = null,
    int $anios_limite = ANIOS_PARA_DESCARTE,
): array {
    $referencia = new DateTimeImmutable($hoy ?? 'today');
    $motivos = [];
    $observaciones = [];

    $problemas_por_anio = [];
    foreach ($partos as $parto) {
        $anio = (int) (new DateTimeImmutable($parto['fecha']))->format('Y');
        if ($parto['resultado'] === 'ABORTO') {
            $problemas_por_anio[$anio][] = 'aborto';
        } elseif (!empty($parto['ternero_debil'])) {
            $problemas_por_anio[$anio][] = 'ternero débil';
        }
    }

    $anios = array_keys($problemas_por_anio);
    sort($anios);

    // Racha más larga de años consecutivos con algún problema.
    $racha = 0;
    $mejor_racha = 0;
    $anterior = null;
    foreach ($anios as $anio) {
        $racha = ($anterior !== null && $anio === $anterior + 1) ? $racha + 1 : 1;
        $mejor_racha = max($mejor_racha, $racha);
        $anterior = $anio;
    }

    if ($mejor_racha >= $anios_limite) {
        $detalle = [];
        foreach ($problemas_por_anio as $anio => $lista) {
            $detalle[] = $anio . ': ' . implode(', ', array_unique($lista));
        }
        $motivos[] = $mejor_racha . ' años seguidos con problemas reproductivos (' . implode(' · ', $detalle) . ')';
    }

    $iep = intervalo_entre_partos(array_column(
        array_filter($partos, static fn (array $p): bool => $p['resultado'] === 'VIVO'),
        'fecha',
    ));
    if ($iep !== null && $iep > IEP_ACEPTABLE) {
        $observaciones[] = 'Tarda ' . $iep . ' días entre partos, cuando lo esperable es un ternero por año';
    }

    foreach ($partos as $parto) {
        if ($parto['resultado'] === 'MUERTO') {
            $observaciones[] = 'Ternero muerto al nacer en ' . substr($parto['fecha'], 0, 4);
        }
    }

    $ultimo_vivo = null;
    foreach ($partos as $parto) {
        if ($parto['resultado'] === 'VIVO' && ($ultimo_vivo === null || $parto['fecha'] > $ultimo_vivo)) {
            $ultimo_vivo = $parto['fecha'];
        }
    }
    if ($ultimo_vivo !== null && dias_entre($ultimo_vivo, $referencia->format('Y-m-d')) > 730) {
        $observaciones[] = 'No deja un ternero vivo desde ' . $ultimo_vivo;
    }

    return [
        'descartar'           => $motivos !== [],
        'motivos'             => $motivos,
        'observaciones'       => $observaciones,
        'anios_con_problemas' => $mejor_racha,
        'iep'                 => $iep,
    ];
}

/**
 * Indicadores del rodeo de cría.
 *
 * @return array{tasa_prenez: float|null, tasa_paricion: float|null, tasa_destete: float|null}
 */
function indicadores_rodeo(
    int $vacas_servidas,
    int $vacas_prenadas,
    int $terneros_nacidos_vivos,
    int $terneros_destetados,
): array {
    $porcentaje = static fn (int $parte, int $total): ?float
        => $total > 0 ? round($parte / $total * 100, 1) : null;

    return [
        // De las vacas servidas, cuántas quedaron preñadas.
        'tasa_prenez'   => $porcentaje($vacas_prenadas, $vacas_servidas),
        // De las preñadas, cuántas dejaron un ternero vivo.
        'tasa_paricion' => $porcentaje($terneros_nacidos_vivos, $vacas_prenadas),
        // De las servidas, cuántos terneros llegaron al destete: el número que
        // finalmente se vende.
        'tasa_destete'  => $porcentaje($terneros_destetados, $vacas_servidas),
    ];
}
