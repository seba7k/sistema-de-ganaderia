<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vistas/layout.php';

exigir_permiso('ver_insumos');

$org = organizacion_actual();
$predio = predio_actual();
$volver = url('/insumos');

/* ============================================================
   Carga: compra de stock y alta de insumo
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_post_valido('gestionar_insumos', $volver);
    $accion = entrada('accion');

    if ($accion === 'compra') {
        $validacion = (new Validacion())
            ->obligatorio('insumo_id', 'El insumo')
            ->obligatorio('fecha', 'La fecha')
            ->fecha('fecha', 'La fecha')
            ->obligatorio('cantidad', 'La cantidad')
            ->numero('cantidad', 'La cantidad', 0.01)
            ->obligatorio('costo_unitario', 'El costo unitario')
            ->numero('costo_unitario', 'El costo unitario', 0);

        $insumo = fila('SELECT * FROM insumos WHERE organizacion_id = ? AND id = ?', [$org, entrada_entera('insumo_id') ?? 0]);
        if ($insumo === null) {
            $validacion->agregar('insumo_id', 'Ese insumo no existe.');
        }

        $validacion->o_volver($volver);

        $cantidad = entrada_decimal('cantidad') ?? 0.0;
        $costo = entrada_decimal('costo_unitario') ?? 0.0;
        $stock_previo = (float) $insumo['stock_actual'];

        /* Costo promedio ponderado: mezclar lo que quedaba con lo que entra. Si
           no, una compra cara haría parecer que todo el stock viejo costó eso. */
        $costo_promedio = ($stock_previo + $cantidad) > 0
            ? (($stock_previo * (float) $insumo['costo_unitario']) + ($cantidad * $costo)) / ($stock_previo + $cantidad)
            : $costo;

        db()->beginTransaction();
        try {
            consultar(
                'INSERT INTO movimientos_stock (organizacion_id, insumo_id, tipo, fecha, cantidad,
                                                costo_unitario, costo_total, observaciones, responsable_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $org, (int) $insumo['id'], 'COMPRA', entrada('fecha'), $cantidad, $costo,
                    round($cantidad * $costo), entrada('observaciones') !== '' ? entrada('observaciones') : null,
                    usuario_actual()['id'],
                ],
            );
            consultar(
                'UPDATE insumos SET stock_actual = stock_actual + ?, costo_unitario = ? WHERE id = ? AND organizacion_id = ?',
                [$cantidad, round($costo_promedio, 2), (int) $insumo['id'], $org],
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
            'Compra registrada: ' . numero($cantidad, 1) . ' de ' . $insumo['nombre']
            . '. Stock: ' . numero($stock_previo + $cantidad, 1) . '.',
        );
        redirigir($volver);
    }

    if ($accion === 'nuevo') {
        $validacion = (new Validacion())
            ->obligatorio('nombre', 'El nombre')
            ->obligatorio('tipo', 'El tipo');

        foreach ([
            'tipo'   => ['ALIMENTO', 'VETERINARIO', 'OTRO'],
            'unidad' => ['KILOGRAMO', 'LITRO', 'MILILITRO', 'DOSIS', 'UNIDAD'],
        ] as $campo_lista => $validos) {
            if (!in_array(entrada($campo_lista), $validos, true)) {
                $validacion->agregar($campo_lista, 'Ese valor no es válido.');
            }
        }

        $validacion
            ->numero('stock_minimo', 'El stock mínimo', 0)
            ->numero('costo_unitario', 'El costo unitario', 0);

        $repetido = valor(
            'SELECT COUNT(*) FROM insumos WHERE organizacion_id = ? AND nombre = ?',
            [$org, entrada('nombre')],
        );
        if ((int) $repetido > 0) {
            $validacion->agregar('nombre', 'Ya hay un insumo con ese nombre.');
        }

        // Un producto veterinario sin días de carencia dejaría sin calcular el
        // período de retiro de todos los animales que lo reciban.
        if (entrada('tipo') === 'VETERINARIO' && entrada('dias_carencia_carne') === '') {
            $validacion->agregar('dias_carencia_carne', 'Un producto veterinario necesita sus días de carencia.');
        }

        $validacion->o_volver($volver);

        consultar(
            'INSERT INTO insumos (organizacion_id, establecimiento_id, nombre, tipo, unidad, stock_actual,
                                  stock_minimo, costo_unitario, dias_carencia_carne, principio_activo, materia_seca_porcentaje)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)',
            [
                $org,
                $predio ?? (int) valor('SELECT MIN(id) FROM establecimientos WHERE organizacion_id = ?', [$org]),
                entrada('nombre'), entrada('tipo'), entrada('unidad'),
                entrada_decimal('stock_minimo'), entrada_decimal('costo_unitario') ?? 0,
                entrada('tipo') === 'VETERINARIO' ? entrada_entera('dias_carencia_carne') : null,
                entrada('principio_activo') !== '' ? entrada('principio_activo') : null,
                entrada('tipo') === 'ALIMENTO' ? entrada_decimal('materia_seca_porcentaje') : null,
            ],
        );

        flash('exito', 'Insumo agregado al catálogo: ' . entrada('nombre') . '. Registrá una compra para cargarle stock.');
        redirigir($volver);
    }

    flash('error', 'No se reconoció qué se quería registrar.');
    redirigir($volver);
}

