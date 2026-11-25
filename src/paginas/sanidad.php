<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vistas/layout.php';
require_once __DIR__ . '/../dominio/retiros.php';

exigir_permiso('ver_sanidad');

$org = organizacion_actual();
$predio = predio_actual();
$volver = url('/sanidad');

/* ============================================================
   Registrar un tratamiento
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    exigir_post_valido('registrar_tratamiento', $volver);

    // Stock, carencia y destinatarios se validan en la operación compartida:
    // son las mismas reglas que aplica la app de terreno.
    try {
        $resultado = op_tratamiento($org, (int) usuario_actual()['id'], $_POST);
    } catch (ErrorOperacion $error) {
        volver_con_errores($error, $volver);
    }

    flash('exito', $resultado['mensaje']);
    redirigir($volver);
}

$tratamientos = filas(
    'SELECT t.*, i.nombre AS producto, i.principio_activo, c.nombre AS corral,
            l.codigo AS lote, u.nombre AS responsable_nombre, u.apellido AS responsable_apellido,
            (SELECT COUNT(*) FROM aplicaciones_tratamiento ap WHERE ap.tratamiento_id = t.id) AS aplicados
       FROM tratamientos t
       JOIN insumos i ON i.id = t.insumo_id
       LEFT JOIN corrales c ON c.id = t.corral_id
       LEFT JOIN lotes l ON l.id = t.lote_id
       LEFT JOIN usuarios u ON u.id = t.responsable_id
      WHERE t.organizacion_id = ? AND t.anulada = 0' . filtro_predio('t') . '
      ORDER BY t.fecha DESC',
    [$org],
);

$bloqueados = [];
$libres = [];
foreach ($tratamientos as $tratamiento) {
    $retiro = estado_retiro($tratamiento['fecha'], (int) $tratamiento['dias_carencia_carne']);
    $registro = ['t' => $tratamiento, 'r' => $retiro];
    if ($retiro['apto']) {
        $libres[] = $registro;
    } else {
        $bloqueados[] = $registro;
    }
}
usort($bloqueados, static fn (array $a, array $b): int => $a['r']['dias_restantes'] <=> $b['r']['dias_restantes']);

$cabezas_bloqueadas = array_sum(array_map(
    static fn (array $x): int => (int) $x['t']['aplicados'],
    $bloqueados,
));

abrir_pagina(
    'Sanidad',
    'Qué se aplicó, a quiénes, y desde cuándo esos animales pueden ir a faena.',
    '/sanidad',
    ['Sanidad'],
);

/* --- Formulario de carga --- */
if (puede('registrar_tratamiento')) {
    $errores = errores_formulario();

    $productos = ['' => 'Elegí el producto'];
    foreach (filas(
        "SELECT id, nombre, dias_carencia_carne, stock_actual FROM insumos
          WHERE organizacion_id = ? AND tipo = 'VETERINARIO'" . filtro_predio('insumos') . ' ORDER BY nombre',
        [$org],
    ) as $producto) {
        $productos[(int) $producto['id']] = $producto['nombre']
            . ' — carencia ' . (int) $producto['dias_carencia_carne'] . ' d'
            . ' · quedan ' . numero((float) $producto['stock_actual'], 0);
    }

    $corrales_opciones = ['' => 'Elegí un corral'];
    foreach (filas(
        'SELECT id, nombre FROM corrales WHERE organizacion_id = ?' . filtro_predio('corrales') . ' ORDER BY nombre',
        [$org],
    ) as $corral) {
        $corrales_opciones[(int) $corral['id']] = $corral['nombre'];
    }

    echo abrir_alta(
        'Registrar tratamiento',
        'Descuenta el stock y calcula la carencia',
        $errores !== [],
    );
    echo formulario(url('/sanidad'), implode('', [
        campo('insumo_id', 'Producto', $errores, 'select', '', ['items' => $productos, 'obligatorio' => true]),
        campo('tipo', 'Tipo', $errores, 'select', 'CURATIVO', ['items' => [
            'CURATIVO' => 'Curativo',
            'PREVENTIVO' => 'Preventivo',
            'VACUNACION' => 'Vacunación',
            'DESPARASITACION' => 'Desparasitación',
            'VITAMINICO' => 'Vitamínico',
        ]]),
        campo('via', 'Vía', $errores, 'select', 'INTRAMUSCULAR', ['items' => [
            'INTRAMUSCULAR' => 'Intramuscular',
            'SUBCUTANEA' => 'Subcutánea',
            'INTRAVENOSA' => 'Intravenosa',
            'ORAL' => 'Oral',
            'TOPICA' => 'Tópica',
        ]]),
        campo('fecha', 'Fecha de aplicación', $errores, 'date', hoy(), ['obligatorio' => true, 'max' => hoy()]),
        campo('dosis', 'Dosis por animal', $errores, 'number', '', [
            'obligatorio' => true, 'step' => '0.1', 'min' => '0.1',
            'ayuda' => 'En la unidad del producto (ml o dosis)',
        ]),
        campo('corral_id', 'Corral completo', $errores, 'select', '', [
            'items' => $corrales_opciones,
            'ayuda' => 'Se aplica a todos los animales activos del corral',
        ]),
        campo('identificacion', 'O un animal puntual', $errores, 'text', '', [
            'placeholder' => 'Caravana, número interno o EID',
            'ayuda' => 'Si lo completás, se aplica solo a ese animal',
        ]),
        campo('diagnostico', 'Diagnóstico', $errores, 'text', '', [
            'placeholder' => 'Ej: neumonía, queratoconjuntivitis',
        ]),
    ]), 'Registrar tratamiento');
    echo cerrar_alta();
}
?>

