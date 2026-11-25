<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vistas/layout.php';
require_once __DIR__ . '/../dominio/indicadores.php';

exigir_permiso('ver_alimentacion');

$org = organizacion_actual();
$predio = predio_actual();
$volver = url('/alimentacion');

// Las entregas de un predio son las de sus corrales y las de sus animales.
$del_predio = $predio === null ? '' : ' AND (c.establecimiento_id = ' . $predio . ' OR a.establecimiento_id = ' . $predio . ')';

const ETAPAS_RACION = [
    'RECEPCION'   => 'Recepción',
    'CRECIMIENTO' => 'Crecimiento',
    'TERMINACION' => 'Terminación',
    'CRIA'        => 'Cría',
    'SUPLEMENTO'  => 'Suplementación',
];

/** Filas de ingredientes que ofrece el formulario de ración. */
const INGREDIENTES_POR_RACION = 5;

/* ============================================================
   Carga: entrega de alimento y ración nueva con su receta
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (entrada('accion') === 'racion') {
        exigir_post_valido('gestionar_raciones', $volver);

        $validacion = (new Validacion())
            ->obligatorio('nombre', 'El nombre')
            ->obligatorio('etapa', 'La etapa');

        if (entrada('etapa') !== '' && !isset(ETAPAS_RACION[entrada('etapa')])) {
            $validacion->agregar('etapa', 'Esa etapa no existe.');
        }
        if (entrada('nombre') !== '' && (int) valor(
            'SELECT COUNT(*) FROM raciones WHERE organizacion_id = ? AND nombre = ?',
            [$org, entrada('nombre')],
        ) > 0) {
            $validacion->agregar('nombre', 'Ya hay una ración con ese nombre.');
        }

        /* La receta dice de qué insumos de bodega se hace la mezcla y en qué
           proporción. Es lo que permite descontar el stock en cada entrega. Sin
           gestión avanzada no hay bodega ni pantalla de insumos, así que la
           ración se describe con su materia seca y su costo, y se guarda sin
           receta: exigirla dejaba la alimentación sin forma de cargarse. */
        if (!GESTION_AVANZADA) {
            $validacion
                ->numero('materia_seca_porcentaje', 'La materia seca', 1, 100)
                ->numero('costo_por_kg', 'El costo por kilo', 0)
                ->o_volver($volver);

            $ms_simple = entrada_decimal('materia_seca_porcentaje') ?? 88.0;
            consultar(
                'INSERT INTO raciones (organizacion_id, nombre, etapa, materia_seca_porcentaje, costo_por_kg) VALUES (?, ?, ?, ?, ?)',
                [$org, entrada('nombre'), entrada('etapa'), round($ms_simple, 1), round(entrada_decimal('costo_por_kg') ?? 0, 2)],
            );
            flash(
                'exito',
                'Ración «' . entrada('nombre') . '» creada: ' . numero($ms_simple, 1) . '% de materia seca.',
            );
            redirigir($volver);
        }

        $receta = [];
        $suma = 0.0;
        for ($i = 1; $i <= INGREDIENTES_POR_RACION; $i++) {
            $insumo_id = entrada_entera('insumo_' . $i);
            $porcentaje = entrada_decimal('porcentaje_' . $i);
            if ($insumo_id === null && $porcentaje === null) {
                continue;
            }
            if ($insumo_id === null || $porcentaje === null || $porcentaje <= 0) {
                $validacion->agregar('insumo_' . $i, 'El ingrediente ' . $i . ' necesita insumo y porcentaje.');
                continue;
            }
            if (isset($receta[$insumo_id])) {
                $validacion->agregar('insumo_' . $i, 'El ingrediente ' . $i . ' está repetido.');
                continue;
            }
            $insumo = fila(
                "SELECT * FROM insumos WHERE organizacion_id = ? AND id = ? AND tipo = 'ALIMENTO'",
                [$org, $insumo_id],
            );
            if ($insumo === null) {
                $validacion->agregar('insumo_' . $i, 'El ingrediente ' . $i . ' no es un alimento de bodega.');
                continue;
            }
            $receta[$insumo_id] = ['insumo' => $insumo, 'porcentaje' => $porcentaje];
            $suma += $porcentaje;
        }

        if ($receta === [] && !$validacion->hay_errores()) {
            $validacion->agregar('insumo_1', 'La ración necesita al menos un ingrediente.');
        } elseif ($receta !== [] && abs($suma - 100) > 0.5) {
            $validacion->agregar('porcentaje_1', 'Los porcentajes suman ' . numero($suma, 1) . '% y tienen que sumar 100%.');
        }

        $validacion->o_volver($volver);

        // Materia seca y costo de la mezcla, ponderados por la receta.
        $materia_seca = 0.0;
        $costo = 0.0;
        foreach ($receta as $parte) {
            $materia_seca += $parte['porcentaje'] / 100 * (float) ($parte['insumo']['materia_seca_porcentaje'] ?? 100);
            $costo += $parte['porcentaje'] / 100 * (float) $parte['insumo']['costo_unitario'];
        }

        en_transaccion(static function () use ($org, $receta, $materia_seca, $costo): void {
            consultar(
                'INSERT INTO raciones (organizacion_id, nombre, etapa, materia_seca_porcentaje, costo_por_kg) VALUES (?, ?, ?, ?, ?)',
                [$org, entrada('nombre'), entrada('etapa'), round($materia_seca, 1), round($costo, 2)],
            );
            $racion_id = ultimo_id();
            foreach ($receta as $insumo_id => $parte) {
                consultar(
                    'INSERT INTO ingredientes_racion (organizacion_id, racion_id, insumo_id, porcentaje) VALUES (?, ?, ?, ?)',
                    [$org, $racion_id, $insumo_id, $parte['porcentaje']],
                );
            }
        });

        flash(
            'exito',
            'Ración «' . entrada('nombre') . '» creada: ' . numero($materia_seca, 1) . '% de materia seca y '
            . pesos($costo) . ' por kilo. Cada entrega descontará sus ingredientes de bodega.',
        );
        redirigir($volver);
    }

    if (entrada('accion') === 'anular') {
        exigir_post_valido('anular_registro', $volver);
        try {
            $resultado = op_anular($org, (int) usuario_actual()['id'], $_POST);
        } catch (ErrorOperacion $error) {
            volver_con_errores($error, $volver);
        }
        flash('exito', $resultado['mensaje']);
        redirigir($volver);
    }

    exigir_post_valido('registrar_entrega', $volver);
    try {
        $resultado = op_entrega($org, (int) usuario_actual()['id'], $_POST);
    } catch (ErrorOperacion $error) {
        volver_con_errores($error, $volver);
    }
    flash('exito', $resultado['mensaje']);
    redirigir($volver);
}