$insumos = filas(
    'SELECT * FROM insumos WHERE organizacion_id = ?' . filtro_predio('insumos') . ' ORDER BY tipo, nombre',
    [$org],
);

$por_reponer = array_filter(
    $insumos,
    static fn (array $i): bool => $i['stock_minimo'] !== null
        && (float) $i['stock_actual'] <= (float) $i['stock_minimo'],
);
$valorizado = array_sum(array_map(
    static fn (array $i): float => (float) $i['stock_actual'] * (float) $i['costo_unitario'],
    $insumos,
));

$grupos = [
    'ALIMENTO'    => ['titulo' => 'Alimento', 'sub' => 'Materias primas de la ración'],
    'VETERINARIO' => ['titulo' => 'Veterinarios', 'sub' => 'Cada uno con sus días de carencia'],
];

abrir_pagina(
    'Insumos',
    'Qué hay en bodega y qué está por acabarse.',
    '/insumos',
    ['Insumos'],
);

/* ---------- Formularios de carga ---------- */

$errores = errores_formulario();
$fallo = valor_previo('accion');

$insumos_opciones = ['' => 'Elegí el insumo'];
foreach ($insumos as $insumo_opcion) {
    $insumos_opciones[(int) $insumo_opcion['id']] = $insumo_opcion['nombre']
        . ' — quedan ' . numero((float) $insumo_opcion['stock_actual'], 1);
}

echo abrir_alta('Registrar compra', 'Suma stock y recalcula el costo promedio', $errores !== [] && $fallo === 'compra');
echo formulario(url('/insumos'), implode('', [
    '<input type="hidden" name="accion" value="compra">',
    campo('insumo_id', 'Insumo', $errores, 'select', '', ['items' => $insumos_opciones, 'obligatorio' => true]),
    campo('fecha', 'Fecha', $errores, 'date', hoy(), ['obligatorio' => true, 'max' => hoy()]),
    campo('cantidad', 'Cantidad', $errores, 'number', '', [
        'obligatorio' => true, 'step' => '0.1', 'min' => '0.1', 'ayuda' => 'En la unidad del insumo',
    ]),
    campo('costo_unitario', 'Costo por unidad', $errores, 'number', '', [
        'obligatorio' => true, 'step' => '1', 'min' => '0', 'ayuda' => 'Lo que se pagó esta vez',
    ]),
    campo('observaciones', 'Observaciones', $errores, 'text', '', ['placeholder' => 'Proveedor, factura…']),
]), 'Registrar compra');
echo cerrar_alta();