<section class="indicadores">
  <div class="indicador <?= $cabezas_bloqueadas > 0 ? 'riesgo' : 'ok' ?>">
    <div class="rotulo">No pueden ir a faena</div>
    <div class="cifra"><?= numero($cabezas_bloqueadas) ?></div>
    <div class="nota">animales en período de carencia</div>
  </div>
  <div class="indicador">
    <div class="rotulo">Tratamientos</div>
    <div class="cifra"><?= numero(count($tratamientos)) ?></div>
    <div class="nota"><?= numero(count($bloqueados)) ?> con retiro sin cumplir</div>
  </div>
  <div class="indicador">
    <div class="rotulo">Costo sanitario</div>
    <div class="cifra" style="font-size: 25px">
      <?= pesos(array_sum(array_map(static fn (array $t): float => (float) $t['costo_total'], $tratamientos))) ?>
    </div>
    <div class="nota">acumulado</div>
  </div>
</section>

<div class="mensaje info">
  <span class="marca-aviso">ℹ</span>
  <div>
    <strong>Cómo leer el estado.</strong>
    Describe el <em>medicamento</em>, no al animal. Mientras corre el período de carencia,
    el residuo sigue presente y el animal <strong>no puede ir a faena</strong>.
    Cuando la carencia se cumple, queda <strong>apto</strong>.
  </div>
</div>

<?php if ($bloqueados !== []): ?>
<div class="bloque">
  <header>
    <div>
      <h2>Retiros sin cumplir</h2>
      <div class="sub">Ordenados por el que se habilita primero</div>
    </div>
    <?= insignia(numero(count($bloqueados)) . ' activos', 'riesgo') ?>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead>
        <tr>
          <th>Producto</th>
          <th>Dónde</th>
          <th class="num">Cabezas</th>
          <th>Se habilita</th>
          <th>Falta</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($bloqueados as $registro): ?>
        <tr>
          <td>
            <span class="principal"><?= h($registro['t']['producto']) ?></span>
            <div class="secundario">
              Aplicado el <?= fecha_corta($registro['t']['fecha']) ?> ·
              carencia <?= (int) $registro['t']['dias_carencia_carne'] ?> días
            </div>
          </td>
          <td>
            <?= h($registro['t']['corral'] ?? $registro['t']['lote'] ?? '—') ?>
            <?php if ($registro['t']['diagnostico'] !== null): ?>
              <div class="secundario"><?= h($registro['t']['diagnostico']) ?></div>
            <?php endif; ?>
          </td>
          <td class="num principal"><?= numero((int) $registro['t']['aplicados']) ?></td>
          <td><?= fecha_corta($registro['r']['fecha_habilitacion']) ?></td>
          <td>
            <?= insignia(
                $registro['r']['dias_restantes'] . ' día(s)',
                $registro['r']['estado'] === 'POR_VENCER' ? 'aviso' : 'riesgo',
            ) ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="bloque">
  <header>
    <div>
      <h2>Historial</h2>
      <div class="sub">Todos los tratamientos registrados</div>
    </div>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Producto</th>
          <th>Dónde</th>
          <th class="num">Cabezas</th>
          <th>Responsable</th>
          <th>Estado</th>
          <?php if (puede('anular_registro')): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tratamientos as $tratamiento): ?>
        <?php
        $retiro = estado_retiro($tratamiento['fecha'], (int) $tratamiento['dias_carencia_carne']);
        $tono = match ($retiro['estado']) {
            'VENCIDO' => 'ok', 'POR_VENCER' => 'aviso', default => 'riesgo',
        };
        ?>
        <tr>
          <td><?= fecha_corta($tratamiento['fecha']) ?></td>
          <td>
            <span class="principal"><?= h($tratamiento['producto']) ?></span>
            <div class="secundario"><?= h(legible($tratamiento['tipo'])) ?> · <?= h(legible($tratamiento['via'])) ?></div>
          </td>
          <td>
            <?= h($tratamiento['corral'] ?? $tratamiento['lote'] ?? '—') ?>
            <?php if ($tratamiento['diagnostico'] !== null): ?>
              <div class="secundario"><?= h($tratamiento['diagnostico']) ?></div>
            <?php endif; ?>
          </td>
          <td class="num"><?= numero((int) $tratamiento['cantidad_animales']) ?></td>
          <td>
            <?= $tratamiento['responsable_nombre'] !== null
                ? h($tratamiento['responsable_nombre'] . ' ' . $tratamiento['responsable_apellido'])
                : '—' ?>
          </td>
          <td><?= insignia(etiqueta_retiro($retiro['estado']), $tono) ?></td>
          <?php if (puede('anular_registro')): ?>
            <td class="num"><?= boton_anular(url('/sanidad'), 'TRATAMIENTO', (int) $tratamiento['id']) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if ($tratamientos === []): ?>
        <tr><td colspan="<?= puede('anular_registro') ? 7 : 6 ?>" class="vacio">Sin tratamientos registrados.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php cerrar_pagina(); ?>