$raciones = filas('SELECT * FROM raciones WHERE organizacion_id = ? ORDER BY etapa, nombre', [$org]);

$recetas = [];
foreach (filas(
    'SELECT ir.racion_id, ir.porcentaje, i.nombre
       FROM ingredientes_racion ir JOIN insumos i ON i.id = ir.insumo_id
      WHERE ir.organizacion_id = ?
      ORDER BY ir.porcentaje DESC',
    [$org],
) as $ingrediente) {
    $recetas[(int) $ingrediente['racion_id']][] = $ingrediente;
}

$entregas = filas(
    'SELECT e.*, c.nombre AS corral, r.nombre AS racion, l.codigo AS lote,
            a.caravana_visual AS animal_caravana, a.numero_interno AS animal_interno,
            u.nombre AS responsable_nombre
       FROM entregas_alimento e
       JOIN raciones r ON r.id = e.racion_id
       LEFT JOIN corrales c ON c.id = e.corral_id
       LEFT JOIN animales a ON a.id = e.animal_id
       LEFT JOIN lotes l ON l.id = e.lote_id
       LEFT JOIN usuarios u ON u.id = e.responsable_id
      WHERE e.organizacion_id = ? AND e.anulada = 0' . $del_predio . '
      ORDER BY e.fecha DESC, e.id DESC
      LIMIT 40',
    [$org],
);

