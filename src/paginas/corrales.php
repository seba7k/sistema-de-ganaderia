<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vistas/layout.php';

exigir_permiso('ver_corrales');

$org = organizacion_actual();
$predio = predio_actual();
$volver = url('/corrales');

/* ============================================================
   Mover animales de un corral a otro
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_post_valido('registrar_traslado', $volver);

    // La capacidad del destino se valida en la operación compartida con la app.
    try {
        $resultado = op_traslado($org, (int) usuario_actual()['id'], $_POST);
    } catch (ErrorOperacion $error) {
        volver_con_errores($error, $volver);
    }

    flash('exito', $resultado['mensaje']);
    redirigir($volver);
}

$corrales = filas(
    "SELECT c.*,
            (SELECT COUNT(*) FROM animales a WHERE a.corral_actual_id = c.id AND a.estado = 'ACTIVO') AS ocupacion,
            (SELECT AVG(a.peso_actual_kg) FROM animales a WHERE a.corral_actual_id = c.id AND a.estado = 'ACTIVO') AS peso_promedio
       FROM corrales c
      WHERE c.organizacion_id = ?" . filtro_predio('c') . "
      ORDER BY c.nombre",
    [$org],
);

$capacidad = (int) array_sum(array_column($corrales, 'capacidad_maxima'));
$ocupadas = (int) array_sum(array_column($corrales, 'ocupacion'));
$ocupacion_pct = $capacidad > 0 ? (int) round($ocupadas / $capacidad * 100) : 0;
$excedidos = count(array_filter(
    $corrales,
    static fn (array $c): bool => (int) $c['ocupacion'] > (int) $c['capacidad_maxima'],
));

/* Historial de traslados: dónde estuvo cada grupo de animales y quién lo movió. */
$movimientos = filas(
    "SELECT m.id, m.fecha, m.cantidad_animales, m.motivo, co.nombre AS origen, cd.nombre AS destino,
            u.nombre AS responsable_nombre, u.apellido AS responsable_apellido
       FROM movimientos_hacienda m
       LEFT JOIN corrales co ON co.id = m.corral_origen_id
       LEFT JOIN corrales cd ON cd.id = m.corral_destino_id
       LEFT JOIN usuarios u ON u.id = m.responsable_id
      WHERE m.organizacion_id = ? AND m.tipo = 'TRASLADO_INTERNO'"
        . ($predio === null ? '' : ' AND cd.establecimiento_id = ' . $predio) . "
      ORDER BY m.fecha DESC, m.id DESC
      LIMIT 30",
    [$org],
);

abrir_pagina(
    'Corrales',
    'Cuánto espacio hay disponible y dónde no entra un animal más.',
    '/corrales',
    ['Corrales'],
);

/* ---------- Formulario de carga ---------- */

if (puede('registrar_traslado')) {
    $errores = errores_formulario();

    $opciones_origen = ['' => 'Corral de origen'];
    $opciones_destino = ['' => 'Elegí el destino'];
    foreach ($corrales as $corral_opcion) {
        $libre = (int) $corral_opcion['capacidad_maxima'] - (int) $corral_opcion['ocupacion'];
        $opciones_origen[(int) $corral_opcion['id']] = $corral_opcion['nombre']
            . ' (' . numero((int) $corral_opcion['ocupacion']) . ' cabezas)';
        $opciones_destino[(int) $corral_opcion['id']] = $corral_opcion['nombre']
            . ' — ' . ($libre > 0 ? 'entran ' . numero($libre) : 'completo');
    }

    echo abrir_alta('Mover animales de corral', 'Valida que quepan en el destino y queda en el historial', $errores !== []);
    echo formulario(url('/corrales'), implode('', [
        campo('origen_id', 'Desde', $errores, 'select', '', [
            'items' => $opciones_origen, 'ayuda' => 'Mueve el corral completo',
        ]),
        campo('identificaciones', 'O animales puntuales', $errores, 'text', '', [
            'placeholder' => 'A-0142, A-0143, P-0007',
            'ayuda' => 'Separados por coma; si los completás, se ignora el origen',
        ]),
        campo('destino_id', 'Hacia', $errores, 'select', '', ['items' => $opciones_destino, 'obligatorio' => true]),
        campo('fecha', 'Fecha', $errores, 'date', hoy(), ['obligatorio' => true, 'max' => hoy()]),
        campo('motivo', 'Motivo', $errores, 'text', '', ['placeholder' => 'Ej: cambio de ración, apartado a enfermería']),
    ]), 'Mover animales');
    echo cerrar_alta();
}
?>

