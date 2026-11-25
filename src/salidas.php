<?php
declare(strict_types=1);

/**
 * Salida de animales del predio: venta, faena o traslado a otro predio.
 *
 * Es el momento en que el animal deja el campo, y por eso concentra dos reglas
 * del SAG:
 *
 *  · Bloqueo sanitario: un animal con un medicamento en período de resguardo
 *    (carencia) no sale. Se mira el retiro el día de la salida, cuente o no con
 *    la validación del MVA: el residuo corre desde la aplicación, no desde la firma.
 *  · Respaldo del FMA: origen y destino con su RUP, transporte y la lista de
 *    DIIO. Con eso se arma el borrador del formulario y la planilla para SIPEC.
 *
 * Si el RUP de destino es de otro predio de la misma organización, el animal
 * sigue activo y pasa a ese predio; si es de un tercero, egresa.
 */

const TIPOS_SALIDA = [
    'VENTA'    => 'Venta',
    'FAENA'    => 'Faena',
    'TRASLADO' => 'Traslado a otro predio',
];

/** Estado que queda en el animal cuando sale hacia un tercero. */
const ESTADO_POR_SALIDA = [
    'VENTA'    => 'VENDIDO',
    'FAENA'    => 'FAENADO',
    'TRASLADO' => 'TRASLADADO',
];

/** La app manda una lista; la web, un texto separado por comas, espacios o líneas. */
function lista_identificaciones(mixed $valor): array
{
    $partes = is_array($valor)
        ? array_map(static fn ($x): string => is_scalar($x) ? (string) $x : '', $valor)
        : (preg_split('/[\s,;]+/', (string) $valor) ?: []);
    return array_values(array_unique(array_filter(array_map('trim', $partes), static fn (string $x): bool => $x !== '')));
}

/**
 * Animales en resguardo a la fecha indicada, con el texto que se muestra al
 * rechazar la salida. Vacío si todos pueden salir.
 *
 * @param array<int, array> $animales id => fila del animal
 * @return list<string>
 */
function bloqueos_sanitarios(int $org, array $animales, string $fecha): array
{
    $retiros = carencias_activas($org, null, $fecha, array_keys($animales));
    $bloqueos = [];
    foreach ($retiros as $id => $retiro) {
        $bloqueos[] = nombre_animal($animales[$id]) . ' (' . $retiro['producto'] . ', puede salir desde el '
            . fecha_corta($retiro['fecha_habilitacion']) . ')';
    }
    return $bloqueos;
}