$resumen = fila(
    'SELECT SUM(e.kg_entregados) AS kg, SUM(e.kg_materia_seca) AS kg_ms, SUM(e.costo_total) AS costo,
            SUM(e.kg_planificados) AS kg_planificados
       FROM entregas_alimento e
       LEFT JOIN corrales c ON c.id = e.corral_id
       LEFT JOIN animales a ON a.id = e.animal_id
      WHERE e.organizacion_id = ? AND e.fecha >= ? AND e.anulada = 0' . $del_predio,
    [$org, hace_dias(30)],
);

$kg_ganados = (float) valor(
    "SELECT SUM(a.peso_actual_kg - a.peso_ingreso_kg) FROM animales a
      WHERE a.organizacion_id = ? AND a.estado = 'ACTIVO' AND a.peso_actual_kg IS NOT NULL" . filtro_predio('a'),
    [$org],
);
$fcr = calcular_fcr((float) ($resumen['kg_ms'] ?? 0), $kg_ganados);

$por_reponer = !GESTION_AVANZADA ? [] : filas(
    "SELECT i.nombre, i.stock_actual, i.stock_minimo FROM insumos i
      WHERE i.organizacion_id = ? AND i.tipo = 'ALIMENTO' AND i.stock_minimo IS NOT NULL
        AND i.stock_actual <= i.stock_minimo" . filtro_predio('i') . "
      ORDER BY i.nombre",
    [$org],
);

abrir_pagina(
    'Alimentación',
    'Lo que se entregó, lo que costó y cuántos kilos de alimento hizo falta por kilo de carne.',
    '/alimentacion',
    ['Alimentación'],
);

echo ayuda_pantalla('¿Cómo se lee esta pantalla?', [
    'Tal cual' => 'Los kilos como salen del mixer, con el agua que trae el alimento incluida.',
    'Materia seca' => 'Los mismos kilos descontada el agua. Es la base para comparar: un silo al 34% y un maíz al 88% no son lo mismo aunque pesen igual. La entrega se puede cargar en kilos o toneladas, tal cual o en materia seca.',
    'Receta' => 'De qué insumos de bodega se hace la ración y en qué proporción. Cada entrega descuenta esos insumos del stock automáticamente; si no alcanza, la entrega no se guarda hasta registrar la compra.',
    'Corral o animal' => 'En feedlot se alimenta el corral completo. En cría se puede suplementar a un animal puntual (una vaca flaca, un ternero guacho).',
    'Conversión (FCR)' => 'Kilos de materia seca que hicieron falta para producir un kilo de carne. Menor es mejor; en feedlot suele estar entre 6 y 8.',
    'Ración calculada' => 'Lo que cada corral necesita por día: consumo de materia seca según el peso, la categoría y la ganancia diaria de cada animal (medida con sus pesajes, o la esperada para su categoría), pasado a kilos tal cual con la ración que viene recibiendo. Si al entregar no se cargan kilos planificados, se usa este cálculo.',
    'Aviso de stock' => 'Al confirmar una entrega, la bodega se descuenta sola; si algún ingrediente queda en su stock mínimo o por debajo, el mensaje lo avisa en el momento.',
]);

$errores = errores_formulario();
$fallo = valor_previo('accion');