<section class="indicadores">
  <div class="indicador <?= $excedidos > 0 ? 'riesgo' : ($ocupacion_pct >= 90 ? 'aviso' : '') ?>">
    <div class="rotulo">Ocupación</div>
    <div class="cifra"><?= $ocupacion_pct ?><small>%</small></div>
    <div class="nota"><?= $excedidos > 0 ? numero($excedidos) . ' corral(es) sobre su capacidad' : 'del total de plazas' ?></div>
  </div>
  <div class="indicador ok">
    <div class="rotulo">Plazas libres</div>
    <div class="cifra"><?= numero(max($capacidad - $ocupadas, 0)) ?></div>
    <div class="nota">para recibir hacienda</div>
  </div>
  <div class="indicador">
    <div class="rotulo">Plazas ocupadas</div>
    <div class="cifra"><?= numero($ocupadas) ?></div>
    <div class="nota">de <?= numero($capacidad) ?> disponibles</div>
  </div>
  <div class="indicador">
    <div class="rotulo">Corrales</div>
    <div class="cifra"><?= numero(count($corrales)) ?></div>
    <div class="nota">incluye potreros y enfermería</div>
  </div>
</section>

<div class="bloque">
  <header>
    <div>
      <h2>Detalle por corral</h2>
      <div class="sub">Los centímetros de comedero por cabeza indican si todos comen a la vez</div>
    </div>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead>
        <tr>
          <th>Corral</th>
          <th class="num">Cabezas</th>
          <th style="width: 220px">Ocupación</th>
          <th class="num">Peso promedio</th>
          <th class="num">Comedero</th>
          <th>Estado</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($corrales as $corral): ?>
        <?php
        $ocupacion = (int) $corral['ocupacion'];
        $maxima = (int) $corral['capacidad_maxima'];
        $pct = $maxima > 0 ? (int) round($ocupacion / $maxima * 100) : 0;
        $comedero = $corral['metros_comedero'] !== null && $ocupacion > 0
            ? (float) $corral['metros_comedero'] / $ocupacion * 100
            : null;
        ?>
        <tr data-ir="<?= h(url_con('/hacienda', ['corral' => (int) $corral['id']])) ?>">
          <td>
            <a class="principal" href="<?= h(url_con('/hacienda', ['corral' => (int) $corral['id']])) ?>"><?= h($corral['nombre']) ?></a>
            <?php if ($corral['tipo'] !== 'CORRAL'): ?>
              <div class="secundario"><?= h(legible($corral['tipo'])) ?></div>
            <?php endif; ?>
          </td>
          <td class="num"><?= numero($ocupacion) ?> <span style="color:var(--tinta-3)">/ <?= numero($maxima) ?></span></td>
          <td><?= medidor($pct) ?></td>
          <td class="num"><?= $corral['peso_promedio'] === null ? '—' : kg((float) $corral['peso_promedio']) ?></td>
          <td class="num"><?= $comedero === null ? '—' : numero($comedero, 0) . ' cm' ?></td>
          <td>
            <?php if ($pct > 100): ?>
              <?= insignia('Sobre capacidad', 'riesgo') ?>
            <?php elseif ($pct >= 90): ?>
              <?= insignia('Casi lleno', 'aviso') ?>
            <?php elseif ($ocupacion === 0): ?>
              <?= insignia('Vacío', 'neutro') ?>
            <?php else: ?>
              <?= insignia('Entran ' . numero($maxima - $ocupacion), 'ok') ?>
            <?php endif; ?>
            <div class="ir-fila">Ver sus animales →</div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="bloque">
  <header>
    <div>
      <h2>Historial de traslados</h2>
      <div class="sub">Los últimos 30 movimientos entre corrales, con quién los hizo</div>
    </div>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead>
        <tr><th>Fecha</th><th>Desde</th><th>Hacia</th><th class="num">Cabezas</th><th>Motivo</th><th>Responsable</th></tr>
      </thead>
      <tbody>
      <?php foreach ($movimientos as $movimiento): ?>
        <tr>
          <td><?= fecha_corta($movimiento['fecha']) ?></td>
          <td><?= h($movimiento['origen'] ?? '—') ?></td>
          <td class="principal"><?= h($movimiento['destino'] ?? '—') ?></td>
          <td class="num"><?= numero((int) $movimiento['cantidad_animales']) ?></td>
          <td><?= h($movimiento['motivo'] ?? '—') ?></td>
          <td>
            <?= $movimiento['responsable_nombre'] !== null
                ? h($movimiento['responsable_nombre'] . ' ' . $movimiento['responsable_apellido'])
                : '—' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($movimientos === []): ?>
        <tr><td colspan="6" class="vacio">Todavía no hay traslados registrados.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php cerrar_pagina(); ?>
