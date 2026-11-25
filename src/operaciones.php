<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/formato.php';
require_once __DIR__ . '/dominio/indicadores.php';
require_once __DIR__ . '/dominio/retiros.php';
require_once __DIR__ . '/dominio/reproduccion.php';
require_once __DIR__ . '/dominio/mva.php';
require_once __DIR__ . '/dominio/sag.php';
require_once __DIR__ . '/dominio/raciones.php';
require_once __DIR__ . '/salidas.php';

/**
 * Operaciones de terreno.
 *
 * Pesaje, parto, tratamiento, entrega de alimento, traslado y baja entran al
 * sistema por dos puertas: los formularios de la web y la sincronización de la
 * app de terreno. Las dos llaman a estas funciones, así que las reglas (el peso
 * anormal, el descuento de bodega, la capacidad del corral) valen igual venga el
 * dato de donde venga.
 *
 * Cada función recibe los datos como arreglo —el $_POST de la web o el JSON de
 * la app—, valida todo junto y, si algo no cuadra, lanza ErrorOperacion con los
 * errores por campo. Si todo está bien, guarda en una transacción y devuelve un
 * mensaje para mostrar.
 *
 * El permiso NO se revisa acá: lo verifica cada puerta antes de llamar, porque
 * cada una identifica a la persona de otra forma (sesión o token).
 */

final class ErrorOperacion extends RuntimeException
{
    /**
     * @param array<string, string> $errores campo => mensaje
     * @param bool $requiere_confirmacion el dato es posible pero raro: se guarda si la persona lo confirma
     */
    public function __construct(
        public readonly array $errores,
        public readonly bool $requiere_confirmacion = false,
    ) {
        parent::__construct(implode(' ', $errores));
    }
}

/** Ejecuta el trabajo en una transacción, o dentro de la que ya esté abierta. */
function en_transaccion(callable $trabajo): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $trabajo();
    }
    $pdo->beginTransaction();
    try {
        $resultado = $trabajo();
        $pdo->commit();
        return $resultado;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/* ============================================================
   Lectura de los datos recibidos
   ============================================================ */

function dato_texto(array $d, string $campo): string
{
    $valor = $d[$campo] ?? '';
    return is_scalar($valor) ? trim((string) $valor) : '';
}

function dato_decimal(array $d, string $campo): ?float
{
    $valor = str_replace(',', '.', dato_texto($d, $campo));
    return is_numeric($valor) ? (float) $valor : null;
}

function dato_entero(array $d, string $campo): ?int
{
    $valor = dato_texto($d, $campo);
    return preg_match('/^\d+$/', $valor) === 1 ? (int) $valor : null;
}

function dato_si(array $d, string $campo): bool
{
    $valor = $d[$campo] ?? false;
    if ($valor === true) {
        return true;
    }
    return is_scalar($valor) && in_array(mb_strtolower(trim((string) $valor)), ['1', 'true', 'si', 'sí', 'on'], true);
}

/** ¿Es una fecha real en formato AAAA-MM-DD? (rechaza el 31 de febrero) */
function fecha_valida(string $valor): bool
{
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return $fecha !== false && $fecha->format('Y-m-d') === $valor;
}

/** @param array<string, string> $errores */
function validar_fecha(array $d, string $campo, string $etiqueta, array &$errores): ?string
{
    $valor = dato_texto($d, $campo);
    if ($valor === '') {
        $errores[$campo] = $etiqueta . ' es obligatoria.';
        return null;
    }
    if (!fecha_valida($valor)) {
        $errores[$campo] = $etiqueta . ' no es una fecha válida.';
        return null;
    }
    if ($valor > hoy()) {
        $errores[$campo] = $etiqueta . ' no puede ser futura.';
        return null;
    }
    return $valor;
}

/** @param array<string, string> $errores */
function validar_numero(
    array $d,
    string $campo,
    string $etiqueta,
    float $minimo,
    ?float $maximo,
    array &$errores,
    bool $obligatorio = true,
): ?float {
    if (dato_texto($d, $campo) === '') {
        if ($obligatorio) {
            $errores[$campo] = $etiqueta . ' es obligatorio.';
        }
        return null;
    }
    $numero = dato_decimal($d, $campo);
    if ($numero === null) {
        $errores[$campo] = $etiqueta . ' tiene que ser un número.';
    } elseif ($numero < $minimo) {
        $errores[$campo] = $etiqueta . ' no puede ser menor que ' . numero($minimo, 1) . '.';
    } elseif ($maximo !== null && $numero > $maximo) {
        $errores[$campo] = $etiqueta . ' no puede superar ' . numero($maximo, 1) . '.';
    } else {
        return $numero;
    }
    return null;
}

/**
 * Busca un animal activo por su id (la app lo conoce por el catálogo) o por
 * cualquiera de sus identificaciones: DIIO, número interno o EID. El EID se
 * compara sin espacios, porque los lectores lo entregan de las dos formas.
 *
 * @param array<string, string> $errores
 */
function buscar_animal(
    int $org,
    array $d,
    array &$errores,
    string $campo = 'identificacion',
    string $campo_id = 'animal_id',
    string $condicion = '',
    string $mensaje = 'No hay ningún animal activo con esa identificación.',
): ?array {
    $consulta = "SELECT * FROM animales WHERE organizacion_id = ? AND estado = 'ACTIVO'" . $condicion;

    $id = dato_entero($d, $campo_id);
    if ($id !== null) {
        $animal = fila($consulta . ' AND id = ?', [$org, $id]);
    } else {
        $identificacion = dato_texto($d, $campo);
        if ($identificacion === '') {
            $errores[$campo] = 'Falta identificar el animal.';
            return null;
        }
        // El DIIO se compara tal cual y normalizado: los cargados antes de que
        // se normalizaran pueden tener espacios o minúsculas.
        $animal = fila(
            $consulta . ' AND (caravana_visual = ? OR caravana_visual = ? OR numero_interno = ? OR eid = ?) LIMIT 1',
            [$org, $identificacion, normalizar_diio($identificacion), $identificacion, str_replace(' ', '', $identificacion)],
        );
    }

    if ($animal === null) {
        $errores[$campo] = $mensaje;
    }
    return $animal;
}

function mensaje_formato_diio(): string
{
    return 'Ese DIIO no tiene el formato de las caravanas oficiales configurado para este sistema. '
        . 'Revisalo contra la caravana; si es un número propio del campo, cargalo como número interno.';
}

function nombre_animal(array $animal): string
{
    return (string) ($animal['caravana_visual'] ?? $animal['numero_interno'] ?? ('#' . $animal['id']));
}

/** El lote con más animales en el corral: a él se imputan los costos del corral. */
function lote_predominante(int $org, int $corral_id): ?int
{
    $lote = valor(
        "SELECT lote_actual_id FROM animales
          WHERE organizacion_id = ? AND corral_actual_id = ? AND estado = 'ACTIVO' AND lote_actual_id IS NOT NULL
          GROUP BY lote_actual_id ORDER BY COUNT(*) DESC LIMIT 1",
        [$org, $corral_id],
    );
    return $lote === null ? null : (int) $lote;
}

/**
 * Aviso de stock mínimo después de un consumo: lista los insumos usados que
 * quedaron en su mínimo o por debajo. Vacío si ninguno. Se agrega al mensaje de
 * la entrega o del tratamiento, para que quien confirmó lo vea en el momento y
 * no recién cuando abra el panel.
 *
 * @param list<int> $insumo_ids
 */
function aviso_stock_minimo(int $org, array $insumo_ids): string
{
    if ($insumo_ids === []) {
        return '';
    }
    $ids = array_values(array_unique(array_map('intval', $insumo_ids)));
    $bajos = filas(
        'SELECT nombre, unidad, stock_actual, stock_minimo FROM insumos
          WHERE organizacion_id = ? AND stock_minimo IS NOT NULL AND stock_actual <= stock_minimo
            AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY nombre',
        array_merge([$org], $ids),
    );
    if ($bajos === []) {
        return '';
    }
    return ' ⚠ Stock bajo: ' . implode('; ', array_map(
        static fn (array $i): string => $i['nombre'] . ' quedan ' . numero((float) $i['stock_actual'], 1) . ' '
            . (['KILOGRAMO' => 'kg', 'LITRO' => 'L', 'MILILITRO' => 'ml', 'DOSIS' => 'dosis', 'UNIDAD' => 'u'][$i['unidad']] ?? mb_strtolower((string) $i['unidad']))
            . ' (mínimo ' . numero((float) $i['stock_minimo'], 1) . ')',
        $bajos,
    )) . '. Hay que reponer.';
}

/** Descuenta un consumo de bodega y lo deja asentado en el libro de stock. */
function descontar_stock(int $org, int $usuario_id, array $insumo, float $cantidad, string $tipo, string $fecha, int $referencia_id): void
{
    consultar(
        'UPDATE insumos SET stock_actual = stock_actual - ? WHERE id = ? AND organizacion_id = ?',
        [$cantidad, (int) $insumo['id'], $org],
    );
    consultar(
        'INSERT INTO movimientos_stock (organizacion_id, insumo_id, tipo, fecha, cantidad,
                                        costo_unitario, costo_total, referencia_id, responsable_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $org, (int) $insumo['id'], $tipo, $fecha, -$cantidad,
            (float) $insumo['costo_unitario'], -round($cantidad * (float) $insumo['costo_unitario']),
            $referencia_id, $usuario_id,
        ],
    );
}

/* ============================================================
   Bitácora

   Crear un registro se explica solo: está el dato y su fecha. Cambiar o anular
   uno ya cargado, no: sin dejar rastro, nadie puede reconstruir después por qué
   un pesaje dejó de estar o por qué una vaca cambió de DIIO.
   ============================================================ */

// registrar_bitacora() vive en db.php: la usan también el ingreso y los bloqueos.

/* ============================================================
   Anulación de registros ya cargados

   Nada se borra: el registro queda marcado, con quién lo anuló y por qué. Lo que
   sí se deshace es su efecto —el peso del animal, el stock descontado, la baja
   del lote—, porque si no el sistema seguiría mostrando datos de algo que se
   dijo que no pasó.
   ============================================================ */

const ANULABLES = [
    'PESADA'      => ['tabla' => 'pesadas', 'nombre' => 'pesaje'],
    'ENTREGA'     => ['tabla' => 'entregas_alimento', 'nombre' => 'entrega de alimento'],
    // Un tratamiento firmado por un MVA solo lo anula otro MVA vigente.
    'TRATAMIENTO' => ['tabla' => 'tratamientos', 'nombre' => 'tratamiento', 'firma' => 'validado_en'],
    'BAJA'        => ['tabla' => 'mortalidades', 'nombre' => 'baja por muerte'],
    'NOTA'        => ['tabla' => 'notas_animal', 'nombre' => 'nota'],
];