/* --- Formulario: entrega --- */
if (puede('registrar_entrega')) {
    $corrales_opciones = ['' => 'Elegí un corral'];
    foreach (filas(
        "SELECT c.id, c.nombre,
                (SELECT COUNT(*) FROM animales a WHERE a.corral_actual_id = c.id AND a.estado = 'ACTIVO') AS cabezas
           FROM corrales c WHERE c.organizacion_id = ?" . filtro_predio('c') . " ORDER BY c.nombre",
        [$org],
    ) as $corral) {
        $corrales_opciones[(int) $corral['id']] = $corral['nombre'] . ' (' . numero((int) $corral['cabezas']) . ' cabezas)';
    }

    $raciones_opciones = ['' => 'Elegí la ración'];
    foreach ($raciones as $racion_opcion) {
        $raciones_opciones[(int) $racion_opcion['id']] = $racion_opcion['nombre']
            . ' — ' . pesos((float) $racion_opcion['costo_por_kg']) . '/kg'
            . (isset($recetas[(int) $racion_opcion['id']]) ? '' : ' (sin receta)');
    }

    echo abrir_alta(
        'Registrar entrega de alimento',
        GESTION_AVANZADA ? 'Descuenta la bodega y carga el costo al lote' : 'Queda registrada por corral, lote o animal',
        $errores !== [] && $fallo !== 'racion',
    );
    echo formulario(url('/alimentacion'), implode('', [
        '<input type="hidden" name="accion" value="entrega">',
        campo('corral_id', 'Corral', $errores, 'select', '', [
            'items' => $corrales_opciones, 'ayuda' => 'Feedlot: se alimenta el corral completo',
        ]),
        campo('identificacion', 'O un animal puntual', $errores, 'text', '', [
            'placeholder' => 'DIIO o número interno',
            'ayuda' => 'Cría: si lo completás, la entrega es individual',
        ]),
        campo('racion_id', 'Ración', $errores, 'select', '', ['items' => $raciones_opciones, 'obligatorio' => true]),
        campo('fecha', 'Fecha', $errores, 'date', hoy(), ['obligatorio' => true, 'max' => hoy()]),
        campo('cantidad', 'Cantidad entregada', $errores, 'number', '', [
            'obligatorio' => true, 'step' => 'any', 'min' => '0.001',
            'ayuda' => 'Lo que realmente salió del mixer',
        ]),
        campo('unidad', 'Unidad', $errores, 'select', 'KG', ['items' => [
            'KG' => 'Kilos', 'T' => 'Toneladas',
        ]]),
        campo('base', 'Medida en', $errores, 'select', 'TAL_CUAL', ['items' => [
            'TAL_CUAL' => 'Tal cual (con humedad)', 'MATERIA_SECA' => 'Materia seca',
        ]]),
        campo('numero_entrega', 'Entrega del día', $errores, 'number', '1', [
            'min' => '1', 'max' => '6', 'ayuda' => 'La primera, la segunda…',
        ]),
        campo('kg_planificados', 'Kilos planificados', $errores, 'number', '', [
            'step' => '0.1', 'min' => '0', 'ayuda' => 'Vacío: el sistema lo calcula según peso, categoría y ganancia diaria',
        ]),
    ]), 'Registrar entrega');
    echo cerrar_alta();
}

/* --- Formulario: ración sin receta (modo simple) --- */
if (!GESTION_AVANZADA && puede('gestionar_raciones')) {
    echo abrir_alta('Crear ración', 'El alimento que se entrega, con su materia seca', $errores !== [] && $fallo === 'racion');
    echo formulario(url('/alimentacion'), implode('', [
        '<input type="hidden" name="accion" value="racion">',
        campo('nombre', 'Nombre', $errores, 'text', '', ['obligatorio' => true, 'placeholder' => 'Ej: Heno de pradera']),
        campo('etapa', 'Etapa', $errores, 'select', 'CRECIMIENTO', ['items' => ETAPAS_RACION]),
        campo('materia_seca_porcentaje', 'Materia seca', $errores, 'number', '88', [
            'step' => '0.1', 'min' => '1', 'max' => '100', 'ayuda' => 'Porcentaje del alimento que no es agua. El heno ronda 88%; un ensilaje, 35%',
        ]),
        campo('costo_por_kg', 'Costo por kilo', $errores, 'number', '0', [
            'step' => '1', 'min' => '0', 'ayuda' => 'Opcional. Sirve para saber cuánto costó alimentar',
        ]),
    ]), 'Crear ración');
    echo cerrar_alta();
}

