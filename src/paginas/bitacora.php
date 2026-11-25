<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vistas/layout.php';

/**
 * Bitácora: qué se corrigió, qué se anuló y qué pasó con los accesos.
 *
 * Solo registra lo que cambia o borra algo ya cargado, y los hechos de
 * seguridad. Las altas no entran: ya quedan en su propia tabla, con su fecha.
 */

exigir_permiso('ver_bitacora');

$org = organizacion_actual();

const ACCIONES_BITACORA = [
    'ANULA_PESADA'      => 'Pesaje anulado',
    'ANULA_ENTREGA'     => 'Entrega anulada',
    'ANULA_TRATAMIENTO' => 'Tratamiento anulado',
    'ANULA_BAJA'        => 'Baja anulada',
    'ANULA_NOTA'        => 'Nota anulada',
    'EDITA_ANIMAL'      => 'Ficha corregida',
    'CAMBIA_ROL'        => 'Rol cambiado',
    'DESACTIVA_ACCESO'  => 'Acceso quitado',
    'ACTIVA_ACCESO'     => 'Acceso devuelto',
    'REINICIA_CLAVE'    => 'Contraseña reiniciada',
    'INGRESO_FALLIDO'   => 'Ingreso fallido',
    'INGRESO_BLOQUEADO' => 'Ingreso bloqueado',
    'SESION_APP'        => 'Sesión de la app',
];

$filtro = (string) ($_GET['accion'] ?? '');
$condiciones = ['(b.organizacion_id = ? OR b.organizacion_id IS NULL)'];
$parametros = [$org];
if ($filtro !== '' && isset(ACCIONES_BITACORA[$filtro])) {
    $condiciones[] = 'b.accion = ?';
    $parametros[] = $filtro;
}

$registros = filas(
    'SELECT b.*, u.nombre, u.apellido
       FROM bitacora b
       LEFT JOIN usuarios u ON u.id = b.usuario_id
      WHERE ' . implode(' AND ', $condiciones) . '
      ORDER BY b.fecha_hora DESC, b.id DESC
      LIMIT 200',
    $parametros,
);

$resumen = filas(
    'SELECT b.accion, COUNT(*) AS veces FROM bitacora b
      WHERE (b.organizacion_id = ? OR b.organizacion_id IS NULL) AND b.fecha_hora >= ?
      GROUP BY b.accion ORDER BY veces DESC',
    [$org, hace_dias(30) . ' 00:00:00'],
);

abrir_pagina(
    'Bitácora',
    'Cada corrección, cada anulación y cada hecho de acceso, con su responsable.',
    '/bitacora',
    ['Bitácora'],
);

echo ayuda_pantalla('¿Qué se guarda acá?', [
    'Correcciones' => 'Cuando alguien cambia la ficha de un animal, queda el dato anterior y el nuevo.',
    'Anulaciones' => 'Un pesaje, una entrega, un tratamiento, una baja o una nota anulados, con el motivo que escribió quien los anuló.',
    'Accesos' => 'Ingresos fallidos, bloqueos por muchos intentos y sesiones abiertas desde la app de terreno.',
    'Por qué' => 'Las altas se explican solas; los cambios y los borrados, no. Sin este registro no se puede reconstruir qué pasó con un dato que ya no está.',
]);
?>

<section class="indicadores">
  <?php foreach (array_slice($resumen, 0, 4) as $fila_resumen): ?>
    <div class="indicador <?= str_starts_with($fila_resumen['accion'], 'INGRESO') ? 'aviso' : '' ?>">
      <div class="rotulo"><?= h(ACCIONES_BITACORA[$fila_resumen['accion']] ?? legible($fila_resumen['accion'])) ?></div>
      <div class="cifra"><?= numero((int) $fila_resumen['veces']) ?></div>
      <div class="nota">en los últimos 30 días</div>
    </div>
  <?php endforeach; ?>
  <?php if ($resumen === []): ?>
    <div class="indicador ok">
      <div class="rotulo">Sin movimientos</div>
      <div class="cifra">0</div>
      <div class="nota">nadie corrigió ni anuló nada</div>
    </div>
  <?php endif; ?>
</section>

<div class="bloque">
  <div class="interior">
    <form class="filtros" method="get" action="<?= h(url('/bitacora')) ?>">
      <?= campo_ruta('/bitacora') ?>
      <div class="campo">
        <label for="accion">Tipo de registro</label>
        <select id="accion" name="accion">
          <option value="">Todos</option>
          <?php foreach (ACCIONES_BITACORA as $clave => $etiqueta): ?>
            <option value="<?= h($clave) ?>" <?= $filtro === $clave ? 'selected' : '' ?>><?= h($etiqueta) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="boton" type="submit">Filtrar</button>
      <?php if ($filtro !== ''): ?>
        <a class="boton suave" href="<?= h(url('/bitacora')) ?>">Ver todo</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="bloque">
  <header>
    <div>
      <h2>Últimos movimientos</h2>
      <div class="sub">Los 200 más recientes</div>
    </div>
    <a class="boton suave" href="<?= h(url_con('/exportar', ['tipo' => 'bitacora'])) ?>">Filtrar y exportar</a>
    <a class="boton suave" href="<?= h(url_con('/exportar', ['tipo' => 'bitacora', 'formato' => 'pdf'])) ?>">PDF</a>
  </header>
  <div class="tabla-scroll">
    <table data-sin-orden>
      <thead>
        <tr><th>Cuándo</th><th>Qué pasó</th><th>Quién</th><th>Detalle</th></tr>
      </thead>
      <tbody>
      <?php foreach ($registros as $registro): ?>
        <tr>
          <td>
            <?= fecha_corta(substr($registro['fecha_hora'], 0, 10)) ?>
            <div class="secundario"><?= h(substr($registro['fecha_hora'], 11, 5)) ?></div>
          </td>
          <td>
            <span class="principal"><?= h(ACCIONES_BITACORA[$registro['accion']] ?? legible($registro['accion'])) ?></span>
            <?php if ($registro['entidad'] !== null): ?>
              <div class="secundario"><?= h(legible($registro['entidad'])) ?><?= $registro['entidad_id'] !== null ? ' #' . (int) $registro['entidad_id'] : '' ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?= $registro['nombre'] !== null ? h($registro['nombre'] . ' ' . $registro['apellido']) : '—' ?>
            <div class="secundario"><?= h($registro['origen'] ?? '') ?></div>
          </td>
          <td><?= h($registro['detalle'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($registros === []): ?>
        <tr><td colspan="4" class="vacio">Todavía no hay movimientos registrados.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php cerrar_pagina(); ?>