function op_anular(int $org, int $usuario_id, array $d): array
{
    $errores = [];
    $tipo = strtoupper(dato_texto($d, 'tipo'));
    $id = dato_entero($d, 'registro_id');
    $motivo = dato_texto($d, 'motivo');

    if (!isset(ANULABLES[$tipo])) {
        $errores['tipo'] = 'Ese tipo de registro no se puede anular.';
    }
    if ($id === null) {
        $errores['registro_id'] = 'Falta indicar qué registro se anula.';
    }
    if ($motivo === '') {
        $errores['motivo'] = 'Escribí por qué se anula: queda en la bitácora.';
    } elseif (mb_strlen($motivo) > 255) {
        $errores['motivo'] = 'El motivo no puede pasar de 255 caracteres.';
    }
    if ($errores !== []) {
        throw new ErrorOperacion($errores);
    }

    $tabla = ANULABLES[$tipo]['tabla'];
    $registro = fila('SELECT * FROM ' . $tabla . ' WHERE organizacion_id = ? AND id = ?', [$org, $id]);
    if ($registro === null) {
        throw new ErrorOperacion(['registro_id' => 'Ese registro no existe en esta organización.']);
    }
    if ((int) $registro['anulada'] === 1) {
        throw new ErrorOperacion(['registro_id' => 'Ese registro ya estaba anulado.']);
    }
    /* Registros con firma (ANULABLES[...]['firma']): uno firmado solo lo anula
       un MVA vigente. Uno sin firma se anula con la condición de que siga sin
       firma en el UPDATE: si lo validan justo entre la lectura y este momento,
       ya no se encuentra y no se puede anular algo recién firmado. */
    $firma = ANULABLES[$tipo]['firma'] ?? null;
    $condicion = '';
    if ($firma !== null) {
        if ($registro[$firma] === null) {
            $condicion = ' AND ' . $firma . ' IS NULL';
        } elseif (!mva_vigente(membresia_mva($org, $usuario_id), hoy())) {
            throw new ErrorOperacion(['registro_id' => 'Este ' . ANULABLES[$tipo]['nombre'] . ' está validado por un MVA: solo un MVA con registro vigente puede anularlo.']);
        }
    }

    en_transaccion(static function () use ($org, $usuario_id, $tipo, $tabla, $id, $motivo, $registro, $condicion): void {
        $actualizada = consultar(
            'UPDATE ' . $tabla . ' SET anulada = 1, anulada_por = ?, anulada_en = ?, motivo_anulacion = ?
              WHERE id = ? AND organizacion_id = ? AND anulada = 0' . $condicion,
            [$usuario_id, ahora(), $motivo, $id, $org],
        );
        if ($actualizada->rowCount() !== 1) {
            throw new ErrorOperacion(['registro_id' => $condicion !== ''
                ? 'Ese registro ya estaba anulado, o lo acaban de validar: volvé a cargar la página.'
                : 'Ese registro ya estaba anulado.']);
        }

        match ($tipo) {
            'PESADA'      => recalcular_peso_animal($org, (int) $registro['animal_id']),
            'ENTREGA'     => revertir_consumo_bodega($org, $usuario_id, 'CONSUMO_ALIMENTACION', $id, 'Entrega de alimento anulada'),
            'TRATAMIENTO' => revertir_consumo_bodega($org, $usuario_id, 'CONSUMO_SANITARIO', $id, 'Tratamiento anulado'),
            'BAJA'        => revertir_baja($org, $registro),
            default       => null,
        };

        registrar_bitacora($org, $usuario_id, 'ANULA_' . $tipo, $tabla, $id, $motivo);
    });

    return [
        'id' => $id,
        'mensaje' => 'Se anuló el ' . ANULABLES[$tipo]['nombre'] . '. Queda registrado quién lo anuló y por qué.',
    ];
}

/** Tras anular un pesaje, el peso del animal vuelve a ser el del último válido. */
function recalcular_peso_animal(int $org, int $animal_id): void
{
    $ultima = fila(
        'SELECT fecha, peso_kg FROM pesadas
          WHERE organizacion_id = ? AND animal_id = ? AND anulada = 0
          ORDER BY fecha DESC, id DESC LIMIT 1',
        [$org, $animal_id],
    );
    consultar(
        'UPDATE animales SET peso_actual_kg = ?, fecha_ultima_pesada = ? WHERE id = ? AND organizacion_id = ?',
        [$ultima['peso_kg'] ?? null, $ultima['fecha'] ?? null, $animal_id, $org],
    );
}

/** Revierte las cantidades y los costos asentados al registrar el consumo. */
function revertir_consumo_bodega(int $org, int $usuario_id, string $tipo, int $referencia_id, string $observacion): void
{
    foreach (filas(
        'SELECT insumo_id, cantidad, costo_unitario FROM movimientos_stock
          WHERE organizacion_id = ? AND tipo = ? AND referencia_id = ? AND cantidad < 0
          ORDER BY insumo_id, id',
        [$org, $tipo, $referencia_id],
    ) as $movimiento) {
        devolver_a_bodega(
            $org, $usuario_id, (int) $movimiento['insumo_id'], (float) $movimiento['costo_unitario'],
            -(float) $movimiento['cantidad'], $referencia_id, $observacion,
        );
    }
}

function devolver_a_bodega(int $org, int $usuario_id, int $insumo_id, float $costo, float $cantidad, int $referencia_id, string $observacion): void
{
    consultar(
        'UPDATE insumos SET stock_actual = stock_actual + ? WHERE id = ? AND organizacion_id = ?',
        [$cantidad, $insumo_id, $org],
    );
    consultar(
        'INSERT INTO movimientos_stock (organizacion_id, insumo_id, tipo, fecha, cantidad,
                                        costo_unitario, costo_total, referencia_id, observaciones, responsable_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$org, $insumo_id, 'AJUSTE', hoy(), $cantidad, $costo, round($cantidad * $costo), $referencia_id, $observacion, $usuario_id],
    );
}

/** Una baja anulada devuelve el animal al inventario y la cabeza a su lote. */
function revertir_baja(int $org, array $mortalidad): void
{
    consultar(
        "UPDATE animales SET estado = 'ACTIVO', fecha_egreso = NULL WHERE id = ? AND organizacion_id = ?",
        [(int) $mortalidad['animal_id'], $org],
    );
    if ($mortalidad['lote_id'] !== null) {
        consultar(
            'UPDATE lotes SET cabezas_actuales = cabezas_actuales + 1 WHERE id = ? AND organizacion_id = ?',
            [(int) $mortalidad['lote_id'], $org],
        );
    }
}

/* ============================================================
   Corrección de la ficha del animal
   ============================================================ */

function op_editar_animal(int $org, int $usuario_id, array $d): array
{
    return en_transaccion(static function () use ($org, $usuario_id, $d): array {
        // Serializar las admisiones a corrales de la misma organización.
        consultar('UPDATE organizaciones SET id = id WHERE id = ?', [$org]);
        return guardar_editar_animal($org, $usuario_id, $d);
    });
}

function guardar_editar_animal(int $org, int $usuario_id, array $d): array
{
    $errores = [];
    $animal = fila('SELECT * FROM animales WHERE organizacion_id = ? AND id = ?', [$org, dato_entero($d, 'animal_id') ?? 0]);
    if ($animal === null) {
        throw new ErrorOperacion(['animal_id' => 'Ese animal no existe en esta organización.']);
    }

    $caravana = normalizar_diio(dato_texto($d, 'caravana_visual'));
    $nuevo = [
        'caravana_visual'  => $caravana !== '' ? $caravana : null,
        'numero_interno'   => dato_texto($d, 'numero_interno') !== '' ? dato_texto($d, 'numero_interno') : null,
        'eid'              => str_replace(' ', '', dato_texto($d, 'eid')) !== '' ? str_replace(' ', '', dato_texto($d, 'eid')) : null,
        'sexo'             => dato_texto($d, 'sexo'),
        'categoria'        => dato_texto($d, 'categoria'),
        'raza'             => dato_texto($d, 'raza') !== '' ? dato_texto($d, 'raza') : null,
        'etapa'            => dato_texto($d, 'etapa'),
        'origen'           => dato_texto($d, 'origen'),
        'fecha_nacimiento' => dato_texto($d, 'fecha_nacimiento') !== '' ? dato_texto($d, 'fecha_nacimiento') : null,
        'fecha_destete'    => dato_texto($d, 'fecha_destete') !== '' ? dato_texto($d, 'fecha_destete') : null,
        'fecha_secado'     => dato_texto($d, 'fecha_secado') !== '' ? dato_texto($d, 'fecha_secado') : null,
        'corral_actual_id' => dato_entero($d, 'corral_actual_id'),
    ];

    if ($nuevo['caravana_visual'] === null && $nuevo['numero_interno'] === null) {
        $errores['caravana_visual'] = 'El animal tiene que quedar con DIIO o con número interno.';
    }
    if ($nuevo['eid'] !== null && preg_match('/^\d{15}$/', $nuevo['eid']) !== 1) {
        $errores['eid'] = 'El EID del chip tiene 15 dígitos.';
    }
    /* Solo se exige el formato si el DIIO cambia: un animal cargado antes de
       configurar el formato tiene que poder corregirse en otros datos sin que
       su caravana, que es la que tiene puesta, lo bloquee. */
    if ($nuevo['caravana_visual'] !== null
        && $nuevo['caravana_visual'] !== normalizar_diio((string) $animal['caravana_visual'])
        && !diio_con_formato($nuevo['caravana_visual'], FORMATO_DIIO)) {
        $errores['caravana_visual'] = mensaje_formato_diio();
    }

    $validas = [
        'sexo'      => ['HEMBRA', 'MACHO', 'MACHO_CASTRADO'],
        'categoria' => ['TERNERO', 'TERNERA', 'NOVILLO', 'VAQUILLA', 'VACA', 'TORO', 'BUEY'],
        'origen'    => ['COMPRADO', 'NACIDO_EN_PREDIO'],
        'etapa'     => ['CRIA', 'RECRIA', 'ENGORDA', 'REPRODUCCION'],
    ];
    foreach ($validas as $campo => $opciones) {
        if (!in_array($nuevo[$campo], $opciones, true)) {
            $errores[$campo] = 'Ese valor no es válido.';
        }
    }

    foreach (['fecha_nacimiento' => 'La fecha de nacimiento', 'fecha_destete' => 'La fecha de destete', 'fecha_secado' => 'La fecha de secado'] as $campo => $etiqueta) {
        if ($nuevo[$campo] !== null) {
            $copia = [$campo => $nuevo[$campo]];
            validar_fecha($copia, $campo, $etiqueta, $errores);
        }
    }
    if ($nuevo['fecha_nacimiento'] !== null && $nuevo['fecha_destete'] !== null && $nuevo['fecha_destete'] < $nuevo['fecha_nacimiento']) {
        $errores['fecha_destete'] = 'El destete no puede ser anterior al nacimiento.';
    }

    // Las identificaciones se comparan contra las tres columnas, sin contarse a sí mismo.
    foreach (['caravana_visual', 'numero_interno', 'eid'] as $campo) {
        if ($nuevo[$campo] === null) {
            continue;
        }
        $ocupado = fila(
            'SELECT id, caravana_visual, numero_interno FROM animales
              WHERE organizacion_id = ? AND id != ?
                AND (caravana_visual = ? OR numero_interno = ? OR eid = ?) LIMIT 1',
            [$org, (int) $animal['id'], $nuevo[$campo], $nuevo[$campo], $nuevo[$campo]],
        );
        if ($ocupado !== null) {
            $errores[$campo] = '«' . $nuevo[$campo] . '» ya identifica al animal ' . nombre_animal($ocupado) . '.';
        }
    }

    if ($nuevo['corral_actual_id'] !== null) {
        $corral = fila(
            "SELECT c.nombre, c.capacidad_maxima,
                    (SELECT COUNT(*) FROM animales a WHERE a.corral_actual_id = c.id AND a.estado = 'ACTIVO' AND a.id != ?) AS ocupacion
               FROM corrales c WHERE c.organizacion_id = ? AND c.id = ?",
            [(int) $animal['id'], $org, $nuevo['corral_actual_id']],
        );
        if ($corral === null) {
            $errores['corral_actual_id'] = 'Ese corral no existe.';
        } elseif ((int) $corral['ocupacion'] >= (int) $corral['capacidad_maxima']
            && (int) $animal['corral_actual_id'] !== $nuevo['corral_actual_id']) {
            $errores['corral_actual_id'] = $corral['nombre'] . ' está completo.';
        }
    }

    if ($errores !== []) {
        throw new ErrorOperacion($errores);
    }

    // Solo se escribe lo que realmente cambió, y se deja dicho qué cambió.
    $cambios = [];
    foreach ($nuevo as $campo => $valor) {
        $antes = $animal[$campo] === null ? null : (string) $animal[$campo];
        $ahora_valor = $valor === null ? null : (string) $valor;
        if ($antes !== $ahora_valor) {
            $cambios[$campo] = ($antes ?? '—') . ' → ' . ($ahora_valor ?? '—');
        }
    }
    if ($cambios === []) {
        throw new ErrorOperacion(['caravana_visual' => 'No cambiaste ningún dato.']);
    }

    en_transaccion(static function () use ($org, $usuario_id, $animal, $nuevo, $cambios): void {
        consultar(
            'UPDATE animales SET caravana_visual = ?, numero_interno = ?, eid = ?, sexo = ?, categoria = ?,
                                 raza = ?, etapa = ?, origen = ?, fecha_nacimiento = ?, fecha_destete = ?,
                                 fecha_secado = ?, corral_actual_id = ?
              WHERE id = ? AND organizacion_id = ?',
            [
                $nuevo['caravana_visual'], $nuevo['numero_interno'], $nuevo['eid'], $nuevo['sexo'],
                $nuevo['categoria'], $nuevo['raza'], $nuevo['etapa'], $nuevo['origen'],
                $nuevo['fecha_nacimiento'], $nuevo['fecha_destete'], $nuevo['fecha_secado'],
                $nuevo['corral_actual_id'], (int) $animal['id'], $org,
            ],
        );
        $detalle = [];
        foreach ($cambios as $campo => $cambio) {
            $detalle[] = str_replace('_', ' ', $campo) . ': ' . $cambio;
        }
        registrar_bitacora($org, $usuario_id, 'EDITA_ANIMAL', 'animales', (int) $animal['id'], implode(' · ', $detalle));
    });

    return [
        'id' => (int) $animal['id'],
        'mensaje' => 'Ficha corregida: ' . implode(', ', array_map(
            static fn (string $campo): string => str_replace('_', ' ', $campo),
            array_keys($cambios),
        )) . '. El cambio quedó en la bitácora.',
    ];
}