/* --- Formulario: ración con receta --- */
if (GESTION_AVANZADA && puede('gestionar_raciones')) {
    $alimentos_opciones = ['' => '—'];
    foreach (filas(
        "SELECT id, nombre, materia_seca_porcentaje, costo_unitario FROM insumos
          WHERE organizacion_id = ? AND tipo = 'ALIMENTO' ORDER BY nombre",
        [$org],
    ) as $alimento) {
        $alimentos_opciones[(int) $alimento['id']] = $alimento['nombre']
            . ' · ' . numero((float) $alimento['materia_seca_porcentaje'], 0) . '% MS · '
            . pesos((float) $alimento['costo_unitario']) . '/kg';
    }

    $filas_receta = [];
    for ($i = 1; $i <= INGREDIENTES_POR_RACION; $i++) {
        $filas_receta[] = campo('insumo_' . $i, 'Ingrediente ' . $i, $errores, 'select', '', ['items' => $alimentos_opciones]);
        $filas_receta[] = campo('porcentaje_' . $i, '% de la mezcla', $errores, 'number', '', [
            'step' => '0.1', 'min' => '0', 'max' => '100', 'ayuda' => $i === 1 ? 'Sobre kilos tal cual; el total suma 100' : null,
        ]);
    }

    echo abrir_alta('Crear ración con receta', 'Calcula materia seca y costo por kilo', $errores !== [] && $fallo === 'racion');
    echo formulario(url('/alimentacion'), implode('', array_merge([
        '<input type="hidden" name="accion" value="racion">',
        campo('nombre', 'Nombre', $errores, 'text', '', ['obligatorio' => true, 'placeholder' => 'Ej: Terminación invierno']),
        campo('etapa', 'Etapa', $errores, 'select', 'TERMINACION', ['items' => ETAPAS_RACION]),
    ], $filas_receta)), 'Crear ración');
    echo cerrar_alta();
}
?>

<?php if ($por_reponer !== []): ?>
  <div class="mensaje error">
    <span class="marca-aviso">!</span>
    <div>
      <strong>Bodega por reponer:</strong>
      <?= h(implode(', ', array_map(
          static fn (array $i): string => $i['nombre'] . ' (quedan ' . numero((float) $i['stock_actual']) . ' kg, mínimo ' . numero((float) $i['stock_minimo']) . ')',
          $por_reponer,
      ))) ?>.
      Si una entrega necesita más de lo que queda, no se guarda.
      <?php if (puede('ver_insumos')): ?>
        <a href="<?= h(url('/insumos')) ?>">Ver insumos →</a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<section class="indicadores">
  <div class="indicador">
    <div class="rotulo">Entregado · 30 días</div>
    <div class="cifra"><?= numero((float) ($resumen['kg'] ?? 0) / 1000, 1) ?><small> t</small></div>
    <div class="nota">tal cual, como sale del mixer</div>
  </div>
  <div class="indicador">
    <div class="rotulo">Materia seca</div>
    <div class="cifra"><?= numero((float) ($resumen['kg_ms'] ?? 0) / 1000, 1) ?><small> t</small></div>
    <div class="nota">descontada el agua del alimento</div>
  </div>
  <div class="indicador <?= $fcr !== null && $fcr <= 7 ? 'ok' : '' ?>">
    <div class="rotulo">Conversión</div>
    <div class="cifra"><?= $fcr === null ? '—' : numero($fcr, 2) ?></div>
    <div class="nota">kg de materia seca por kg ganado</div>
  </div>
  <?php if (GESTION_AVANZADA): ?>
  <div class="indicador">
    <div class="rotulo">Costo · 30 días</div>
    <div class="cifra" style="font-size: 25px"><?= pesos((float) ($resumen['costo'] ?? 0)) ?></div>
    <div class="nota">solo alimento</div>
  </div>
  <?php endif; ?>
</section>