function op_salida(int $org, int $usuario_id, array $d): array
{
    $errores = [];

    $tipo = strtoupper(dato_texto($d, 'tipo'));
    if (!isset(TIPOS_SALIDA[$tipo])) {
        $errores['tipo'] = 'Elegí si es venta, faena o traslado.';
    }

    $fecha = validar_fecha($d, 'fecha', 'La fecha', $errores);
    if ($fecha !== null && $fecha > hoy()) {
        $errores['fecha'] = 'La salida no puede tener fecha futura: se registra cuando los animales salen.';
    }

    /* ---------- Animales ---------- */
    $animales = [];
    $identificaciones = lista_identificaciones($d['identificaciones'] ?? '');
    if ($identificaciones === []) {
        $errores['identificaciones'] = 'Indicá los DIIO de los animales que salen.';
    } else {
        $no_encontrados = [];
        foreach ($identificaciones as $identificacion) {
            $sin_error = [];
            $animal = buscar_animal($org, ['identificacion' => $identificacion], $sin_error);
            if ($animal === null) {
                $no_encontrados[] = $identificacion;
            } else {
                $animales[(int) $animal['id']] = $animal;
            }
        }
        if ($no_encontrados !== []) {
            $errores['identificaciones'] = 'No corresponden a un animal activo: ' . implode(', ', $no_encontrados) . '.';
        }
    }

    // El FMA identifica a cada bovino por su DIIO: sin él, el animal no puede salir.
    $sin_diio = array_filter($animales, static fn (array $a): bool => trim((string) ($a['caravana_visual'] ?? '')) === '');
    if ($sin_diio !== [] && !isset($errores['identificaciones'])) {
        $errores['identificaciones'] = 'Estos animales no tienen DIIO y no pueden salir del predio sin él: '
            . implode(', ', array_map('nombre_animal', $sin_diio)) . '. Cargale la caravana oficial antes.';
    }

    // Un FMA tiene un solo predio de origen.
    $predios = array_values(array_unique(array_map(static fn (array $a): int => (int) $a['establecimiento_id'], $animales)));
    $origen = null;
    if (count($predios) > 1 && !isset($errores['identificaciones'])) {
        $errores['identificaciones'] = 'Los animales son de predios distintos. Cada salida (y cada FMA) es de un solo predio de origen.';
    } elseif (count($predios) === 1) {
        $origen = fila('SELECT * FROM establecimientos WHERE organizacion_id = ? AND id = ?', [$org, $predios[0]]);
        if ($origen !== null && trim((string) $origen['rup']) === '') {
            $errores['identificaciones'] = 'El predio ' . $origen['nombre'] . ' no tiene RUP cargado. Cargalo en Predios antes de registrar salidas.';
        }
    }

    /* ---------- Bloqueo sanitario ---------- */
    if ($fecha !== null && $animales !== [] && !isset($errores['identificaciones'])) {
        $bloqueos = bloqueos_sanitarios($org, $animales, $fecha);
        if ($bloqueos !== []) {
            $errores['identificaciones'] = (count($bloqueos) === 1 ? 'Bloqueado' : 'Bloqueados')
                . ' por período de resguardo de medicamentos: ' . implode('; ', $bloqueos)
                . '. Sacalos de la lista o esperá a que se cumpla el plazo.';
        }
    }

    /* ---------- Destino ---------- */
    $destino_rup = dato_texto($d, 'destino_rup') === '' ? null : normalizar_rup(dato_texto($d, 'destino_rup'));
    $destino_propio = null;
    if (dato_texto($d, 'destino_rup') === '') {
        $errores['destino_rup'] = 'Falta el RUP de destino.';
    } elseif ($destino_rup === null) {
        $errores['destino_rup'] = 'El RUP de destino tiene que tener 9 dígitos (por ejemplo 09.1.01.0003).';
    } elseif ($origen !== null && $destino_rup === $origen['rup']) {
        $errores['destino_rup'] = 'El destino es el mismo predio de origen.';
    } else {
        $destino_propio = fila('SELECT * FROM establecimientos WHERE organizacion_id = ? AND rup = ?', [$org, $destino_rup]);
        if ($destino_propio !== null && $tipo !== 'TRASLADO' && isset(TIPOS_SALIDA[$tipo])) {
            $errores['destino_rup'] = 'Ese RUP es de tu predio ' . $destino_propio['nombre'] . ': registralo como traslado.';
        }
    }

    /* ---------- Peso y precio (opcionales) ---------- */
    $peso = validar_numero($d, 'peso_total_kg', 'El peso total', 1, 1000000, $errores, false);
    $precio = validar_numero($d, 'precio_por_kg', 'El precio por kilo', 1, 1000000, $errores, false);
    $descuentos = validar_numero($d, 'descuentos', 'Los descuentos', 0, 1000000000, $errores, false) ?? 0.0;
    if ($precio !== null && $peso === null) {
        $errores['peso_total_kg'] = 'Con precio por kilo, falta el peso total para calcular el monto.';
    }

    $patente = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', dato_texto($d, 'patente')) ?? '');
    if ($patente !== '' && (strlen($patente) < 5 || strlen($patente) > 8)) {
        $errores['patente'] = 'La patente no parece válida (ej: ABCD12).';
    }

    if ($errores !== []) {
        throw new ErrorOperacion($errores);
    }

    $texto = static fn (string $campo): ?string => dato_texto($d, $campo) !== '' ? mb_substr(dato_texto($d, $campo), 0, 150) : null;
    $monto = ($peso !== null && $precio !== null) ? round($peso * $precio - $descuentos) : null;

    $id = en_transaccion(static function () use (
        $org, $usuario_id, $tipo, $fecha, $animales, $origen, $destino_rup, $destino_propio, $peso, $precio, $monto,
        $patente, $texto, $d,
    ): int {
        consultar(
            'INSERT INTO salidas (organizacion_id, establecimiento_id, tipo, fecha, destino_rup, destino_nombre, destino_comuna,
                                  destino_establecimiento_id, comprador, transportista, transportista_rut, patente,
                                  cantidad_animales, peso_total_kg, precio_por_kg, monto_neto, observaciones,
                                  responsable_id, creada_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $org, (int) $origen['id'], $tipo, $fecha, $destino_rup,
                $destino_propio['nombre'] ?? $texto('destino_nombre'),
                $destino_propio['comuna'] ?? $texto('destino_comuna'),
                $destino_propio !== null ? (int) $destino_propio['id'] : null,
                $texto('comprador'), $texto('transportista'), $texto('transportista_rut'),
                $patente !== '' ? $patente : null,
                count($animales), $peso, $precio, $monto,
                dato_texto($d, 'observaciones') !== '' ? mb_substr(dato_texto($d, 'observaciones'), 0, 500) : null,
                $usuario_id, ahora(),
            ],
        );
        $id = ultimo_id();

        $por_lote = [];
        foreach ($animales as $animal) {
            consultar(
                'INSERT INTO detalles_salida (organizacion_id, salida_id, animal_id, diio) VALUES (?, ?, ?, ?)',
                [$org, $id, (int) $animal['id'], (string) $animal['caravana_visual']],
            );
            if ($animal['lote_actual_id'] !== null) {
                $por_lote[(int) $animal['lote_actual_id']] = ($por_lote[(int) $animal['lote_actual_id']] ?? 0) + 1;
            }
            if ($destino_propio !== null) {
                // Traslado entre predios propios: sigue activo, en el otro campo, sin corral asignado.
                consultar(
                    'UPDATE animales SET establecimiento_id = ?, corral_actual_id = NULL, lote_actual_id = NULL
                      WHERE id = ? AND organizacion_id = ?',
                    [(int) $destino_propio['id'], (int) $animal['id'], $org],
                );
            } else {
                consultar(
                    'UPDATE animales SET estado = ?, fecha_egreso = ?, corral_actual_id = NULL
                      WHERE id = ? AND organizacion_id = ?',
                    [ESTADO_POR_SALIDA[$tipo], $fecha, (int) $animal['id'], $org],
                );
            }
        }

        /* Los lotes pierden esas cabezas. Si es una venta con precio, el ingreso se
           reparte entre los lotes según cuántos animales aportó cada uno: así el
           resultado económico del lote incluye esta venta. */
        foreach ($por_lote as $lote_id => $cabezas) {
            consultar(
                // $cabezas es un entero contado acá: va en el SQL porque la expresión se repite.
                'UPDATE lotes SET cabezas_actuales = ' . sql_no_negativo('cabezas_actuales - ' . (int) $cabezas) . ' WHERE id = ? AND organizacion_id = ?',
                [$lote_id, $org],
            );
            if ($tipo !== 'TRASLADO' && $monto !== null) {
                $parte = $cabezas / count($animales);
                consultar(
                    'INSERT INTO ventas_hacienda (organizacion_id, lote_id, fecha, destino, comprador,
                                                  cantidad_animales, peso_total_kg, precio_por_kg, monto_neto)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $org, $lote_id, $fecha, $tipo === 'FAENA' ? 'FRIGORIFICO' : 'OTRO_PRODUCTOR', $texto('comprador'),
                        $cabezas, round($peso * $parte, 1), $precio, round($monto * $parte),
                    ],
                );
            }
            if ((int) valor('SELECT cabezas_actuales FROM lotes WHERE id = ?', [$lote_id]) === 0) {
                consultar(
                    "UPDATE lotes SET estado = 'CERRADO', fecha_cierre = ? WHERE id = ? AND organizacion_id = ? AND estado <> 'CERRADO'",
                    [$fecha, $lote_id, $org],
                );
            }
        }

        consultar(
            'INSERT INTO movimientos_hacienda (organizacion_id, tipo, fecha, corral_origen_id, corral_destino_id,
                                               cantidad_animales, motivo, responsable_id)
             VALUES (?, ?, ?, NULL, NULL, ?, ?, ?)',
            [$org, 'EGRESO_' . $tipo, $fecha, count($animales), TIPOS_SALIDA[$tipo] . ' a RUP ' . $destino_rup, $usuario_id],
        );

        registrar_bitacora(
            $org, $usuario_id, 'SALIDA', 'salidas', $id,
            TIPOS_SALIDA[$tipo] . ' de ' . count($animales) . ' animales de ' . $origen['rup'] . ' a ' . $destino_rup
            . ': ' . implode(', ', array_map(static fn (array $a): string => (string) $a['caravana_visual'], $animales)),
        );
        return $id;
    });

    $cantidad = count($animales);
    return [
        'id' => $id,
        'mensaje' => TIPOS_SALIDA[$tipo] . ' registrada: ' . ($cantidad === 1 ? nombre_animal(array_values($animales)[0]) : numero($cantidad) . ' animales')
            . ' a RUP ' . $destino_rup
            . ($destino_propio !== null ? ' (' . $destino_propio['nombre'] . ', siguen activos allá)' : '')
            . '. Ya podés descargar el borrador del FMA y la planilla para SIPEC.',
    ];
}