/* ============================================================
   Alta de animal

   Una compra, o un animal que ya estaba en el campo y todavía no se había
   cargado. Los nacidos en el predio entran solos al registrar el parto.
   ============================================================ */

function op_alta_animal(int $org, int $usuario_id, array $d): array
{
    return en_transaccion(static function () use ($org, $usuario_id, $d): array {
        // Serializar las admisiones a corrales de la misma organización.
        consultar('UPDATE organizaciones SET id = id WHERE id = ?', [$org]);
        return guardar_alta_animal($org, $usuario_id, $d);
    });
}

function guardar_alta_animal(int $org, int $usuario_id, array $d): array
{
    $errores = [];

    $caravana = normalizar_diio(dato_texto($d, 'caravana_visual'));
    $interno = dato_texto($d, 'numero_interno');
    $eid = str_replace(' ', '', dato_texto($d, 'eid'));

    if ($caravana === '' && $interno === '') {
        $errores['caravana_visual'] = 'Poné al menos el DIIO o el número interno.';
    }
    if ($eid !== '' && preg_match('/^\d{15}$/', $eid) !== 1) {
        $errores['eid'] = 'El EID del chip tiene 15 dígitos.';
    }
    if ($caravana !== '' && !diio_con_formato($caravana, FORMATO_DIIO)) {
        $errores['caravana_visual'] = mensaje_formato_diio();
    }

    $sexo = dato_texto($d, 'sexo');
    $categoria = dato_texto($d, 'categoria');
    $origen = dato_texto($d, 'origen') !== '' ? dato_texto($d, 'origen') : 'COMPRADO';
    $etapa = dato_texto($d, 'etapa') !== '' ? dato_texto($d, 'etapa') : 'RECRIA';
    foreach ([
        'sexo'      => [$sexo, ['HEMBRA', 'MACHO', 'MACHO_CASTRADO']],
        'categoria' => [$categoria, ['TERNERO', 'TERNERA', 'NOVILLO', 'VAQUILLA', 'VACA', 'TORO', 'BUEY']],
        'origen'    => [$origen, ['COMPRADO', 'NACIDO_EN_PREDIO']],
        'etapa'     => [$etapa, ['CRIA', 'RECRIA', 'ENGORDA', 'REPRODUCCION']],
    ] as $campo => [$valor, $validos]) {
        if (!in_array($valor, $validos, true)) {
            $errores[$campo] = 'Falta elegir ' . $campo . '.';
        }
    }

    $fecha_ingreso = validar_fecha($d, 'fecha_ingreso', 'La fecha de ingreso', $errores);
    $peso = validar_numero($d, 'peso_ingreso_kg', 'El peso de ingreso', 10, 1500, $errores);

    $nacimiento = null;
    if (dato_texto($d, 'fecha_nacimiento') !== '') {
        $nacimiento = validar_fecha($d, 'fecha_nacimiento', 'La fecha de nacimiento', $errores);
        if ($nacimiento !== null && $fecha_ingreso !== null && $nacimiento > $fecha_ingreso) {
            $errores['fecha_nacimiento'] = 'La fecha de nacimiento no puede ser posterior al ingreso.';
        }
    }

    /* Cada identificación se compara contra las tres columnas: si un DIIO nuevo
       coincidiera con el número interno de otro animal, en la manga no se sabría
       a cuál se está pesando. */
    foreach (['caravana_visual' => $caravana, 'numero_interno' => $interno, 'eid' => $eid] as $campo => $valor) {
        if ($valor === '') {
            continue;
        }
        $ocupado = fila(
            'SELECT id, caravana_visual, numero_interno FROM animales
              WHERE organizacion_id = ? AND (caravana_visual = ? OR numero_interno = ? OR eid = ?) LIMIT 1',
            [$org, $valor, $valor, $valor],
        );
        if ($ocupado !== null) {
            $errores[$campo] = '«' . $valor . '» ya identifica al animal ' . nombre_animal($ocupado) . '.';
        }
    }

    $corral_id = dato_entero($d, 'corral_id');
    $corral = null;
    if ($corral_id !== null) {
        $corral = fila(
            "SELECT c.id, c.nombre, c.establecimiento_id, c.capacidad_maxima,
                    (SELECT COUNT(*) FROM animales a WHERE a.corral_actual_id = c.id AND a.estado = 'ACTIVO') AS ocupacion
               FROM corrales c WHERE c.organizacion_id = ? AND c.id = ?",
            [$org, $corral_id],
        );
        if ($corral === null) {
            $errores['corral_id'] = 'Ese corral no existe.';
        } elseif ((int) $corral['ocupacion'] >= (int) $corral['capacidad_maxima']) {
            $errores['corral_id'] = $corral['nombre'] . ' está completo (' . (int) $corral['capacidad_maxima'] . ' cabezas).';
        }
    }

    /* El predio sale del corral elegido; si no hay corral, del que venga indicado
       (la web manda el activo) y, en última instancia, del único que exista. */
    $predio = $corral !== null
        ? (int) $corral['establecimiento_id']
        : (dato_entero($d, 'establecimiento_id')
            ?? (int) (valor('SELECT id FROM establecimientos WHERE organizacion_id = ? ORDER BY id LIMIT 1', [$org]) ?? 0));
    if ($predio === 0) {
        $errores['corral_id'] = 'Antes de cargar animales hay que crear un predio.';
    }

    $madre_id = null;
    if (dato_texto($d, 'madre') !== '') {
        $madre = fila(
            "SELECT id FROM animales WHERE organizacion_id = ? AND sexo = 'HEMBRA'
               AND (caravana_visual = ? OR numero_interno = ? OR eid = ?)",
            [$org, dato_texto($d, 'madre'), dato_texto($d, 'madre'), dato_texto($d, 'madre')],
        );
        if ($madre === null) {
            $errores['madre'] = 'No se encontró una hembra con esa identificación.';
        } else {
            $madre_id = (int) $madre['id'];
        }
    }

    $padre_id = null;
    if (dato_texto($d, 'padre') !== '') {
        $padre = fila(
            "SELECT id FROM animales WHERE organizacion_id = ? AND sexo != 'HEMBRA'
               AND (caravana_visual = ? OR numero_interno = ? OR eid = ?)",
            [$org, dato_texto($d, 'padre'), dato_texto($d, 'padre'), dato_texto($d, 'padre')],
        );
        if ($padre === null) {
            $errores['padre'] = 'No se encontró un toro con esa identificación.';
        } else {
            $padre_id = (int) $padre['id'];
        }
    }

    if ($errores !== []) {
        throw new ErrorOperacion($errores);
    }

    $raza = dato_texto($d, 'raza');
    $destete = dato_texto($d, 'fecha_destete') !== '' ? dato_texto($d, 'fecha_destete') : null;

    $id = en_transaccion(static function () use (
        $org, $predio, $corral_id, $caravana, $interno, $eid, $sexo, $categoria, $raza,
        $madre_id, $padre_id, $origen, $etapa, $nacimiento, $destete, $fecha_ingreso, $peso
    ): int {
        consultar(
            'INSERT INTO animales (organizacion_id, establecimiento_id, corral_actual_id, caravana_visual,
                                   numero_interno, eid, sexo, categoria, raza, madre_id, padre_id, origen, etapa,
                                   fecha_nacimiento, fecha_destete, fecha_ingreso, peso_ingreso_kg, peso_actual_kg,
                                   fecha_ultima_pesada)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $org, $predio, $corral_id,
                $caravana !== '' ? $caravana : null,
                $interno !== '' ? $interno : null,
                $eid !== '' ? $eid : null,
                $sexo, $categoria, $raza !== '' ? $raza : null,
                $madre_id, $padre_id, $origen, $etapa,
                $nacimiento, $destete, $fecha_ingreso, $peso, $peso, $fecha_ingreso,
            ],
        );
        $id = ultimo_id();

        consultar(
            'INSERT INTO pesadas (organizacion_id, animal_id, fecha, peso_kg, motivo) VALUES (?, ?, ?, ?, ?)',
            [$org, $id, $fecha_ingreso, $peso, 'INGRESO'],
        );
        return $id;
    });

    return [
        'id' => $id,
        'mensaje' => 'Animal dado de alta: ' . ($caravana !== '' ? $caravana : $interno)
            . ' (' . mb_strtolower(legible($categoria)) . ', ' . numero($peso, 1) . ' kg).',
    ];
}

/* ============================================================
   Pesaje
   ============================================================ */

const MOTIVOS_PESADA = ['CONTROL', 'NACIMIENTO', 'DESTETE', 'ENCASTE', 'PREVENTA', 'SALIDA'];

function op_pesada(int $org, int $usuario_id, array $d): array
{
    $errores = [];
    $animal = buscar_animal($org, $d, $errores);
    $fecha = validar_fecha($d, 'fecha', 'La fecha', $errores);
    $peso = validar_numero($d, 'peso_kg', 'El peso', 10, 1500, $errores);

    $motivo = dato_texto($d, 'motivo') !== '' ? dato_texto($d, 'motivo') : 'CONTROL';
    if (!in_array($motivo, MOTIVOS_PESADA, true)) {
        $errores['motivo'] = 'Ese hito de pesaje no existe.';
    }
    if ($animal !== null && $fecha !== null && $animal['fecha_nacimiento'] !== null && $fecha < $animal['fecha_nacimiento']) {
        $errores['fecha'] = 'La pesada no puede ser anterior al nacimiento del animal.';
    }
    if ($errores !== []) {
        throw new ErrorOperacion($errores);
    }

    // Se compara con el pesaje anterior a esta fecha, no con el último cargado:
    // registrar hoy un pesaje atrasado no debe compararse con uno posterior.
    $anterior = fila(
        'SELECT fecha, peso_kg FROM pesadas
          WHERE organizacion_id = ? AND animal_id = ? AND fecha <= ? AND anulada = 0
          ORDER BY fecha DESC, id DESC LIMIT 1',
        [$org, (int) $animal['id'], $fecha],
    );
    $variacion = $anterior === null
        ? null
        : evaluar_variacion_peso((float) $anterior['peso_kg'], $anterior['fecha'], $peso, $fecha);

    if ($variacion !== null && !dato_si($d, 'confirmar')) {
        throw new ErrorOperacion(
            ['peso_kg' => $variacion['mensaje'] . ' Revisá el número y la identificación; si el peso es correcto, confirmalo.'],
            true,
        );
    }

    $id = en_transaccion(static function () use ($org, $animal, $fecha, $peso, $motivo): int {
        consultar(
            'INSERT INTO pesadas (organizacion_id, animal_id, fecha, peso_kg, motivo) VALUES (?, ?, ?, ?, ?)',
            [$org, (int) $animal['id'], $fecha, $peso, $motivo],
        );
        $id = ultimo_id();

        // El peso actual solo se pisa si esta pesada es la más reciente.
        consultar(
            'UPDATE animales SET peso_actual_kg = ?, fecha_ultima_pesada = ?
              WHERE id = ? AND organizacion_id = ? AND (fecha_ultima_pesada IS NULL OR fecha_ultima_pesada <= ?)',
            [$peso, $fecha, (int) $animal['id'], $org, $fecha],
        );

        // El destete no es solo un peso: cambia la etapa del animal.
        if ($motivo === 'DESTETE') {
            consultar(
                "UPDATE animales SET fecha_destete = ?, etapa = CASE WHEN etapa = 'CRIA' THEN 'RECRIA' ELSE etapa END
                  WHERE id = ? AND organizacion_id = ?",
                [$fecha, (int) $animal['id'], $org],
            );
        }
        return $id;
    });

    $mensaje = 'Pesaje de ' . nombre_animal($animal) . ': ' . numero($peso, 1) . ' kg';
    if ($anterior !== null) {
        $dias = dias_entre($anterior['fecha'], $fecha);
        $gdp = calcular_adg((float) $anterior['peso_kg'], $peso, $dias);
        $mensaje .= ' (antes ' . numero((float) $anterior['peso_kg'], 1) . ' kg'
            . ($gdp !== null ? ', GDP ' . numero($gdp, 2) . ' kg/día' : '') . ')';
    }
    $mensaje .= $motivo === 'DESTETE' ? '. Quedó destetado.' : '.';

    return ['id' => $id, 'mensaje' => $mensaje, 'alerta' => $variacion['mensaje'] ?? null];
}