<?php
/* Ración calculada: lo que necesita hoy cada corral con animales. */
$calculos = [];
foreach (filas('SELECT c.id, c.nombre FROM corrales c WHERE c.organizacion_id = ?' . filtro_predio('c') . ' ORDER BY c.nombre', [$org]) as $corral_calculo) {
    $calculo = racion_calculada_corral($org, (int) $corral_calculo['id']);
    if ($calculo !== null) {
        $calculos[] = $calculo + ['corral' => $corral_calculo['nombre']];
    }
}
?>
<?php if ($calculos !== []): ?>
<div class="bloque">
  <header>
    <div>
      <h2>Ración calculada por corral</h2>
      <div class="sub">Necesidad de hoy según peso, categoría y ganancia diaria. Es el plan que se usa si al entregar no se cargan kilos planificados.</div>
    </div>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead>
        <tr>
          <th>Corral</th><th class="num">Cabezas</th><th class="num">Peso medio</th><th class="num">Ganancia diaria</th>
          <th class="num">MS por día</th><th>Ración</th><th class="num">Tal cual por día</th><th class="num">Por entrega</th>
          <?php if (GESTION_AVANZADA): ?><th>Ingredientes por día · bodega</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($calculos as $c): ?>
        <tr>
          <td><span class="principal"><?= h($c['corral']) ?></span></td>
          <td class="num"><?= numero($c['cabezas']) ?></td>
          <td class="num"><?= kg($c['peso_medio']) ?></td>
          <td class="num"><?= numero($c['gdp_media'], 2) ?> kg/d
            <div class="secundario"><?= $c['con_gdp_medida'] === $c['cabezas'] ? 'medida' : ($c['con_gdp_medida'] === 0 ? 'esperada' : numero($c['con_gdp_medida']) . ' medidas') ?></div></td>
          <td class="num"><?= kg($c['kg_ms_dia']) ?></td>
          <td><?= $c['racion'] !== null ? h($c['racion']['nombre']) : '<span class="secundario">Sin entregas en el mes: elegí la ración al entregar</span>' ?></td>
          <td class="num principal"><?= $c['kg_tal_cual_dia'] !== null ? kg($c['kg_tal_cual_dia']) : '—' ?></td>
          <td class="num"><?= $c['kg_por_entrega'] !== null ? kg($c['kg_por_entrega']) : '—' ?>
            <div class="secundario"><?= numero($c['entregas_por_dia']) ?> al día</div></td>
          <?php if (GESTION_AVANZADA): ?>
          <td>
            <?php foreach ($c['ingredientes'] as $ingrediente): ?>
              <div class="secundario"><?= h($ingrediente['nombre']) ?> <?= kg($ingrediente['kg_dia']) ?>
                · <?= $ingrediente['dias_cubiertos'] === null ? '—' : insignia(
                    'alcanza ' . numero($ingrediente['dias_cubiertos']) . ' d',
                    $ingrediente['dias_cubiertos'] < 7 ? 'riesgo' : ($ingrediente['dias_cubiertos'] < 15 ? 'aviso' : 'ok'),
                ) ?></div>
            <?php endforeach; ?>
            <?php if ($c['ingredientes'] === [] && $c['racion'] !== null): ?><span class="secundario">Ración sin receta</span><?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="par">
  <div class="bloque">
    <header>
      <div>
        <h2>Raciones y recetas</h2>
        <div class="sub">De qué se hace cada mezcla</div>
      </div>
    </header>
    <div class="tabla-scroll">
      <table>
        <thead>
          <tr><th>Ración</th><?php if (GESTION_AVANZADA): ?><th>Receta</th><?php endif; ?><th class="num">MS</th><?php if (GESTION_AVANZADA): ?><th class="num">Costo por kg</th><?php endif; ?></tr>
        </thead>
        <tbody>
        <?php foreach ($raciones as $racion): ?>
          <tr>
            <td>
              <span class="principal"><?= h($racion['nombre']) ?></span>
              <div class="secundario"><?= h(ETAPAS_RACION[$racion['etapa']] ?? legible($racion['etapa'])) ?></div>
            </td>
            <?php if (GESTION_AVANZADA): ?>
            <td>
              <?php if (isset($recetas[(int) $racion['id']])): ?>
                <?php foreach ($recetas[(int) $racion['id']] as $ingrediente): ?>
                  <div class="secundario"><?= numero((float) $ingrediente['porcentaje'], 0) ?>% <?= h($ingrediente['nombre']) ?></div>
                <?php endforeach; ?>
              <?php else: ?>
                <?php if (GESTION_AVANZADA): ?><?= insignia('Sin receta: no descuenta bodega', 'aviso') ?><?php endif; ?>
              <?php endif; ?>
            </td>
            <?php endif; ?>
            <td class="num"><?= numero((float) $racion['materia_seca_porcentaje'], 1) ?>%</td>
            <?php if (GESTION_AVANZADA): ?><td class="num"><?= pesos((float) $racion['costo_por_kg']) ?></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if ($raciones === []): ?>
          <tr><td colspan="<?= GESTION_AVANZADA ? 4 : 2 ?>" class="vacio">Todavía no hay raciones.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="bloque">
    <header>
      <div>
        <h2>Cumplimiento</h2>
        <div class="sub">Diferencia entre lo planificado y lo que se entregó</div>
      </div>
    </header>
    <div class="interior">
      <?php
      $planificado = (float) ($resumen['kg_planificados'] ?? 0);
      $entregado = (float) ($resumen['kg'] ?? 0);
      $cumplimiento = $planificado > 0 ? (int) round($entregado / $planificado * 100) : 0;
      ?>
      <div style="font-size: 40px; font-weight: 650; letter-spacing: -1.5px; line-height: 1;">
        <?= $cumplimiento ?>%
      </div>
      <p style="color: var(--tinta-2); font-size: 14px; margin: 8px 0 16px;">
        Se entregaron <?= numero($entregado) ?> kg de los <?= numero($planificado) ?> kg
        planificados en los últimos 30 días.
      </p>
      <?= medidor($cumplimiento) ?>
      <p style="color: var(--tinta-2); font-size: 13px; margin: 14px 0 0;">
        Entregar de menos frena la ganancia de peso; entregar de más deja comida en el
        comedero y encarece el kilo producido.
      </p>
    </div>
  </div>
