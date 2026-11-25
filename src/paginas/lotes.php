<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vistas/layout.php';
require_once __DIR__ . '/../dominio/cierre.php';
require_once __DIR__ . '/../dominio/indicadores.php';

exigir_permiso('gestionar_lotes');

$org = organizacion_actual();
$volver = url('/lotes');

/* ============================================================
   Registrar una venta de hacienda
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_post_valido('registrar_venta', $volver);

    $validacion = (new Validacion())
        ->obligatorio('lote_id', 'El lote')
        ->obligatorio('fecha', 'La fecha')
        ->fecha('fecha', 'La fecha')
        ->obligatorio('cantidad_animales', 'La cantidad de animales')
        ->numero('cantidad_animales', 'La cantidad de animales', 1)
        ->obligatorio('peso_total_kg', 'El peso total')
        ->numero('peso_total_kg', 'El peso total', 1)
        ->obligatorio('precio_por_kg', 'El precio por kilo')
        ->numero('precio_por_kg', 'El precio por kilo', 1)
        ->numero('descuentos', 'Los descuentos', 0);

    $lote_vendido = fila(
        'SELECT * FROM lotes l WHERE l.organizacion_id = ? AND l.id = ?' . filtro_predio('l'),
        [$org, entrada_entera('lote_id') ?? 0],
    );
    if ($lote_vendido === null) {
        $validacion->agregar('lote_id', 'Ese lote no existe.');
    }

    $cantidad = (int) (entrada_entera('cantidad_animales') ?? 0);
    $en_lote = $lote_vendido === null ? [] : filas(
        "SELECT id FROM animales
          WHERE organizacion_id = ? AND lote_actual_id = ? AND estado = 'ACTIVO'
          ORDER BY peso_actual_kg DESC",
        [$org, (int) $lote_vendido['id']],
    );

    /* Bloqueo de faena: un animal con un medicamento en período de carencia no
       puede venderse ni ir al matadero, porque el residuo llega a la carne. Esos
       animales ni siquiera se ofrecen: la venta toma solo los aptos. */
    $carencias = carencias_activas($org, predio_actual());
    $disponibles = array_values(array_filter(
        $en_lote,
        static fn (array $animal): bool => !isset($carencias[(int) $animal['id']]),
    ));
    $bloqueados = count($en_lote) - count($disponibles);

    if ($lote_vendido !== null && $cantidad > count($disponibles)) {
        $validacion->agregar(
            'cantidad_animales',
            'El lote tiene ' . numero(count($disponibles)) . ' animales aptos para faena y se quieren vender ' . numero($cantidad) . '.'
            . ($bloqueados > 0
                ? ' Hay ' . numero($bloqueados) . ' bloqueados por carencia de medicamentos: no se pueden vender hasta que se cumpla.'
                : ''),
        );
    }

    $validacion->o_volver($volver);

    $peso = entrada_decimal('peso_total_kg') ?? 0.0;
    $precio = entrada_decimal('precio_por_kg') ?? 0.0;
    $descuentos = entrada_decimal('descuentos') ?? 0.0;
    $bruto = $peso * $precio;

    db()->beginTransaction();
    try {
        consultar(
            'INSERT INTO ventas_hacienda (organizacion_id, lote_id, fecha, destino,
                                          comprador, cantidad_animales, peso_total_kg, precio_por_kg, monto_neto)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $org, (int) $lote_vendido['id'], entrada('fecha'), entrada('destino'),
                entrada('comprador') !== '' ? entrada('comprador') : null,
                $cantidad, $peso, $precio, round($bruto - $descuentos),
            ],
        );

        // Se venden primero los más pesados, que es como sale la hacienda terminada.
        $vendidos = array_slice($disponibles, 0, $cantidad);
        foreach ($vendidos as $animal) {
            consultar(
                "UPDATE animales SET estado = 'VENDIDO', fecha_egreso = ?, corral_actual_id = NULL
                  WHERE id = ? AND organizacion_id = ?",
                [entrada('fecha'), (int) $animal['id'], $org],
            );
        }

        $quedan = count($en_lote) - $cantidad;
        consultar(
            'UPDATE lotes SET cabezas_actuales = ? WHERE id = ? AND organizacion_id = ?',
            [$quedan, (int) $lote_vendido['id'], $org],
        );

        // Sin animales, el lote cierra: su resultado económico ya es definitivo.
        if ($quedan === 0) {
            consultar(
                "UPDATE lotes SET estado = 'CERRADO', fecha_cierre = ? WHERE id = ? AND organizacion_id = ?",
                [entrada('fecha'), (int) $lote_vendido['id'], $org],
            );
        }

        consultar(
            'INSERT INTO movimientos_hacienda (organizacion_id, tipo, fecha, corral_origen_id, corral_destino_id,
                                               cantidad_animales, motivo, responsable_id)
             VALUES (?, ?, ?, NULL, NULL, ?, ?, ?)',
            [
                $org, 'EGRESO_VENTA', entrada('fecha'), $cantidad,
                'Venta a ' . mb_strtolower(legible(entrada('destino'))), usuario_actual()['id'],
            ],
        );

        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }

    flash(
        'exito',
        'Venta registrada: ' . numero($cantidad) . ' animales por ' . pesos($bruto - $descuentos)
        . ($quedan === 0 ? '. El lote quedó cerrado.' : '. Quedan ' . numero($quedan) . ' cabezas en el lote.'),
    );
    redirigir($volver);
}

