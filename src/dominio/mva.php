<?php
declare(strict_types=1);

/**
 * Médico Veterinario Autorizado (MVA) ante el SAG.
 *
 * Cálculos puros, sin base de datos: el RUT con su dígito verificador, la
 * vigencia del registro y la huella de un tratamiento validado.
 */

/**
 * RUT normalizado como 12345678-K, o null si no es válido.
 *
 * Acepta puntos, espacios y la K en minúscula. El dígito verificador se calcula
 * con módulo 11: un RUT mal tipeado casi siempre falla esa cuenta.
 */
function normalizar_rut(string $rut): ?string
{
    $limpio = strtoupper(preg_replace('/[^0-9kK]/', '', $rut) ?? '');
    if (preg_match('/^(\d{7,8})([0-9K])$/', $limpio, $partes) !== 1) {
        return null;
    }
    return digito_verificador_rut($partes[1]) === $partes[2] ? $partes[1] . '-' . $partes[2] : null;
}

function digito_verificador_rut(string $cuerpo): string
{
    $suma = 0;
    $factor = 2;
    for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
        $suma += (int) $cuerpo[$i] * $factor;
        $factor = $factor === 7 ? 2 : $factor + 1;
    }
    $resto = 11 - ($suma % 11);
    return match ($resto) {
        11 => '0',
        10 => 'K',
        default => (string) $resto,
    };
}

/** 12345678-K → 12.345.678-K, para mostrar. */
function rut_legible(?string $rut): string
{
    if ($rut === null || !str_contains($rut, '-')) {
        return $rut ?? '—';
    }
    [$cuerpo, $dv] = explode('-', $rut, 2);
    return number_format((int) $cuerpo, 0, ',', '.') . '-' . $dv;
}

/**
 * ¿La membresía tiene un registro MVA vigente al día indicado?
 *
 * Hacen falta las tres cosas: RUT, número de registro y una vigencia que no haya
 * vencido. Sin fecha de vigencia no se da por vigente: el sistema no puede
 * suponer que una acreditación dura para siempre.
 *
 * @param array{mva_rut?: ?string, mva_numero?: ?string, mva_vigente_hasta?: ?string}|null $membresia
 */
function mva_vigente(?array $membresia, string $dia): bool
{
    return $membresia !== null
        && ($membresia['mva_rut'] ?? null) !== null
        && ($membresia['mva_numero'] ?? null) !== null
        && ($membresia['mva_vigente_hasta'] ?? null) !== null
        && substr((string) $membresia['mva_vigente_hasta'], 0, 10) >= $dia;
}

/**
 * Huella de un tratamiento validado: HMAC-SHA256 de todo lo que el MVA firmó,
 * con una clave que vive en el servidor y no en la base.
 *
 * Si alguien cambia esos datos directo en la base, la huella deja de coincidir
 * y la pantalla lo muestra. Sin la clave tampoco puede recalcularla: un SHA-256
 * común se podría rehacer con los mismos datos de la fila.
 *
 * @param array<string, mixed> $tratamiento fila de `tratamientos` con los datos de validación
 * @param list<int> $animales ids de los animales tratados
 */
function huella_tratamiento(array $tratamiento, array $animales, string $clave): string
{
    return hash_hmac('sha256', contenido_huella($tratamiento, $animales), $clave);
}

/**
 * Lo que cubre la huella, como texto: qué se aplicó, a quiénes, cuándo, la
 * carencia y los datos de la propia validación.
 *
 * Los números se escriben siempre con el mismo formato porque MySQL y SQLite no
 * devuelven igual un decimal (2 contra 2.0), y la huella tiene que dar lo mismo
 * en los dos motores. Un texto con bytes inválidos se reemplaza en vez de
 * romper la pantalla.
 */
function contenido_huella(array $tratamiento, array $animales): string
{
    $entero = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
    sort($animales);

    return json_encode([
        'id'                  => (int) $tratamiento['id'],
        'organizacion_id'     => (int) $tratamiento['organizacion_id'],
        'establecimiento_id'  => (int) $tratamiento['establecimiento_id'],
        'corral_id'           => $entero($tratamiento['corral_id']),
        'lote_id'             => $entero($tratamiento['lote_id']),
        'insumo_id'           => (int) $tratamiento['insumo_id'],
        'tipo'                => (string) $tratamiento['tipo'],
        'fecha'               => substr((string) $tratamiento['fecha'], 0, 10),
        'via'                 => (string) $tratamiento['via'],
        'dosis_por_animal'    => sprintf('%.4F', (float) $tratamiento['dosis_por_animal']),
        'cantidad_animales'   => (int) $tratamiento['cantidad_animales'],
        'dias_carencia_carne' => (int) $tratamiento['dias_carencia_carne'],
        'diagnostico'         => $tratamiento['diagnostico'] === null ? null : (string) $tratamiento['diagnostico'],
        'responsable_id'      => $entero($tratamiento['responsable_id']),
        'animales'            => array_map('intval', $animales),
        'validado_por'        => $entero($tratamiento['validado_por']),
        'validado_en'         => substr((string) $tratamiento['validado_en'], 0, 19),
        'validado_mva_rut'    => (string) $tratamiento['validado_mva_rut'],
        'validado_mva_numero' => (string) $tratamiento['validado_mva_numero'],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}