/* ============================================================
   Parto
   ============================================================ */

const RESULTADOS_PARTO = ['VIVO', 'MUERTO', 'ABORTO'];

function op_parto(int $org, int $usuario_id, array $d): array
{
    $errores = [];
    $servicio = null;

    $servicio_id = dato_entero($d, 'servicio_id');
    if ($servicio_id !== null) {
        $servicio = fila(
            "SELECT s.* FROM servicios s LEFT JOIN partos p ON p.servicio_id = s.id
              WHERE s.organizacion_id = ? AND s.id = ? AND s.resultado IN ('PRENADA', 'PENDIENTE') AND p.id IS NULL",
            [$org, $servicio_id],
        );
        $vaca = $servicio === null ? null : fila(
            "SELECT * FROM animales WHERE organizacion_id = ? AND id = ? AND estado = 'ACTIVO'",
            [$org, (int) $servicio['vaca_id']],
        );
        if ($vaca === null) {
            $errores['servicio_id'] = 'Esa gestación no está abierta.';
        }
    } else {
        $vaca = buscar_animal(
            $org, $d, $errores, 'identificacion', 'vaca_id', " AND sexo = 'HEMBRA'",
            'No hay ninguna hembra activa con esa identificación.',
        );
        if ($vaca !== null) {
            // En terreno no se elige el servicio: se toma la gestación abierta de
            // la vaca. Si no hay ninguna registrada (monta sin anotar), el parto
            // igual se guarda, sin servicio.
            $servicio = fila(
                "SELECT s.* FROM servicios s LEFT JOIN partos p ON p.servicio_id = s.id
                  WHERE s.organizacion_id = ? AND s.vaca_id = ? AND s.resultado IN ('PRENADA', 'PENDIENTE') AND p.id IS NULL
                  ORDER BY s.fecha DESC LIMIT 1",
                [$org, (int) $vaca['id']],
            );
        }
    }

    $fecha = validar_fecha($d, 'fecha', 'La fecha del parto', $errores);

    $resultado = dato_texto($d, 'resultado');
    if (!in_array($resultado, RESULTADOS_PARTO, true)) {
        $errores['resultado'] = 'Indicá cómo terminó el parto.';
    }
    $dificultad = dato_texto($d, 'dificultad') !== '' ? dato_texto($d, 'dificultad') : 'NORMAL';
    if (!in_array($dificultad, ['NORMAL', 'DISTOCIA', 'CESAREA'], true)) {
        $errores['dificultad'] = 'Esa dificultad no existe.';
    }

    if ($servicio !== null && $fecha !== null && $fecha < $servicio['fecha']) {
        $errores['fecha'] = 'El parto no puede ser anterior al servicio (' . fecha_corta($servicio['fecha']) . ').';
    }

    $vivo = $resultado === 'VIVO';
    $peso = null;
    $sexo = null;
    if ($vivo) {
        $peso = validar_numero($d, 'peso_nacimiento', 'El peso al nacer', 10, 90, $errores);
        $sexo = dato_texto($d, 'sexo');
        if (!in_array($sexo, ['HEMBRA', 'MACHO'], true)) {
            $errores['sexo'] = 'Falta el sexo del ternero.';
        }
    }

    // Una vaca no puede parir dos veces en 200 días: si ya hay un parto cerca de
    // esa fecha, es el mismo cargado dos veces (por la web y por la app).
    if ($vaca !== null && $fecha !== null) {
        $cercano = valor(
            'SELECT fecha FROM partos WHERE organizacion_id = ? AND vaca_id = ? AND fecha >= ? AND fecha <= ? LIMIT 1',
            [
                $org, (int) $vaca['id'],
                (new DateTimeImmutable($fecha))->modify('-200 days')->format('Y-m-d'),
                (new DateTimeImmutable($fecha))->modify('+200 days')->format('Y-m-d'),
            ],
        );
        if ($cercano !== null) {
            $errores['fecha'] = nombre_animal($vaca) . ' ya tiene un parto registrado el ' . fecha_corta((string) $cercano) . '.';
        }
    }

    if ($errores !== []) {
        throw new ErrorOperacion($errores);
    }

    $debil = $vivo && dato_si($d, 'ternero_debil');
    $observaciones = dato_texto($d, 'observaciones');

    $ternero = en_transaccion(static function () use ($org, $vaca, $servicio, $fecha, $resultado, $dificultad, $vivo, $peso, $sexo, $debil, $observaciones): ?array {
        $ternero = null;

        if ($vivo) {
            /* El ternero entra al sistema como un animal más, con su madre y su
               padre enlazados. Sin DIIO todavía: se identifica con el número
               interno del predio hasta que se le ponga la caravana. */
            $mayor = 0;
            foreach (filas(
                "SELECT numero_interno FROM animales WHERE organizacion_id = ? AND numero_interno LIKE 'P-%'",
                [$org],
            ) as $fila) {
                $mayor = max($mayor, (int) substr((string) $fila['numero_interno'], 2));
            }
            $interno = sprintf('P-%04d', $mayor + 1);

            consultar(
                'INSERT INTO animales (organizacion_id, establecimiento_id, corral_actual_id, numero_interno,
                                       sexo, categoria, raza, madre_id, padre_id, pajuela_id,
                                       origen, etapa, fecha_nacimiento, fecha_ingreso,
                                       peso_ingreso_kg, peso_actual_kg, fecha_ultima_pesada)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $org, (int) $vaca['establecimiento_id'], $vaca['corral_actual_id'], $interno,
                    $sexo, $sexo === 'MACHO' ? 'TERNERO' : 'TERNERA', $vaca['raza'],
                    (int) $vaca['id'], $servicio['toro_id'] ?? null, $servicio['pajuela_id'] ?? null,
                    'NACIDO_EN_PREDIO', 'CRIA', $fecha, $fecha, $peso, $peso, $fecha,
                ],
            );
            $ternero = ['id' => ultimo_id(), 'interno' => $interno];

            consultar(
                'INSERT INTO pesadas (organizacion_id, animal_id, fecha, peso_kg, motivo) VALUES (?, ?, ?, ?, ?)',
                [$org, $ternero['id'], $fecha, $peso, 'NACIMIENTO'],
            );
        }

        consultar(
            'INSERT INTO partos (organizacion_id, vaca_id, servicio_id, fecha, resultado, dificultad,
                                 ternero_id, peso_nacimiento_kg, ternero_debil, observaciones)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $org, (int) $vaca['id'], $servicio !== null ? (int) $servicio['id'] : null, $fecha,
                $resultado, $dificultad, $ternero['id'] ?? null, $peso, $debil ? 1 : 0,
                $observaciones !== '' ? $observaciones : null,
            ],
        );

        // Si parió, estaba preñada aunque nunca se hubiera diagnosticado.
        if ($servicio !== null && $servicio['resultado'] === 'PENDIENTE') {
            consultar(
                "UPDATE servicios SET resultado = 'PRENADA' WHERE id = ? AND organizacion_id = ?",
                [(int) $servicio['id'], $org],
            );
        }

        return $ternero;
    });

    $mensaje = match (true) {
        $ternero !== null => 'Parto de ' . nombre_animal($vaca) . ': ternero ' . $ternero['interno']
            . ' (' . mb_strtolower(legible($sexo)) . ', ' . numero($peso, 1) . ' kg' . ($debil ? ', débil' : '')
            . ') enlazado a su madre.',
        $resultado === 'ABORTO' => 'Aborto registrado para ' . nombre_animal($vaca) . '. Cuenta para la alerta de descarte.',
        default => 'Parto de ' . nombre_animal($vaca) . ' registrado con el ternero muerto.',
    };

    return ['id' => $ternero['id'] ?? null, 'mensaje' => $mensaje, 'ternero' => $ternero];
}

/* ============================================================
   Secado: fin de la lactancia
   ============================================================ */

function op_secado(int $org, int $usuario_id, array $d): array
{
    $errores = [];
    $vaca = buscar_animal(
        $org, $d, $errores, 'identificacion', 'animal_id', " AND sexo = 'HEMBRA'",
        'No hay ninguna hembra activa con esa identificación.',
    );
    $fecha = validar_fecha($d, 'fecha', 'La fecha de secado', $errores);

    if ($vaca !== null && $fecha !== null && $vaca['fecha_nacimiento'] !== null && $fecha < $vaca['fecha_nacimiento']) {
        $errores['fecha'] = 'El secado no puede ser anterior al nacimiento del animal.';
    }
    if ($errores !== []) {
        throw new ErrorOperacion($errores);
    }

    consultar(
        'UPDATE animales SET fecha_secado = ? WHERE id = ? AND organizacion_id = ?',
        [$fecha, (int) $vaca['id'], $org],
    );

    return [
        'id' => (int) $vaca['id'],
        'mensaje' => 'Secado de ' . nombre_animal($vaca) . ' registrado el ' . fecha_corta($fecha) . '.',
    ];
}

/* ============================================================
   Nota en la ficha del animal
   ============================================================ */

const LARGO_MAXIMO_NOTA = 500;

/**
 * Texto libre con su fecha: «mastitis», «cojera», «costó parir».
 *
 * A propósito no hay catálogo de enfermedades ni campos de dosis: el productor
 * anota con sus palabras lo que pasó, que es lo que después quiere leer.
 */
function op_nota(int $org, int $usuario_id, array $d): array
{
    $errores = [];
    $animal = buscar_animal($org, $d, $errores);
    $fecha = validar_fecha($d, 'fecha', 'La fecha', $errores);

    $texto = dato_texto($d, 'texto');
    if ($texto === '') {
        $errores['texto'] = 'Escribí qué le pasó al animal.';
    } elseif (mb_strlen($texto) > LARGO_MAXIMO_NOTA) {
        $errores['texto'] = 'La nota no puede pasar de ' . LARGO_MAXIMO_NOTA . ' caracteres.';
    }
    if ($errores !== []) {
        throw new ErrorOperacion($errores);
    }

    consultar(
        'INSERT INTO notas_animal (organizacion_id, animal_id, fecha, texto, usuario_id, creado_en)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$org, (int) $animal['id'], $fecha, $texto, $usuario_id, ahora()],
    );

    return [
        'id' => ultimo_id(),
        'mensaje' => 'Nota guardada en la ficha de ' . nombre_animal($animal) . '.',
    ];
}

/* ============================================================
   Tratamiento sanitario
   ============================================================ */

const TIPOS_TRATAMIENTO = ['CURATIVO', 'PREVENTIVO', 'VACUNACION', 'DESPARASITACION', 'VITAMINICO'];
const VIAS_TRATAMIENTO = ['INTRAMUSCULAR', 'SUBCUTANEA', 'INTRAVENOSA', 'ORAL', 'TOPICA'];