$lotes = filas(
    'SELECT l.* FROM lotes l WHERE l.organizacion_id = ?' . filtro_predio('l') . ' ORDER BY l.fecha_ingreso DESC',
    [$org],
);

/** Cierre económico de un lote a partir de sus costos e ingresos reales. */
function cierre_de_lote(array $lote, int $org): array
{
    $sumar = static fn (string $sql) => (float) valor($sql, [$org, $lote['id']]);

    $alimentacion = $sumar('SELECT COALESCE(SUM(costo_total), 0) FROM entregas_alimento WHERE organizacion_id = ? AND lote_id = ? AND anulada = 0');
    $sanidad      = $sumar('SELECT COALESCE(SUM(costo_total), 0) FROM tratamientos WHERE organizacion_id = ? AND lote_id = ? AND anulada = 0');
    $ingresos     = $sumar('SELECT COALESCE(SUM(monto_neto), 0) FROM ventas_hacienda WHERE organizacion_id = ? AND lote_id = ?');
    $peso_vendido = $sumar('SELECT COALESCE(SUM(peso_total_kg), 0) FROM ventas_hacienda WHERE organizacion_id = ? AND lote_id = ?');
    $peso_actual  = $sumar("SELECT COALESCE(SUM(COALESCE(peso_actual_kg, peso_ingreso_kg)), 0) FROM animales WHERE organizacion_id = ? AND lote_actual_id = ? AND estado = 'ACTIVO'");

    $vendidas = (int) valor('SELECT COALESCE(SUM(cantidad_animales), 0) FROM ventas_hacienda WHERE organizacion_id = ? AND lote_id = ?', [$org, $lote['id']]);
    $muertas  = (int) valor('SELECT COUNT(*) FROM mortalidades WHERE organizacion_id = ? AND lote_id = ? AND anulada = 0', [$org, $lote['id']]);

    $dias = dias_entre($lote['fecha_ingreso'], $lote['fecha_cierre'] ?? date('Y-m-d'));
    $kg_producidos = $peso_actual + $peso_vendido - (float) $lote['peso_ingreso_total_kg'];

    $cierre = calcular_cierre(
        [
            'adquisicion'  => (float) $lote['costo_adquisicion_total'],
            'alimentacion' => $alimentacion,
            'sanidad'      => $sanidad,
            'otros'        => 0.0,
        ],
        $ingresos,
        (int) $lote['cabezas_iniciales'],
        $vendidas,
        $muertas,
        $kg_producidos,
        $dias,
    );

    return $cierre + [
        'alimentacion'  => $alimentacion,
        'sanidad'       => $sanidad,
        'kg_producidos' => $kg_producidos,
        'dias'          => $dias,
        'vendidas'      => $vendidas,
        'muertas'       => $muertas,
        'vendido'       => $ingresos > 0,
        'adg'           => $dias > 0 && (int) $lote['cabezas_iniciales'] > 0
            ? round($kg_producidos / (int) $lote['cabezas_iniciales'] / $dias, 2)
            : null,
    ];
}

$cierres = [];
foreach ($lotes as $lote) {
    $cierres[$lote['id']] = cierre_de_lote($lote, $org);
}

abrir_pagina(
    'Lotes y cierres',
    'Cuánto costó cada lote y cuánto dejó. El detalle se abre por lote.',
    '/lotes',
    ['Lotes y cierres'],
);

/* ---------- Formulario de carga ---------- */

$errores = errores_formulario();

