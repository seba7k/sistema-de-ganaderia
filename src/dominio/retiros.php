<?php
declare(strict_types=1);

/**
 * Período de retiro (carencia) de medicamentos.
 *
 * Es la regla con más consecuencia legal del sistema: si un animal sale a faena
 * con el retiro vigente, el residuo del medicamento llega a la carne.
 *
 * Los estados describen el RETIRO, no la aptitud del animal:
 *  - VIGENTE    el retiro corre; el animal NO puede ir a faena
 *  - POR_VENCER termina dentro de los próximos días de preaviso
 *  - VENCIDO    el retiro se cumplió; el animal SÍ puede ir a faena
 */

const DIAS_PREAVISO = 3;

/**
 * @return array{estado: string, fecha_habilitacion: string, dias_restantes: int, apto: bool}
 */
function estado_retiro(
    string $fecha_tratamiento,
    int $dias_carencia,
    ?string $fecha_referencia = null,
    int $dias_preaviso = DIAS_PREAVISO,
): array {
    $tratamiento = new DateTimeImmutable($fecha_tratamiento);
    $referencia  = new DateTimeImmutable($fecha_referencia ?? 'today');

    $habilitacion = $tratamiento->modify("+{$dias_carencia} days");

    // Se compara a nivel de día de calendario: un tratamiento de las 23:00 y otro
    // de la 01:00 del día siguiente están a un día, no a dos horas.
    $restantes = (int) $referencia->setTime(0, 0)
        ->diff($habilitacion->setTime(0, 0))
        ->format('%r%a');

    if ($restantes <= 0) {
        $estado = 'VENCIDO';
    } elseif ($restantes <= $dias_preaviso) {
        $estado = 'POR_VENCER';
    } else {
        $estado = 'VIGENTE';
    }

    return [
        'estado'             => $estado,
        'fecha_habilitacion' => $habilitacion->format('Y-m-d'),
        'dias_restantes'     => $restantes,
        'apto'               => $estado === 'VENCIDO',
    ];
}

/** Etiqueta legible del estado, para mostrar en pantalla. */
function etiqueta_retiro(string $estado): string
{
    return match ($estado) {
        'VIGENTE'    => 'Retiro vigente',
        'POR_VENCER' => 'Por vencer',
        'VENCIDO'    => 'Apto',
        default      => $estado,
    };
}