</div>

<div class="bloque">
  <header>
    <div>
      <h2>Últimas entregas</h2>
      <div class="sub">Las 40 más recientes, a corral o a un animal</div>
    </div>
    <a class="boton suave" href="<?= h(url_con('/exportar', ['tipo' => 'entregas'])) ?>">Filtrar y exportar</a>
    <a class="boton suave" href="<?= h(url_con('/exportar', ['tipo' => 'entregas', 'formato' => 'pdf'])) ?>">PDF</a>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead>
        <tr>
          <th>Fecha</th><th>Destino</th><th>Ración</th>
          <th class="num">Entregado</th><th class="num">Diferencia</th><?php if (GESTION_AVANZADA): ?><th class="num">Costo</th><?php endif; ?><?php if (puede('anular_registro')): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($entregas as $entrega): ?>
        <?php
        $kg = (float) $entrega['kg_entregados'];
        $dif = $entrega['kg_planificados'] !== null ? $kg - (float) $entrega['kg_planificados'] : 0.0;
        ?>
        <tr>
          <td>
            <?= fecha_corta($entrega['fecha']) ?>
            <div class="secundario">entrega <?= (int) $entrega['numero_entrega'] ?><?= $entrega['responsable_nombre'] !== null ? ' · ' . h($entrega['responsable_nombre']) : '' ?></div>
          </td>
          <td>
            <?php if ($entrega['animal_id'] !== null): ?>
              <a class="clave principal" href="<?= h(url_con('/animal', ['id' => (int) $entrega['animal_id']])) ?>">
                <?= h($entrega['animal_caravana'] ?? $entrega['animal_interno'] ?? '—') ?>
              </a>
              <div class="secundario">individual</div>
            <?php else: ?>
              <span class="principal"><?= h($entrega['corral'] ?? '—') ?></span>
              <div class="secundario"><?= h($entrega['lote'] ?? '—') ?></div>
            <?php endif; ?>
          </td>
          <td><?= h($entrega['racion']) ?></td>
          <td class="num">
            <span class="principal"><?= $kg >= 1000 ? numero($kg / 1000, 2) . ' t' : numero($kg) . ' kg' ?></span>
            <div class="secundario"><?= numero((float) $entrega['kg_materia_seca']) ?> kg MS</div>
          </td>
          <td class="num" style="color: <?= abs($dif) > 30 ? 'var(--aviso)' : 'var(--tinta-3)' ?>">
            <?= $dif > 0 ? '+' : '' ?><?= numero($dif) ?>
          </td>
          <?php if (GESTION_AVANZADA): ?><td class="num"><?= pesos((float) $entrega['costo_total']) ?></td><?php endif; ?>
          <?php if (puede('anular_registro')): ?>
            <td class="num"><?= boton_anular(url('/alimentacion'), 'ENTREGA', (int) $entrega['id']) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if ($entregas === []): ?>
        <tr><td colspan="<?= (GESTION_AVANZADA ? 6 : 5) + (puede('anular_registro') ? 1 : 0) ?>" class="vacio">Sin entregas registradas.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php cerrar_pagina(); ?>