/**
 * Una salida con todo lo que necesita el FMA: predio de origen, animales con
 * su categoría y sexo, y quién la registró.
 */
function salida_completa(int $org, int $id): ?array
{
    $salida = fila(
        "SELECT s.*, e.nombre AS origen_nombre, e.rup AS origen_rup, e.comuna AS origen_comuna, e.region AS origen_region,
                u.nombre AS responsable_nombre, u.apellido AS responsable_apellido
           FROM salidas s
           JOIN establecimientos e ON e.id = s.establecimiento_id
           LEFT JOIN usuarios u ON u.id = s.responsable_id
          WHERE s.organizacion_id = ? AND s.id = ?",
        [$org, $id],
    );
    if ($salida === null) {
        return null;
    }
    // El nombre se arma acá: concatenar en SQL se escribe distinto en MySQL y SQLite.
    $salida['responsable'] = trim(($salida['responsable_nombre'] ?? '') . ' ' . ($salida['responsable_apellido'] ?? ''));
    $salida['animales'] = filas(
        'SELECT d.diio, a.eid, a.sexo, a.categoria, a.raza, a.fecha_nacimiento, a.peso_actual_kg
           FROM detalles_salida d JOIN animales a ON a.id = d.animal_id
          WHERE d.organizacion_id = ? AND d.salida_id = ? ORDER BY d.diio',
        [$org, $id],
    );
    return $salida;
}