// Cuántos animales de cada lote están bloqueados para faena, para decirlo antes de vender.
$carencias_venta = carencias_activas($org, predio_actual());
$bloqueados_por_lote = [];
foreach (filas(
    "SELECT id, lote_actual_id FROM animales WHERE organizacion_id = ? AND estado = 'ACTIVO' AND lote_actual_id IS NOT NULL",
    [$org],
) as $animal_lote) {
    if (isset($carencias_venta[(int) $animal_lote['id']])) {
        $lote_bloqueado = (int) $animal_lote['lote_actual_id'];
        $bloqueados_por_lote[$lote_bloqueado] = ($bloqueados_por_lote[$lote_bloqueado] ?? 0) + 1;
    }
}

$lotes_opciones = ['' => 'Elegí el lote'];
foreach ($lotes as $lote_opcion) {
    if ($lote_opcion['estado'] !== 'CERRADO') {
        $bloqueadas = $bloqueados_por_lote[(int) $lote_opcion['id']] ?? 0;
        $lotes_opciones[(int) $lote_opcion['id']] = $lote_opcion['codigo']
            . ' — ' . numero((int) $lote_opcion['cabezas_actuales']) . ' cabezas'
            . ($bloqueadas > 0 ? ' (' . numero($bloqueadas) . ' bloqueadas por carencia)' : '');
    }
}

echo abrir_alta('Registrar venta o envío a faena', 'Solo animales sin carencia; cierra el lote si se vacía', $errores !== []);
echo formulario(url('/lotes'), implode('', [
    campo('lote_id', 'Lote', $errores, 'select', '', ['items' => $lotes_opciones, 'obligatorio' => true]),
    campo('fecha', 'Fecha', $errores, 'date', hoy(), ['obligatorio' => true, 'max' => hoy()]),
    campo('destino', 'Destino', $errores, 'select', 'FRIGORIFICO', ['items' => [
        'FRIGORIFICO' => 'Frigorífico', 'FERIA' => 'Feria',
        'PARTICULAR' => 'Particular', 'EXPORTACION' => 'Exportación',
    ]]),
    campo('comprador', 'Comprador', $errores, 'text', '', ['placeholder' => 'Ej: Frigorífico Temuco']),
    campo('cantidad_animales', 'Cabezas', $errores, 'number', '', ['obligatorio' => true, 'min' => '1']),
    campo('peso_total_kg', 'Peso de salida', $errores, 'number', '', [
        'obligatorio' => true, 'step' => '0.5', 'min' => '1', 'ayuda' => 'Kilos de romana de todo el grupo',
    ]),
    campo('precio_por_kg', 'Precio pactado por kilo', $errores, 'number', '', ['obligatorio' => true, 'step' => '1', 'min' => '1']),
    campo('descuentos', 'Descuentos', $errores, 'number', '', [
        'step' => '1', 'min' => '0', 'ayuda' => 'Comisiones, fletes, merma de romana',
    ]),
]), 'Registrar venta');
echo cerrar_alta();
?>

<div class="mensaje info">
  <span class="marca-aviso">ℹ</span>
  <div>
    <strong>Los lotes sin vender muestran margen negativo,</strong> y está bien:
    acumularon costos y todavía no generaron ingresos. El resultado real se conoce
    al vender.
  </div>
</div>

