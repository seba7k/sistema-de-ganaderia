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

    if (entrada('accion') === 'validar') {
        exigir_post_valido('validar_tratamiento', $volver);
        try {
            $resultado = op_validar_tratamiento($org, (int) usuario_actual()['id'], $_POST);
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
            v.nombre AS validador_nombre, v.apellido AS validador_apellido,
            (SELECT COUNT(*) FROM aplicaciones_tratamiento ap WHERE ap.tratamiento_id = t.id) AS aplicados
       FROM tratamientos t
       JOIN insumos i ON i.id = t.insumo_id
       LEFT JOIN corrales c ON c.id = t.corral_id
       LEFT JOIN lotes l ON l.id = t.lote_id
       LEFT JOIN usuarios u ON u.id = t.responsable_id
       LEFT JOIN usuarios v ON v.id = t.validado_por
      WHERE t.organizacion_id = ? AND t.anulada = 0' . filtro_predio('t') . '
      ORDER BY t.fecha DESC',
    [$org],
);

/* Validación MVA: se calcula una vez por tratamiento, porque revisar la huella
   implica leer a qué animales se aplicó. */
$validacion = [];
$pendientes = [];
foreach ($tratamientos as $tratamiento) {
    $validacion[(int) $tratamiento['id']] = estado_validacion($org, $tratamiento);
    if ($validacion[(int) $tratamiento['id']] === 'PENDIENTE') {
        $pendientes[] = $tratamiento;
    }
}
$alterados = count(array_filter($validacion, static fn (string $e): bool => $e === 'ALTERADO'));
$mi_mva = membresia_mva($org, (int) usuario_actual()['id']);
$puedo_validar = puede('validar_tratamiento') && mva_vigente($mi_mva, hoy());

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
            'placeholder' => 'DIIO, número interno o EID',
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
  <div class="indicador <?= $alterados > 0 ? 'riesgo' : ($pendientes !== [] ? 'aviso' : 'ok') ?>">
    <div class="rotulo">Sin validar por MVA</div>
    <div class="cifra"><?= numero(count($pendientes)) ?></div>
    <div class="nota">
      <?= $alterados > 0
          ? numero($alterados) . ' validado(s) con datos alterados'
          : 'tratamientos pendientes de firma' ?>
    </div>
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

<?php if ($alterados > 0): ?>
<div class="mensaje error">
  <span class="marca-aviso">!</span>
  <div>
    <strong>Hay <?= numero($alterados) ?> tratamiento(s) validado(s) cuyos datos ya no coinciden con lo que firmó el MVA.</strong>
    Alguien los cambió fuera del sistema después de la validación. Están marcados como
    «Alterado» en el historial: revisalos con el veterinario y con la bitácora.
  </div>
</div>
<?php endif; ?>

<?php if (puede('validar_tratamiento') && $pendientes !== []): ?>
<div class="bloque">
  <header>
    <div>
      <h2>Pendientes de validar</h2>
      <div class="sub">
        <?php if ($puedo_validar): ?>
          Vas a validar como MVA N° <?= h($mi_mva['mva_numero']) ?> · RUT <?= h(rut_legible($mi_mva['mva_rut'])) ?>
        <?php elseif ($mi_mva !== null && $mi_mva['mva_numero'] !== null): ?>
          Tu registro MVA venció el <?= fecha_corta($mi_mva['mva_vigente_hasta']) ?>: pedile al administrador que lo actualice
        <?php else: ?>
          Para validar tenés que tener un registro MVA vigente: lo carga el administrador en Usuarios
        <?php endif; ?>
      </div>
    </div>
    <?= insignia(numero(count($pendientes)) . ' sin firma', 'aviso') ?>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Producto</th>
          <th>Dónde</th>
          <th class="num">Cabezas</th>
          <th>Cargó</th>
          <?php if ($puedo_validar): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($pendientes as $tratamiento): ?>
        <tr>
          <td><?= fecha_corta($tratamiento['fecha']) ?></td>
          <td>
            <span class="principal"><?= h($tratamiento['producto']) ?></span>
            <div class="secundario">
              <?= h(legible($tratamiento['tipo'])) ?> · <?= h(legible($tratamiento['via'])) ?> ·
              <?= numero((float) $tratamiento['dosis_por_animal'], 1) ?> por animal ·
              carencia <?= (int) $tratamiento['dias_carencia_carne'] ?> d
            </div>
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
          <?php if ($puedo_validar): ?>
            <td class="num">
              <form method="post" action="<?= h(url('/sanidad')) ?>">
                <input type="hidden" name="csrf" value="<?= h(token_csrf()) ?>">
                <input type="hidden" name="accion" value="validar">
                <input type="hidden" name="tratamiento_id" value="<?= (int) $tratamiento['id'] ?>">
                <button class="boton menudo" type="submit">Validar</button>
              </form>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

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
          <th>Validación MVA</th>
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
          <td>
            <?php $estado_mva = $validacion[(int) $tratamiento['id']]; ?>
            <?php if ($estado_mva === 'PENDIENTE'): ?>
              <?= insignia('Pendiente', 'aviso') ?>
            <?php else: ?>
              <?= $estado_mva === 'VALIDADO' ? insignia('Validado', 'ok') : insignia('Alterado', 'riesgo') ?>
              <div class="secundario">
                <?= h(trim(($tratamiento['validador_nombre'] ?? '') . ' ' . ($tratamiento['validador_apellido'] ?? ''))) ?>
                · MVA N° <?= h($tratamiento['validado_mva_numero']) ?> · <?= fecha_corta($tratamiento['validado_en']) ?>
              </div>
            <?php endif; ?>
          </td>
          <td><?= insignia(etiqueta_retiro($retiro['estado']), $tono) ?></td>
          <?php if (puede('anular_registro')): ?>
            <td class="num"><?= boton_anular(url('/sanidad'), 'TRATAMIENTO', (int) $tratamiento['id']) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if ($tratamientos === []): ?>
        <tr><td colspan="<?= puede('anular_registro') ? 8 : 7 ?>" class="vacio">Sin tratamientos registrados.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php cerrar_pagina(); ?>