function op_tratamiento(int $org, int $usuario_id, array $d): array
{
    $errores = [];

    $insumo = fila(
        "SELECT * FROM insumos WHERE organizacion_id = ? AND id = ? AND tipo = 'VETERINARIO'",
        [$org, dato_entero($d, 'insumo_id') ?? 0],
    );
    if ($insumo === null) {
        $errores['insumo_id'] = 'Elegí un producto veterinario del catálogo.';
    }

    $fecha = validar_fecha($d, 'fecha', 'La fecha', $errores);
    $dosis = validar_numero($d, 'dosis', 'La dosis', 0.01, 1000, $errores);

    $tipo = dato_texto($d, 'tipo') !== '' ? dato_texto($d, 'tipo') : 'CURATIVO';
    $via = dato_texto($d, 'via') !== '' ? dato_texto($d, 'via') : 'INTRAMUSCULAR';
    if (!in_array($tipo, TIPOS_TRATAMIENTO, true)) {
        $errores['tipo'] = 'Ese tipo de tratamiento no existe.';
    }
    if (!in_array($via, VIAS_TRATAMIENTO, true)) {
        $errores['via'] = 'Esa vía de aplicación no existe.';
    }

    /* A quién: a un animal puntual, o a todo un corral (vacunación masiva). */
    $corral_id = dato_entero($d, 'corral_id');
    $lote_id = null;
    $destinatarios = [];

    if (dato_texto($d, 'identificacion') !== '' || dato_entero($d, 'animal_id') !== null) {
        $animal = buscar_animal($org, $d, $errores);
        if ($animal !== null) {
            $destinatarios = [$animal];
            $lote_id = $animal['lote_actual_id'] !== null ? (int) $animal['lote_actual_id'] : null;
            $corral_id = $animal['corral_actual_id'] !== null ? (int) $animal['corral_actual_id'] : null;
        }
    } elseif ($corral_id !== null) {
        $destinatarios = filas(
            "SELECT * FROM animales WHERE organizacion_id = ? AND estado = 'ACTIVO' AND corral_actual_id = ?",
            [$org, $corral_id],
        );
        if ($destinatarios === []) {
            $errores['corral_id'] = 'Ese corral no tiene animales activos.';
        }
        $lote_id = lote_predominante($org, $corral_id);
    } else {
        $errores['corral_id'] = 'Elegí un corral o identificá el animal.';
    }

    $total = ($dosis ?? 0) * count($destinatarios);
    if ($insumo !== null && $destinatarios !== [] && $total > (float) $insumo['stock_actual']) {
        $errores['dosis'] = 'No alcanza el stock de ' . $insumo['nombre'] . ': hacen falta ' . numero($total, 1)
            . ' y quedan ' . numero((float) $insumo['stock_actual'], 1) . '.';
    }

    if ($errores !== []) {
        throw new ErrorOperacion($errores);
    }

    $diagnostico = dato_texto($d, 'diagnostico');

    $id = en_transaccion(static function () use ($org, $usuario_id, $insumo, $fecha, $dosis, $tipo, $via, $corral_id, $lote_id, $destinatarios, $total, $diagnostico): int {
        consultar(
            'INSERT INTO tratamientos (organizacion_id, establecimiento_id, corral_id, lote_id, insumo_id,
                                       tipo, fecha, via, dosis_por_animal, cantidad_animales,
                                       dias_carencia_carne, diagnostico, responsable_id, costo_total)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $org, (int) $insumo['establecimiento_id'], $corral_id, $lote_id, (int) $insumo['id'],
                $tipo, $fecha, $via, $dosis, count($destinatarios),
                // La carencia se congela: corregir la ficha del producto mañana no
                // puede cambiar la habilitación de un animal tratado hoy.
                (int) $insumo['dias_carencia_carne'],
                $diagnostico !== '' ? $diagnostico : null,
                $usuario_id, round($total * (float) $insumo['costo_unitario']),
            ],
        );
        $id = ultimo_id();

        foreach ($destinatarios as $animal) {
            consultar(
                'INSERT INTO aplicaciones_tratamiento (organizacion_id, tratamiento_id, animal_id) VALUES (?, ?, ?)',
                [$org, $id, (int) $animal['id']],
            );
        }

        descontar_stock($org, $usuario_id, $insumo, $total, 'CONSUMO_SANITARIO', $fecha, $id);
        return $id;
    });

    $habilitacion = estado_retiro($fecha, (int) $insumo['dias_carencia_carne'])['fecha_habilitacion'];
    $quien = count($destinatarios) === 1 ? nombre_animal($destinatarios[0]) : count($destinatarios) . ' animales';

    return [
        'id' => $id,
        'mensaje' => 'Tratamiento con ' . $insumo['nombre'] . ' en ' . $quien . '. '
            . ((int) $insumo['dias_carencia_carne'] > 0
                ? 'Bloqueado para venta, faena y salida del predio hasta el ' . fecha_corta($habilitacion) . '.'
                : 'Sin carencia: puede ir a faena.')
            . aviso_stock_minimo($org, [(int) $insumo['id']]),
    ];
}

/* ============================================================
   Validación del Médico Veterinario Autorizado (MVA)

   El tratamiento lo puede cargar cualquiera con permiso; validarlo, solo quien
   tiene un registro MVA vigente en esta organización. Al validar se congela lo
   firmado: el RUT y el número del MVA se copian, y la huella resume el
   tratamiento completo. Validar no reemplaza ningún trámite ante el SAG: deja
   constancia, dentro del sistema, de qué veterinario respondió por cada evento.
   ============================================================ */

/** Registro MVA de una persona en esta organización, o null si no tiene acceso. */
function membresia_mva(int $org, int $usuario_id): ?array
{
    return fila(
        'SELECT m.mva_rut, m.mva_numero, m.mva_vigente_hasta, u.nombre, u.apellido
           FROM membresias m JOIN usuarios u ON u.id = m.usuario_id
          WHERE m.organizacion_id = ? AND m.usuario_id = ? AND m.activa = 1',
        [$org, $usuario_id],
    );
}

/** Ids de los animales a los que se aplicó un tratamiento. */
function animales_del_tratamiento(int $org, int $tratamiento_id): array
{
    return animales_por_tratamiento($org, [$tratamiento_id])[$tratamiento_id] ?? [];
}

/**
 * Animales de varios tratamientos en una sola consulta: id del tratamiento →
 * ids de los animales. Una pantalla con cientos de tratamientos no puede hacer
 * una consulta por fila para revisar las huellas.
 *
 * @param list<int> $ids
 * @return array<int, list<int>>
 */
function animales_por_tratamiento(int $org, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $mapa = [];
    foreach (filas(
        'SELECT tratamiento_id, animal_id FROM aplicaciones_tratamiento
          WHERE organizacion_id = ? AND tratamiento_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
        [$org, ...array_map('intval', $ids)],
    ) as $aplicacion) {
        $mapa[(int) $aplicacion['tratamiento_id']][] = (int) $aplicacion['animal_id'];
    }
    return $mapa;
}

/**
 * Estado de la validación de cada tratamiento: PENDIENTE, VALIDADO o ALTERADO
 * (estaba validado, pero sus datos ya no coinciden con la huella). Los animales
 * de todos los validados se leen en una sola consulta.
 *
 * @param list<array<string, mixed>> $tratamientos filas de `tratamientos`
 * @return array<int, string> id del tratamiento → estado
 */
function estados_validacion(int $org, array $tratamientos): array
{
    $validados = array_filter($tratamientos, static fn (array $t): bool => $t['validado_en'] !== null);
    $animales = animales_por_tratamiento($org, array_map('intval', array_column($validados, 'id')));
    $estados = [];
    foreach ($tratamientos as $t) {
        $id = (int) $t['id'];
        $estados[$id] = $t['validado_en'] === null ? 'PENDIENTE'
            : (hash_equals((string) $t['huella'], huella_tratamiento($t, $animales[$id] ?? [], clave_huella())) ? 'VALIDADO' : 'ALTERADO');
    }
    return $estados;
}

function estado_validacion(int $org, array $tratamiento): string
{
    return estados_validacion($org, [$tratamiento])[(int) $tratamiento['id']];
}

/**
 * Lo que la app necesita para validar sin señal: el registro MVA de quien
 * ingresó y los tratamientos que esperan su firma.
 *
 * La vigencia se manda para avisar en el teléfono, pero la decisión es del
 * servidor: si el registro vence antes de que la validación llegue, se rechaza.
 */
function validaciones_app(int $org, int $usuario_id): array
{
    $mva = membresia_mva($org, $usuario_id);

    $pendientes = array_map(static fn (array $t): array => [
        'id'          => (int) $t['id'],
        'fecha'       => substr((string) $t['fecha'], 0, 10),
        'producto'    => $t['producto'],
        'unidad'      => $t['unidad'],
        'tipo'        => $t['tipo'],
        'via'         => $t['via'],
        'dosis'       => (float) $t['dosis_por_animal'],
        'cabezas'     => (int) $t['cantidad_animales'],
        'carencia'    => (int) $t['dias_carencia_carne'],
        'diagnostico' => $t['diagnostico'],
        'donde'       => $t['corral'] ?? $t['lote'],
        'cargo'       => $t['responsable_nombre'] !== null ? trim($t['responsable_nombre'] . ' ' . $t['responsable_apellido']) : null,
    ], filas(
        "SELECT t.id, t.fecha, t.tipo, t.via, t.dosis_por_animal, t.cantidad_animales, t.dias_carencia_carne,
                t.diagnostico, i.nombre AS producto, i.unidad, c.nombre AS corral, l.codigo AS lote,
                u.nombre AS responsable_nombre, u.apellido AS responsable_apellido
           FROM tratamientos t
           JOIN insumos i ON i.id = t.insumo_id
           LEFT JOIN corrales c ON c.id = t.corral_id
           LEFT JOIN lotes l ON l.id = t.lote_id
           LEFT JOIN usuarios u ON u.id = t.responsable_id
          WHERE t.organizacion_id = ? AND t.anulada = 0 AND t.validado_en IS NULL
          ORDER BY t.fecha DESC, t.id DESC
          LIMIT 200",
        [$org],
    ));

    return [
        'mva' => $mva === null || $mva['mva_numero'] === null ? null : [
            'numero'        => $mva['mva_numero'],
            'rut'           => rut_legible($mva['mva_rut']),
            'vigente_hasta' => substr((string) $mva['mva_vigente_hasta'], 0, 10),
            'vigente'       => mva_vigente($mva, hoy()),
        ],
        'por_validar' => $pendientes,
        // La lista va cortada en 200: el total permite avisar que hay más.
        'por_validar_total' => count($pendientes) < 200 ? count($pendientes) : (int) valor(
            'SELECT COUNT(*) FROM tratamientos WHERE organizacion_id = ? AND anulada = 0 AND validado_en IS NULL',
            [$org],
        ),
    ];
}