<div class="bloque">
  <header>
    <div>
      <h2>Resumen</h2>
      <div class="sub">Un lote por fila. Tocá «Ver detalle» para abrir sus números.</div>
    </div>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead>
        <tr>
          <th>Lote</th><th class="num">Cabezas</th><th class="num">Días</th>
          <th class="num">Ganancia diaria</th><th class="num">Costo por kg</th>
          <th class="num">Margen neto</th><th>Estado</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($lotes as $lote): ?>
        <?php $c = $cierres[$lote['id']]; ?>
        <tr data-ir="<?= h(url_con('/hacienda', ['lote' => (int) $lote['id']])) ?>">
          <td>
            <a class="principal" href="<?= h(url_con('/hacienda', ['lote' => (int) $lote['id']])) ?>"><?= h($lote['codigo']) ?></a>
            <div class="secundario"><?= h($lote['nombre'] ?? '') ?></div>
          </td>
          <td class="num"><?= numero((int) $lote['cabezas_actuales']) ?>
            <div class="secundario">de <?= numero((int) $lote['cabezas_iniciales']) ?></div>
          </td>
          <td class="num"><?= numero($c['dias']) ?></td>
          <td class="num"><?= $c['adg'] === null ? '—' : numero((float) $c['adg'], 2) . ' kg' ?></td>
          <td class="num"><?= pesos($c['costo_por_kg_producido']) ?></td>
          <td class="num principal" style="color: <?= $c['vendido'] ? ($c['margen_bruto'] >= 0 ? 'var(--ok)' : 'var(--riesgo)') : 'var(--tinta-3)' ?>">
            <?= $c['vendido'] ? pesos($c['margen_bruto']) : 'Sin vender' ?>
          </td>
          <td><?= insignia(legible($lote['estado']), $lote['estado'] === 'CERRADO' ? 'neutro' : 'ok') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($lotes === []): ?>
        <tr><td colspan="7" class="vacio">No hay lotes cargados.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($lotes as $lote): ?>
  <?php $c = $cierres[$lote['id']]; ?>
  <details class="bloque desplegable-lote">
    <summary>
      <span>
        <span class="principal"><?= h($lote['codigo']) ?></span>
        <span class="secundario">
          <?= h($lote['nombre'] ?? '') ?> · ingresó el <?= fecha_corta($lote['fecha_ingreso']) ?>
        </span>
      </span>
      <span class="abrir">Ver detalle</span>
    </summary>

    <div class="interior">
      <section class="indicadores" style="margin-bottom: var(--e3)">
        <div class="indicador">
          <div class="rotulo">Kilos producidos</div>
          <div class="cifra" style="font-size: 25px"><?= numero($c['kg_producidos']) ?></div>
          <div class="nota">en <?= numero($c['dias']) ?> días</div>
        </div>
        <div class="indicador <?= (float) $c['mortalidad_porcentaje'] > 2 ? 'aviso' : 'ok' ?>">
          <div class="rotulo">Mortalidad</div>
          <div class="cifra" style="font-size: 25px"><?= numero((float) $c['mortalidad_porcentaje'], 1) ?><small>%</small></div>
          <div class="nota"><?= numero($c['muertas']) ?> cabeza(s)</div>
        </div>
        <div class="indicador">
          <div class="rotulo">Costo por kg producido</div>
          <div class="cifra" style="font-size: 25px"><?= pesos($c['costo_por_kg_producido']) ?></div>
          <div class="nota">no incluye la compra del animal</div>
        </div>
        <div class="indicador <?= $c['vendido'] ? ($c['margen_bruto'] >= 0 ? 'ok' : 'riesgo') : '' ?>">
          <div class="rotulo">Margen</div>
          <div class="cifra" style="font-size: 25px"><?= $c['vendido'] ? pesos($c['margen_bruto']) : '—' ?></div>
          <div class="nota"><?= $c['vendido'] ? numero((float) $c['rentabilidad_porcentaje'], 1) . '% sobre ingresos' : 'sin ventas todavía' ?></div>
        </div>
      </section>

      <div class="tabla-scroll">
        <!-- Sin ordenar ni filtrar: las filas de total y margen perderían sentido fuera de su orden. -->
        <table data-sin-orden data-sin-buscador>
          <thead><tr><th>Concepto</th><th class="num">Total</th><th class="num">Por cabeza</th></tr></thead>
          <tbody>
            <?php $por_cabeza = static fn (float $monto): string => pesos($monto / max((int) $lote['cabezas_iniciales'], 1)); ?>
            <tr><td>Compra de hacienda</td><td class="num"><?= pesos((float) $lote['costo_adquisicion_total']) ?></td><td class="num"><?= $por_cabeza((float) $lote['costo_adquisicion_total']) ?></td></tr>
            <tr><td>Alimentación</td><td class="num"><?= pesos($c['alimentacion']) ?></td><td class="num"><?= $por_cabeza($c['alimentacion']) ?></td></tr>
            <tr><td>Sanidad</td><td class="num"><?= pesos($c['sanidad']) ?></td><td class="num"><?= $por_cabeza($c['sanidad']) ?></td></tr>
            <tr style="background: #fbfcfb"><td class="principal">Costo total</td><td class="num principal"><?= pesos($c['costo_total']) ?></td><td class="num"><?= $por_cabeza((float) $c['costo_total']) ?></td></tr>
            <tr><td>Ingresos por venta</td><td class="num"><?= pesos($c['ingreso_total']) ?></td><td class="num"><?= $c['vendidas'] > 0 ? pesos((float) $c['ingreso_total'] / $c['vendidas']) : '—' ?></td></tr>
            <tr style="background: #fbfcfb">
              <td class="principal">Margen</td>
              <td class="num principal" style="color: <?= $c['margen_bruto'] >= 0 ? 'var(--ok)' : 'var(--riesgo)' ?>"><?= pesos($c['margen_bruto']) ?></td>
              <td class="num"><?= pesos($c['margen_por_cabeza']) ?></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </details>
<?php endforeach; ?>

<?php cerrar_pagina(); ?>