echo abrir_alta('Agregar insumo al catálogo', 'Un producto nuevo, sin stock todavía', $errores !== [] && $fallo === 'nuevo');
echo formulario(url('/insumos'), implode('', [
    '<input type="hidden" name="accion" value="nuevo">',
    campo('nombre', 'Nombre', $errores, 'text', '', ['obligatorio' => true, 'placeholder' => 'Ej: Maíz partido']),
    campo('tipo', 'Tipo', $errores, 'select', 'ALIMENTO', ['items' => [
        'ALIMENTO' => 'Alimento', 'VETERINARIO' => 'Veterinario', 'OTRO' => 'Otro',
    ]]),
    campo('unidad', 'Unidad', $errores, 'select', 'KILOGRAMO', ['items' => [
        'KILOGRAMO' => 'Kilogramo', 'LITRO' => 'Litro', 'MILILITRO' => 'Mililitro',
        'DOSIS' => 'Dosis', 'UNIDAD' => 'Unidad',
    ]]),
    campo('stock_minimo', 'Stock mínimo', $errores, 'number', '', [
        'step' => '0.1', 'min' => '0', 'ayuda' => 'Debajo de esto avisa que hay que reponer',
    ]),
    campo('costo_unitario', 'Costo por unidad', $errores, 'number', '', ['step' => '1', 'min' => '0']),
    campo('dias_carencia_carne', 'Días de carencia', $errores, 'number', '', [
        'min' => '0', 'ayuda' => 'Obligatorio en productos veterinarios',
    ]),
    campo('principio_activo', 'Principio activo', $errores, 'text', '', ['placeholder' => 'Solo veterinarios']),
    campo('materia_seca_porcentaje', 'Materia seca (%)', $errores, 'number', '', [
        'step' => '0.1', 'min' => '0', 'max' => '100', 'ayuda' => 'Solo alimentos',
    ]),
]), 'Agregar al catálogo');
echo cerrar_alta();
?>

<section class="indicadores">
  <div class="indicador <?= $por_reponer !== [] ? 'riesgo' : 'ok' ?>">
    <div class="rotulo">Por reponer</div>
    <div class="cifra"><?= numero(count($por_reponer)) ?></div>
    <div class="nota">en el mínimo o por debajo</div>
  </div>
  <div class="indicador">
    <div class="rotulo">Stock valorizado</div>
    <div class="cifra" style="font-size: 25px"><?= pesos($valorizado) ?></div>
    <div class="nota">a costo promedio</div>
  </div>
  <div class="indicador">
    <div class="rotulo">Insumos</div>
    <div class="cifra"><?= numero(count($insumos)) ?></div>
    <div class="nota">en catálogo</div>
  </div>
</section>

<?php foreach ($grupos as $tipo => $encabezado): ?>
  <?php $del_grupo = array_values(array_filter($insumos, static fn (array $i): bool => $i['tipo'] === $tipo)); ?>
  <?php if ($del_grupo === []) { continue; } ?>
  <div class="bloque">
    <header>
      <div>
        <h2><?= h($encabezado['titulo']) ?></h2>
        <div class="sub"><?= h($encabezado['sub']) ?></div>
      </div>
    </header>
    <div class="tabla-scroll">
      <table>
        <thead>
          <tr>
            <th>Insumo</th>
            <th class="num">Stock</th>
            <th style="width: 190px">Nivel</th>
            <th class="num">Valorizado</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($del_grupo as $insumo): ?>
          <?php
          $stock = (float) $insumo['stock_actual'];
          $minimo = $insumo['stock_minimo'] !== null ? (float) $insumo['stock_minimo'] : null;
          $bajo = $minimo !== null && $stock <= $minimo;
          // El nivel se muestra contra tres veces el mínimo, que es el punto
          // donde ya no hay por qué preocuparse.
          $nivel = $minimo !== null && $minimo > 0
              ? (int) min(round($stock / ($minimo * 3) * 100), 100)
              : 100;
          ?>
          <tr>
            <td>
              <span class="principal"><?= h($insumo['nombre']) ?></span>
              <div class="secundario">
                <?php if ($tipo === 'VETERINARIO'): ?>
                  <?= h($insumo['principio_activo'] ?? '—') ?> · carencia <?= (int) $insumo['dias_carencia_carne'] ?> días
                <?php else: ?>
                  <?= numero((float) $insumo['materia_seca_porcentaje'], 1) ?>% de materia seca
                <?php endif; ?>
              </div>
            </td>
            <td class="num">
              <span class="principal"><?= numero($stock, 0) ?></span>
              <div class="secundario"><?= h(mb_strtolower(legible($insumo['unidad']))) ?></div>
            </td>
            <td><?= medidor($nivel) ?></td>
            <td class="num"><?= pesos($stock * (float) $insumo['costo_unitario']) ?></td>
            <td><?= $bajo ? insignia('Reponer', 'riesgo') : insignia('En nivel', 'ok') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>

<?php cerrar_pagina(); ?>