function op_validar_tratamiento(int $org, int $usuario_id, array $d): array
{
    $id = dato_entero($d, 'tratamiento_id');
    if ($id === null) {
        throw new ErrorOperacion(['tratamiento_id' => 'Falta indicar qué tratamiento se valida.']);
    }

    $mva = membresia_mva($org, $usuario_id);
    if (!mva_vigente($mva, hoy())) {
        throw new ErrorOperacion(['tratamiento_id' => $mva !== null && $mva['mva_numero'] !== null
            ? 'Tu registro MVA venció el ' . fecha_corta($mva['mva_vigente_hasta']) . '. Pedile al administrador que actualice la vigencia.'
            : 'Para validar hace falta tener cargado un registro MVA vigente. Lo carga el administrador en Usuarios.']);
    }

    $tratamiento = fila('SELECT * FROM tratamientos WHERE organizacion_id = ? AND id = ?', [$org, $id]);
    if ($tratamiento === null) {
        throw new ErrorOperacion(['tratamiento_id' => 'Ese tratamiento no existe en esta organización.']);
    }
    if ((int) $tratamiento['anulada'] === 1) {
        throw new ErrorOperacion(['tratamiento_id' => 'Ese tratamiento está anulado: no se puede validar.']);
    }
    if ($tratamiento['validado_en'] !== null) {
        throw new ErrorOperacion(['tratamiento_id' => 'Ese tratamiento ya estaba validado.']);
    }

    $validado = $tratamiento;
    $validado['validado_por'] = $usuario_id;
    $validado['validado_en'] = ahora();
    $validado['validado_mva_rut'] = $mva['mva_rut'];
    $validado['validado_mva_numero'] = $mva['mva_numero'];
    $huella = huella_tratamiento($validado, animales_del_tratamiento($org, $id), clave_huella());

    en_transaccion(static function () use ($org, $usuario_id, $id, $validado, $huella, $mva): void {
        // La condición sobre validado_en evita que dos validaciones simultáneas
        // se pisen: la segunda no encuentra la fila y avisa.
        $actualizada = consultar(
            'UPDATE tratamientos
                SET validado_por = ?, validado_en = ?, validado_mva_rut = ?, validado_mva_numero = ?, huella = ?
              WHERE id = ? AND organizacion_id = ? AND anulada = 0 AND validado_en IS NULL',
            [
                $usuario_id, $validado['validado_en'], $validado['validado_mva_rut'],
                $validado['validado_mva_numero'], $huella, $id, $org,
            ],
        );
        if ($actualizada->rowCount() !== 1) {
            throw new ErrorOperacion(['tratamiento_id' => 'Ese tratamiento ya estaba validado o fue anulado recién.']);
        }
        registrar_bitacora(
            $org, $usuario_id, 'VALIDA_TRATAMIENTO', 'tratamientos', $id,
            'MVA N° ' . $mva['mva_numero'] . ' · RUT ' . rut_legible($mva['mva_rut']),
        );
    });

    return [
        'id' => $id,
        'mensaje' => 'Tratamiento validado por ' . $mva['nombre'] . ' ' . $mva['apellido']
            . ' (MVA N° ' . $mva['mva_numero'] . ').',
    ];
}

/* ============================================================
   Predios: alta y edición

   El RUP identifica al predio ante el SAG: se guarda siempre con el mismo
   formato y no puede repetirse entre los campos del propietario. Al editar,
   el predio tiene que ser de esta organización: el id llega del formulario y
   se puede cambiar a mano.
   ============================================================ */

const TIPOS_PREDIO = [
    'CRIANZA'        => 'Crianza',
    'LECHERIA'       => 'Lechería',
    'CAMPO_PASTORIL' => 'Campo pastoril',
    'FEEDLOT'        => 'Feedlot',
    'MIXTO'          => 'Mixto',
];

/** Alta de un predio. Nunca edita, aunque los datos traigan un predio_id. */
function op_crear_predio(int $org, int $usuario_id, array $d): array
{
    return guardar_predio($org, $usuario_id, $d, null);
}

/** Edición del predio `predio_id`, que tiene que ser de esta organización. */
function op_editar_predio(int $org, int $usuario_id, array $d): array
{
    return guardar_predio($org, $usuario_id, $d, dato_entero($d, 'predio_id') ?? 0);
}

function guardar_predio(int $org, int $usuario_id, array $d, ?int $predio_id): array
{
    $anterior = null;
    if ($predio_id !== null) {
        $anterior = fila('SELECT * FROM establecimientos WHERE organizacion_id = ? AND id = ?', [$org, $predio_id]);
        if ($anterior === null) {
            throw new ErrorOperacion(['predio_id' => 'Ese predio no es de esta organización.']);
        }
    }

    $datos = [
        'nombre' => dato_texto($d, 'nombre'),
        'tipo'   => dato_texto($d, 'tipo'),
        'rup'    => null,
        'comuna' => dato_texto($d, 'comuna') !== '' ? dato_texto($d, 'comuna') : null,
        'region' => dato_texto($d, 'region') !== '' ? dato_texto($d, 'region') : null,
    ];

    $errores = [];
    foreach (['nombre' => 150, 'comuna' => 80, 'region' => 80] as $campo => $maximo) {
        if (mb_strlen((string) $datos[$campo]) > $maximo) {
            $errores[$campo] = 'Puede tener hasta ' . $maximo . ' caracteres.';
        }
    }
    if ($datos['nombre'] === '') {
        $errores['nombre'] = 'Falta el nombre del predio.';
    } elseif (!isset($errores['nombre']) && (int) valor(
        'SELECT COUNT(*) FROM establecimientos WHERE organizacion_id = ? AND nombre = ? AND id <> ?',
        [$org, $datos['nombre'], $predio_id ?? 0],
    ) > 0) {
        $errores['nombre'] = 'Ya hay un predio con ese nombre.';
    }
    if (!isset(TIPOS_PREDIO[$datos['tipo']])) {
        $errores['tipo'] = 'Ese tipo de predio no existe.';
    }
    if (dato_texto($d, 'rup') !== '') {
        $datos['rup'] = normalizar_rup(dato_texto($d, 'rup'));
        if ($datos['rup'] === null) {
            $errores['rup'] = 'El RUP tiene 9 dígitos: región, provincia, comuna y número del predio (ej: 09.1.01.0001).';
        } elseif ((int) valor(
            'SELECT COUNT(*) FROM establecimientos WHERE organizacion_id = ? AND rup = ? AND id <> ?',
            [$org, $datos['rup'], $predio_id ?? 0],
        ) > 0) {
            $errores['rup'] = 'Ese RUP ya está cargado en otro predio.';
        }
    }
    if ($errores !== []) {
        throw new ErrorOperacion($errores);
    }

    if ($anterior === null) {
        consultar(
            'INSERT INTO establecimientos (organizacion_id, nombre, tipo, rup, comuna, region) VALUES (?, ?, ?, ?, ?, ?)',
            [$org, ...array_values($datos)],
        );
        return [
            'id' => ultimo_id(),
            'mensaje' => 'Predio «' . $datos['nombre'] . '» creado. Elegilo arriba para empezar a cargarle animales.',
        ];
    }

    $cambios = [];
    foreach (['nombre' => 'nombre', 'tipo' => 'tipo', 'rup' => 'RUP', 'comuna' => 'comuna', 'region' => 'región'] as $columna => $rotulo) {
        if ((string) $anterior[$columna] !== (string) $datos[$columna]) {
            $cambios[] = $rotulo . ': ' . ($anterior[$columna] ?? '—') . ' → ' . ($datos[$columna] ?? '—');
        }
    }
    if ($cambios === []) {
        throw new ErrorOperacion(['nombre' => 'No cambiaste ningún dato de «' . $anterior['nombre'] . '».']);
    }

    en_transaccion(static function () use ($org, $usuario_id, $predio_id, $datos, $cambios): void {
        consultar(
            'UPDATE establecimientos SET nombre = ?, tipo = ?, rup = ?, comuna = ?, region = ? WHERE id = ? AND organizacion_id = ?',
            [...array_values($datos), $predio_id, $org],
        );
        // Cambiar el RUP de un predio con animales es delicado: queda en la bitácora.
        registrar_bitacora($org, $usuario_id, 'EDITA_PREDIO', 'establecimientos', $predio_id, implode(' · ', $cambios));
    });

    return ['id' => $predio_id, 'mensaje' => 'Predio «' . $datos['nombre'] . '» actualizado.'];
}

/* ============================================================
   Registro MVA de una persona (lo carga el administrador)

   Va en la membresía: lo declara el administrador de cada organización.
   Nadie se lo carga a sí mismo: quien valida tratamientos no puede ser
   también quien se dio la autorización. Los tres campos vacíos lo quitan; las
   validaciones ya hechas no cambian, porque guardaron su propia copia.
   ============================================================ */

function op_guardar_mva(int $org, int $usuario_id, array $d): array
{
    $persona_id = dato_entero($d, 'usuario_id') ?? 0;
    $miembro = fila(
        'SELECT u.nombre, u.apellido, m.mva_numero FROM membresias m JOIN usuarios u ON u.id = m.usuario_id
          WHERE m.organizacion_id = ? AND m.usuario_id = ?',
        [$org, $persona_id],
    );
    if ($miembro === null) {
        throw new ErrorOperacion(['usuario_id' => 'Esa persona no trabaja en esta organización.']);
    }
    if ($persona_id === $usuario_id) {
        throw new ErrorOperacion(['usuario_id' => 'No podés cargar tu propio registro MVA: pedíselo a otro administrador.']);
    }
    $nombre = $miembro['nombre'] . ' ' . $miembro['apellido'];

    $rut = null;
    $numero = null;
    $vigencia = null;
    $quitar = dato_texto($d, 'mva_rut') === '' && dato_texto($d, 'mva_numero') === '' && dato_texto($d, 'mva_vigente_hasta') === '';
    if ($quitar && $miembro['mva_numero'] === null) {
        throw new ErrorOperacion(['usuario_id' => $nombre . ' no tenía registro MVA cargado.']);
    }
    if (!$quitar) {
        $errores = [];
        $rut = normalizar_rut(dato_texto($d, 'mva_rut'));
        if ($rut === null) {
            $errores['mva_rut'] = 'El RUT no es válido: revisá el dígito verificador.';
        }
        $numero = dato_texto($d, 'mva_numero');
        if ($numero === '' || mb_strlen($numero) > 30) {
            $errores['mva_numero'] = 'Escribí el número de registro MVA (hasta 30 caracteres).';
        }
        $vigencia = dato_texto($d, 'mva_vigente_hasta');
        if (!fecha_valida($vigencia)) {
            $errores['mva_vigente_hasta'] = 'Indicá hasta qué fecha está vigente el registro.';
        }
        if ($errores !== []) {
            throw new ErrorOperacion($errores);
        }
    }

    en_transaccion(static function () use ($org, $usuario_id, $persona_id, $rut, $numero, $vigencia, $nombre, $quitar): void {
        consultar(
            'UPDATE membresias SET mva_rut = ?, mva_numero = ?, mva_vigente_hasta = ? WHERE organizacion_id = ? AND usuario_id = ?',
            [$rut, $numero, $vigencia, $org, $persona_id],
        );
        registrar_bitacora(
            $org, $usuario_id, $quitar ? 'QUITA_MVA' : 'ACTUALIZA_MVA', 'membresias', $persona_id,
            $quitar ? $nombre : $nombre . ': MVA N° ' . $numero . ' · RUT ' . rut_legible($rut) . ' · vigente hasta ' . fecha_corta($vigencia),
        );
    });

    return [
        'id' => $persona_id,
        'mensaje' => $quitar
            ? $nombre . ' ya no tiene registro MVA: no va a poder validar tratamientos.'
            : 'Registro MVA de ' . $nombre . ' guardado, vigente hasta el ' . fecha_corta($vigencia) . '.',
    ];
}

/* ============================================================
   Entrega de alimento
   ============================================================ */

function op_entrega(int $org, int $usuario_id, array $d): array
{
    $errores = [];

    $racion = fila('SELECT * FROM raciones WHERE organizacion_id = ? AND id = ?', [$org, dato_entero($d, 'racion_id') ?? 0]);
    if ($racion === null) {
        $errores['racion_id'] = 'Elegí una ración.';
    }

    /* Destino: un corral completo (feedlot) o un animal puntual (cría). */
    $corral = null;
    $animal = null;
    if (dato_texto($d, 'identificacion') !== '' || dato_entero($d, 'animal_id') !== null) {
        $animal = buscar_animal($org, $d, $errores);
    } elseif (dato_entero($d, 'corral_id') !== null) {
        $corral = fila('SELECT * FROM corrales WHERE organizacion_id = ? AND id = ?', [$org, dato_entero($d, 'corral_id')]);
        if ($corral === null) {
            $errores['corral_id'] = 'Ese corral no existe.';
        }
    } else {
        $errores['corral_id'] = 'Elegí un corral o identificá el animal.';
    }

    $fecha = validar_fecha($d, 'fecha', 'La fecha', $errores);

    // La cantidad puede venir en kilos o toneladas, y tal cual o en materia seca.
    $campo_cantidad = dato_texto($d, 'cantidad') !== '' ? 'cantidad' : 'kg_entregados';
    $cantidad = validar_numero($d, $campo_cantidad, 'La cantidad', 0.001, 100000, $errores);
    $unidad = dato_texto($d, 'unidad') !== '' ? dato_texto($d, 'unidad') : 'KG';
    $base = dato_texto($d, 'base') !== '' ? dato_texto($d, 'base') : 'TAL_CUAL';
    if (!in_array($unidad, ['KG', 'T'], true)) {
        $errores['unidad'] = 'La unidad tiene que ser kilos o toneladas.';
    }
    if (!in_array($base, ['TAL_CUAL', 'MATERIA_SECA'], true)) {
        $errores['base'] = 'Indicá si la cantidad es tal cual o materia seca.';
    }

    $numero_entrega = dato_entero($d, 'numero_entrega') ?? 1;
    if ($numero_entrega < 1 || $numero_entrega > 6) {
        $errores['numero_entrega'] = 'El número de entrega va de 1 a 6.';
    }
    $kg_planificados = validar_numero($d, 'kg_planificados', 'Los kilos planificados', 0, 100000, $errores, false);

    $kg = 0.0;
    $ingredientes = [];
    if ($racion !== null && $cantidad !== null) {
        $kg = $cantidad * ($unidad === 'T' ? 1000 : 1);
        if ($base === 'MATERIA_SECA') {
            // Kilos de materia seca a kilos tal cual, con la humedad de la ración.
            $kg = (float) $racion['materia_seca_porcentaje'] > 0
                ? $kg / ((float) $racion['materia_seca_porcentaje'] / 100)
                : $kg;
        }
        $kg = round($kg, 1);

        $ingredientes = !GESTION_AVANZADA ? [] : filas(
            'SELECT ir.porcentaje, i.* FROM ingredientes_racion ir JOIN insumos i ON i.id = ir.insumo_id
              WHERE ir.organizacion_id = ? AND ir.racion_id = ? ORDER BY ir.porcentaje DESC',
            [$org, (int) $racion['id']],
        );

        // Lo que no está en bodega no pudo salir del mixer.
        $faltantes = [];
        foreach ($ingredientes as $ingrediente) {
            $necesario = $kg * (float) $ingrediente['porcentaje'] / 100;
            if ($necesario > (float) $ingrediente['stock_actual']) {
                $faltantes[] = $ingrediente['nombre'] . ' (hacen falta ' . numero($necesario) . ' kg, quedan '
                    . numero((float) $ingrediente['stock_actual']) . ')';
            }
        }
        if ($faltantes !== []) {
            $errores[$campo_cantidad] = 'No alcanza la bodega: ' . implode(', ', $faltantes) . '. Registrá la compra antes.';
        }
    }

    if ($errores !== []) {
        throw new ErrorOperacion($errores);
    }

    // Costo y materia seca salen de la receta si la hay: es lo que realmente se usó.
    if ($ingredientes !== []) {
        $costo = 0.0;
        $kg_ms = 0.0;
        foreach ($ingredientes as $ingrediente) {
            $parte = $kg * (float) $ingrediente['porcentaje'] / 100;
            $costo += $parte * (float) $ingrediente['costo_unitario'];
            $kg_ms += $parte * (float) ($ingrediente['materia_seca_porcentaje'] ?? 100) / 100;
        }
    } else {
        $costo = $kg * (float) $racion['costo_por_kg'];
        $kg_ms = kg_materia_seca($kg, (float) $racion['materia_seca_porcentaje']);
    }

    $lote_id = $animal !== null
        ? ($animal['lote_actual_id'] !== null ? (int) $animal['lote_actual_id'] : null)
        : lote_predominante($org, (int) $corral['id']);

    /* Sin kilos planificados a mano, el plan es el calculado: lo que el corral
       (o el animal) necesita según peso, categoría y ganancia diaria. Así la
       pantalla de cumplimiento compara contra la necesidad real y no contra lo
       mismo que se entregó. */
    $plan_calculado = null;
    if ($kg_planificados === null) {
        $plan_calculado = kg_planificados_calculados($org, $corral, $animal, $racion);
        $kg_planificados = $plan_calculado;
    }

    $id = en_transaccion(static function () use ($org, $usuario_id, $corral, $animal, $lote_id, $racion, $fecha, $numero_entrega, $kg_planificados, $kg, $kg_ms, $costo, $ingredientes): int {
        consultar(
            'INSERT INTO entregas_alimento (organizacion_id, corral_id, animal_id, lote_id, racion_id, fecha,
                                            numero_entrega, kg_planificados, kg_entregados, kg_materia_seca,
                                            costo_total, responsable_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $org, $corral !== null ? (int) $corral['id'] : null, $animal !== null ? (int) $animal['id'] : null,
                $lote_id, (int) $racion['id'], $fecha, $numero_entrega, $kg_planificados ?? $kg, $kg,
                round($kg_ms, 1), round($costo), $usuario_id,
            ],
        );
        $id = ultimo_id();

        foreach ($ingredientes as $ingrediente) {
            descontar_stock(
                $org, $usuario_id, $ingrediente, round($kg * (float) $ingrediente['porcentaje'] / 100, 2),
                'CONSUMO_ALIMENTACION', $fecha, $id,
            );
        }
        return $id;
    });

    $destino = $animal !== null ? nombre_animal($animal) : $corral['nombre'];
    $mensaje = 'Entrega de ' . numero($kg) . ' kg' . ($kg >= 1000 ? ' (' . numero($kg / 1000, 2) . ' t)' : '')
        . ' de ' . $racion['nombre'] . ' en ' . $destino . ', ' . numero($kg_ms) . ' kg de materia seca.';
    if ($ingredientes !== []) {
        $mensaje .= ' Se descontó de bodega: ' . implode(', ', array_map(
            static fn (array $i): string => $i['nombre'] . ' ' . numero($kg * (float) $i['porcentaje'] / 100) . ' kg',
            $ingredientes,
        )) . '.';
    }
    if ($plan_calculado !== null) {
        $diferencia = $kg - $plan_calculado;
        $mensaje .= ' Plan calculado: ' . numero($plan_calculado) . ' kg'
            . (abs($diferencia) >= max(1.0, $plan_calculado * 0.05)
                ? ' (' . ($diferencia > 0 ? '+' : '−') . numero(abs($diferencia)) . ' kg).'
                : ', entrega al día.');
    }
    $mensaje .= aviso_stock_minimo($org, array_map(static fn (array $i): int => (int) $i['id'], $ingredientes));

    return ['id' => $id, 'mensaje' => $mensaje];
}

/* ============================================================
   Traslado entre corrales
   ============================================================ */

function op_traslado(int $org, int $usuario_id, array $d): array
{
    return en_transaccion(static function () use ($org, $usuario_id, $d): array {
        // Serializar las admisiones a corrales de la misma organización.
        consultar('UPDATE organizaciones SET id = id WHERE id = ?', [$org]);
        return guardar_traslado($org, $usuario_id, $d);
    });
}

function guardar_traslado(int $org, int $usuario_id, array $d): array
{
    $errores = [];

    $destino = fila('SELECT * FROM corrales WHERE organizacion_id = ? AND id = ?', [$org, dato_entero($d, 'destino_id') ?? 0]);
    if ($destino === null) {
        $errores['destino_id'] = 'Elegí el corral de destino.';
    }
    if ($origen_corral = dato_entero($d, 'origen_id')) {
        // El corral de origen también tiene que ser de la organización: se guarda
        // en el movimiento y quedaría apuntando a un campo ajeno.
        if (fila('SELECT id FROM corrales WHERE organizacion_id = ? AND id = ?', [$org, $origen_corral]) === null) {
            $errores['origen_id'] = 'Ese corral de origen no existe.';
        }
    }
    $fecha = validar_fecha($d, 'fecha', 'La fecha', $errores);

    // La app manda una lista; la web, un texto separado por comas.
    $lista = $d['identificaciones'] ?? [];
    $identificaciones = is_array($lista)
        ? array_values(array_filter(array_map(static fn ($x): string => is_scalar($x) ? trim((string) $x) : '', $lista)))
        : array_values(array_filter(array_map('trim', explode(',', (string) $lista))));
    $identificaciones = array_values(array_unique($identificaciones));
    $origen_id = dato_entero($d, 'origen_id');

    $a_mover = [];
    if ($identificaciones !== []) {
        $no_encontrados = [];
        foreach ($identificaciones as $identificacion) {
            $sin_error = [];
            $animal = buscar_animal($org, ['identificacion' => $identificacion], $sin_error);
            if ($animal === null) {
                $no_encontrados[] = $identificacion;
            } else {
                $a_mover[(int) $animal['id']] = $animal;
            }
        }
        if ($no_encontrados !== []) {
            $errores['identificaciones'] = 'No corresponden a un animal activo: ' . implode(', ', $no_encontrados) . '.';
        }
    } elseif ($origen_id !== null) {
        foreach (filas(
            "SELECT * FROM animales WHERE organizacion_id = ? AND estado = 'ACTIVO' AND corral_actual_id = ?",
            [$org, $origen_id],
        ) as $animal) {
            $a_mover[(int) $animal['id']] = $animal;
        }
        if ($a_mover === []) {
            $errores['origen_id'] = 'Ese corral no tiene animales activos.';
        }
    } else {
        $errores['origen_id'] = 'Elegí un corral de origen o indicá qué animales mover.';
    }

    /* La capacidad del destino es un límite: un corral sobrepoblado deja
       animales sin lugar en el comedero. */
    /* Un traslado mueve animales dentro de un mismo campo. Permitirlo entre
       predios dejaba al animal con el corral de uno y el establecimiento del
       otro: aparecía en el inventario de un campo ocupando una plaza del otro. */
    if ($destino !== null && $a_mover !== []) {
        $de_otro_predio = array_filter(
            $a_mover,
            static fn (array $a): bool => (int) $a['establecimiento_id'] !== (int) $destino['establecimiento_id'],
        );
        if ($de_otro_predio !== []) {
            $errores['destino_id'] = 'Ese corral es de otro predio. '
                . (count($de_otro_predio) === 1 ? 'El animal' : 'Hay animales')
                . ' que no pertenece' . (count($de_otro_predio) === 1 ? '' : 'n') . ' a ese campo.';
        }
    }
    if ($destino !== null && $a_mover !== [] && !isset($errores['destino_id'])) {
        $ocupacion = (int) valor(
            "SELECT COUNT(*) FROM animales WHERE organizacion_id = ? AND corral_actual_id = ? AND estado = 'ACTIVO'",
            [$org, (int) $destino['id']],
        );
        $entran = count(array_filter(
            $a_mover,
            static fn (array $a): bool => (int) $a['corral_actual_id'] !== (int) $destino['id'],
        ));
        if ($entran === 0) {
            $errores['destino_id'] = 'Esos animales ya están en ' . $destino['nombre'] . '.';
        } elseif ($ocupacion + $entran > (int) $destino['capacidad_maxima']) {
            $errores['destino_id'] = $destino['nombre'] . ' quedaría con ' . numero($ocupacion + $entran)
                . ' cabezas y su capacidad es ' . numero((int) $destino['capacidad_maxima'])
                . ' (quedan ' . numero(max((int) $destino['capacidad_maxima'] - $ocupacion, 0)) . ' plazas).';
        }
    }

    if ($errores !== []) {
        throw new ErrorOperacion($errores);
    }

    $motivo = dato_texto($d, 'motivo');
    $moviendo = array_values(array_filter(
        $a_mover,
        static fn (array $a): bool => (int) $a['corral_actual_id'] !== (int) $destino['id'],
    ));

    $id = en_transaccion(static function () use ($org, $usuario_id, $destino, $fecha, $origen_id, $moviendo, $motivo): int {
        // Con animales puntuales, el origen es el corral de donde sale la mayoría.
        $origenes = array_count_values(array_map(static fn (array $a): int => (int) $a['corral_actual_id'], $moviendo));
        arsort($origenes);
        $origen = $origen_id ?? (int) array_key_first($origenes);

        consultar(
            'INSERT INTO movimientos_hacienda (organizacion_id, tipo, fecha, corral_origen_id,
                                               corral_destino_id, cantidad_animales, motivo, responsable_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $org, 'TRASLADO_INTERNO', $fecha, $origen > 0 ? $origen : null, (int) $destino['id'],
                count($moviendo), $motivo !== '' ? $motivo : null, $usuario_id,
            ],
        );
        $id = ultimo_id();

        foreach ($moviendo as $animal) {
            consultar(
                'INSERT INTO detalles_movimiento (organizacion_id, movimiento_id, animal_id) VALUES (?, ?, ?)',
                [$org, $id, (int) $animal['id']],
            );
            consultar(
                'UPDATE animales SET corral_actual_id = ? WHERE id = ? AND organizacion_id = ?',
                [(int) $destino['id'], (int) $animal['id'], $org],
            );
        }
        return $id;
    });

    return [
        'id' => $id,
        'mensaje' => (count($moviendo) === 1 ? nombre_animal($moviendo[0]) . ' movido' : numero(count($moviendo)) . ' animales movidos')
            . ' a ' . $destino['nombre'] . '.',
    ];
}

/* ============================================================
   Baja por muerte
   ============================================================ */

function op_baja(int $org, int $usuario_id, array $d): array
{
    $errores = [];
    $animal = buscar_animal($org, $d, $errores);
    $fecha = validar_fecha($d, 'fecha', 'La fecha', $errores);
    if ($errores !== []) {
        throw new ErrorOperacion($errores);
    }

    $causa = dato_texto($d, 'causa');

    $id = en_transaccion(static function () use ($org, $animal, $fecha, $causa): int {
        consultar(
            'INSERT INTO mortalidades (organizacion_id, animal_id, lote_id, fecha, causa_presunta) VALUES (?, ?, ?, ?, ?)',
            [$org, (int) $animal['id'], $animal['lote_actual_id'], $fecha, $causa !== '' ? $causa : null],
        );
        $id = ultimo_id();

        consultar(
            "UPDATE animales SET estado = 'MUERTO', fecha_egreso = ?, corral_actual_id = NULL
              WHERE id = ? AND organizacion_id = ?",
            [$fecha, (int) $animal['id'], $org],
        );

        // La baja se descuenta del lote: si no, la mortalidad del cierre quedaría mal.
        if ($animal['lote_actual_id'] !== null) {
            consultar(
                'UPDATE lotes SET cabezas_actuales = ' . sql_no_negativo('cabezas_actuales - 1') . ' WHERE id = ? AND organizacion_id = ?',
                [(int) $animal['lote_actual_id'], $org],
            );
        }
        return $id;
    });

    return [
        'id' => $id,
        'mensaje' => 'Baja registrada: ' . nombre_animal($animal)
            . ($animal['lote_actual_id'] !== null ? '. Se descontó del lote.' : '.'),
    ];
}

/* ============================================================
   Consultas compartidas por varias pantallas y por la API
   ============================================================ */

/**
 * Carencia efectiva de cada animal con algún tratamiento: manda el más
 * restrictivo. Solo incluye a los que todavía no pueden ir a faena.
 *
 * @return array<int, array{estado: string, fecha_habilitacion: string, dias_restantes: int, apto: bool, producto: string}>
 */
function carencias_activas(int $org, ?int $predio = null, ?string $fecha = null, ?array $animal_ids = null): array
{
    /* $fecha: el día en que el animal saldría (por defecto, hoy). Una venta
       fechada antes de que venza el retiro tiene que verse bloqueada aunque
       hoy ya se haya cumplido, y al revés. $animal_ids acota la consulta. */
    if ($animal_ids === []) {
        return [];
    }
    $retiros = [];
    $parametros = [$org, (new DateTimeImmutable($fecha ?? hoy()))->modify('-400 days')->format('Y-m-d')];
    $filtro = '';
    if ($predio !== null) {
        $filtro .= ' AND a.establecimiento_id = ?';
        $parametros[] = $predio;
    }
    if ($animal_ids !== null) {
        $ids = array_values(array_map('intval', $animal_ids));
        $filtro .= ' AND ap.animal_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($parametros, ...$ids);
    }
    foreach (filas(
        'SELECT ap.animal_id, t.fecha, t.dias_carencia_carne, i.nombre AS producto
           FROM aplicaciones_tratamiento ap
           JOIN tratamientos t ON t.id = ap.tratamiento_id
           JOIN insumos i ON i.id = t.insumo_id
           JOIN animales a ON a.id = ap.animal_id
          WHERE ap.organizacion_id = ? AND t.fecha >= ? AND t.anulada = 0' . $filtro,
        // Ningún producto tiene más de un año de carencia: lo anterior ya venció.
        $parametros,
    ) as $aplicacion) {
        // Un tratamiento fechado después de la salida no la afecta.
        if ($fecha !== null && substr((string) $aplicacion['fecha'], 0, 10) > $fecha) {
            continue;
        }
        $estado = estado_retiro($aplicacion['fecha'], (int) $aplicacion['dias_carencia_carne'], $fecha);
        if ($estado['apto']) {
            continue;
        }
        $animal = (int) $aplicacion['animal_id'];
        if (!isset($retiros[$animal]) || $estado['fecha_habilitacion'] > $retiros[$animal]['fecha_habilitacion']) {
            $retiros[$animal] = $estado + ['producto' => $aplicacion['producto']];
        }
    }
    return $retiros;
}

/**
 * Vacas activas que cumplen la regla de descarte.
 *
 * @return array<int, array> filas de animales con la clave 'evaluacion'
 */
function vacas_para_descarte(int $org, ?int $predio = null): array
{
    $del_predio = $predio === null ? '' : ' AND a.establecimiento_id = ' . $predio;
    $partos = [];
    foreach (filas(
        'SELECT vaca_id, fecha, resultado, dificultad, ternero_debil FROM partos WHERE organizacion_id = ? ORDER BY fecha',
        [$org],
    ) as $parto) {
        $partos[(int) $parto['vaca_id']][] = [
            'fecha'         => $parto['fecha'],
            'resultado'     => $parto['resultado'],
            'dificultad'    => $parto['dificultad'],
            'ternero_debil' => (int) $parto['ternero_debil'] === 1,
        ];
    }

    $resultado = [];
    foreach (filas(
        "SELECT a.id, a.caravana_visual, a.numero_interno, a.raza, a.fecha_nacimiento FROM animales a
          WHERE a.organizacion_id = ? AND a.estado = 'ACTIVO' AND a.sexo = 'HEMBRA'" . $del_predio . "
          ORDER BY a.caravana_visual",
        [$org],
    ) as $vaca) {
        if (!isset($partos[(int) $vaca['id']])) {
            continue;
        }
        $evaluacion = evaluar_descarte($partos[(int) $vaca['id']]);
        if ($evaluacion['descartar']) {
            $resultado[] = $vaca + ['evaluacion' => $evaluacion];
        }
    }
    return $resultado;
}

/**
 * Animales cuyo último pesaje de los últimos días varió de forma anormal
 * respecto del anterior.
 *
 * @return array<int, array{animal_id: int, nombre: string, fecha: string, peso: float, alerta: array}>
 */
function alertas_de_pesaje(int $org, int $dias = 30, ?int $predio = null): array
{
    $del_predio = $predio === null ? '' : ' AND a.establecimiento_id = ' . $predio;
    $por_animal = [];
    foreach (filas(
        "SELECT p.animal_id, p.fecha, p.peso_kg, a.caravana_visual, a.numero_interno
           FROM pesadas p
           JOIN animales a ON a.id = p.animal_id
          WHERE p.organizacion_id = ? AND a.estado = 'ACTIVO' AND p.anulada = 0
            AND p.animal_id IN (SELECT animal_id FROM pesadas WHERE organizacion_id = ? AND fecha >= ? AND anulada = 0)" . $del_predio . "
          ORDER BY p.animal_id, p.fecha, p.id",
        [$org, $org, hace_dias($dias)],
    ) as $pesada) {
        $por_animal[(int) $pesada['animal_id']][] = $pesada;
    }

    $alertas = [];
    foreach ($por_animal as $animal_id => $pesadas) {
        $n = count($pesadas);
        if ($n < 2 || $pesadas[$n - 1]['fecha'] < hace_dias($dias)) {
            continue;
        }
        $ultima = $pesadas[$n - 1];
        $previa = $pesadas[$n - 2];
        $alerta = evaluar_variacion_peso((float) $previa['peso_kg'], $previa['fecha'], (float) $ultima['peso_kg'], $ultima['fecha']);
        if ($alerta !== null) {
            $alertas[] = [
                'animal_id' => $animal_id,
                'nombre'    => (string) ($ultima['caravana_visual'] ?? $ultima['numero_interno'] ?? '#' . $animal_id),
                'fecha'     => $ultima['fecha'],
                'peso'      => (float) $ultima['peso_kg'],
                'alerta'    => $alerta,
            ];
        }
    }
    return $alertas;
}

/**
 * Lo que la app de terreno necesita tener en el teléfono para trabajar sin señal.
 */
function catalogo_app(int $org): array
{
    $carencias = GESTION_AVANZADA ? carencias_activas($org) : [];

    $gestaciones = [];
    foreach (filas(
        "SELECT s.vaca_id, s.fecha FROM servicios s LEFT JOIN partos p ON p.servicio_id = s.id
          WHERE s.organizacion_id = ? AND s.resultado IN ('PRENADA', 'PENDIENTE') AND p.id IS NULL",
        [$org],
    ) as $servicio) {
        $gestaciones[(int) $servicio['vaca_id']] = fecha_probable_parto($servicio['fecha']);
    }

    $animales = array_map(static function (array $a) use ($carencias, $gestaciones): array {
        $id = (int) $a['id'];
        return [
            'id'          => $id,
            'diio'        => $a['caravana_visual'],
            'eid'         => $a['eid'],
            'interno'     => $a['numero_interno'],
            'sexo'        => $a['sexo'],
            'categoria'   => $a['categoria'],
            'etapa'       => $a['etapa'],
            'raza'        => $a['raza'],
            'corral_id'   => $a['corral_actual_id'] !== null ? (int) $a['corral_actual_id'] : null,
            'peso'        => $a['peso_actual_kg'] !== null ? (float) $a['peso_actual_kg'] : null,
            'fecha_peso'  => $a['fecha_ultima_pesada'],
            'carencia_hasta' => $carencias[$id]['fecha_habilitacion'] ?? null,
            'parto_probable' => $gestaciones[$id] ?? null,
            'secado'         => $a['fecha_secado'],
        ];
    }, filas(
        "SELECT id, caravana_visual, eid, numero_interno, sexo, categoria, etapa, raza, corral_actual_id,
                peso_actual_kg, fecha_ultima_pesada, fecha_secado
           FROM animales WHERE organizacion_id = ? AND estado = 'ACTIVO'
          ORDER BY caravana_visual, numero_interno",
        [$org],
    ));

    return [
        'generado_en' => ahora(),
        'hoy'         => hoy(),
        'gdp_maxima'  => GDP_MAXIMA_NORMAL,
        // Para que el alta en el teléfono distinga un DIIO de un número interno.
        'formato_diio' => FORMATO_DIIO,
        'animales'    => $animales,
        'corrales'    => array_map(static fn (array $c): array => [
            'id' => (int) $c['id'], 'nombre' => $c['nombre'], 'tipo' => $c['tipo'],
            'capacidad' => (int) $c['capacidad_maxima'], 'ocupacion' => (int) $c['ocupacion'],
        ], filas(
            "SELECT c.id, c.nombre, c.tipo, c.capacidad_maxima,
                    (SELECT COUNT(*) FROM animales a WHERE a.corral_actual_id = c.id AND a.estado = 'ACTIVO') AS ocupacion
               FROM corrales c WHERE c.organizacion_id = ? ORDER BY c.nombre",
            [$org],
        )),
        'raciones'    => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'nombre' => $r['nombre'], 'etapa' => $r['etapa'],
            'materia_seca' => (float) $r['materia_seca_porcentaje'],
        ], filas('SELECT id, nombre, etapa, materia_seca_porcentaje FROM raciones WHERE organizacion_id = ? ORDER BY nombre', [$org])),
        'productos'   => !GESTION_AVANZADA ? [] : array_map(static fn (array $i): array => [
            'id' => (int) $i['id'], 'nombre' => $i['nombre'], 'unidad' => $i['unidad'],
            'carencia' => (int) $i['dias_carencia_carne'], 'stock' => (float) $i['stock_actual'],
        ], filas(
            "SELECT id, nombre, unidad, dias_carencia_carne, stock_actual FROM insumos
              WHERE organizacion_id = ? AND tipo = 'VETERINARIO' ORDER BY nombre",
            [$org],
        )),
    ];
}
