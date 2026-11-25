<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vistas/layout.php';

/**
 * Predios del propietario.
 *
 * Una organización es el propietario, identificado por su RUT —como en el
 * registro del SAG—, y cuelga de él cada campo que trabaja. El sistema muestra
 * un predio a la vez: el que esté elegido en la barra superior.
 */

exigir_permiso('gestionar_predios');

$org = organizacion_actual();
$volver = url('/predios');

// Las reglas de alta y edición viven en op_crear_predio / op_editar_predio.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_post_valido('gestionar_predios', $volver);
    try {
        $resultado = entrada_entera('predio_id') !== null
            ? op_editar_predio($org, (int) usuario_actual()['id'], $_POST)
            : op_crear_predio($org, (int) usuario_actual()['id'], $_POST);
    } catch (ErrorOperacion $error) {
        volver_con_errores($error, $volver);
    }

    flash('exito', $resultado['mensaje']);
    redirigir($volver);
}

$propietario = fila('SELECT nombre, rut FROM organizaciones WHERE id = ?', [$org]);

$predios = filas(
    "SELECT e.*,
            (SELECT COUNT(*) FROM animales a WHERE a.establecimiento_id = e.id AND a.estado = 'ACTIVO') AS animales,
            (SELECT COUNT(*) FROM corrales c WHERE c.establecimiento_id = e.id) AS corrales
       FROM establecimientos e
      WHERE e.organizacion_id = ?
      ORDER BY e.nombre",
    [$org],
);

$total_animales = (int) array_sum(array_column($predios, 'animales'));

abrir_pagina(
    'Predios',
    'Los campos de ' . ($propietario['nombre'] ?? '') . '. Arriba se elige en cuál se está trabajando.',
    '/predios',
    ['Predios'],
);

echo ayuda_pantalla('¿Cómo funcionan los predios?', [
    'Propietario' => 'Todos los predios cuelgan de un mismo propietario, identificado por su RUT. Es el mismo criterio del registro del SAG.',
    'Predio activo' => 'El sistema muestra un predio a la vez. Hacienda, corrales, alimentación y reproducción solo traen lo de ese campo; sumar predios distintos daría números sin sentido.',
    'Cambiar de predio' => 'Desde el selector de la barra superior, o desde el menú en el teléfono. Lo que se cargue después queda en el predio elegido.',
    'RUP' => 'El rol único pecuario del predio, si lo tiene. Es el número con el que el SAG identifica el campo, no al dueño. Tiene 9 dígitos y se guarda como 09.1.01.0001; no puede repetirse entre predios.',
    'Editar' => 'Cada predio tiene su enlace «Editar» para corregir nombre, tipo, RUP o ubicación. Los cambios quedan en la bitácora.',
]);

$errores = errores_formulario();

/* Qué predio se está editando: el que se pidió con ?editar=, o el que volvió
   con errores (su predio_id quedó recordado). Los errores de una edición
   fallida van a ese formulario y no al de alta. */
$editando = (int) valor_previo('predio_id', (string) (int) ($_GET['editar'] ?? 0));
$errores_alta = $editando === 0 ? $errores : [];

echo abrir_alta('Agregar un predio', 'Otro campo del mismo propietario', $errores_alta !== []);
$en_alta = static fn (array $o = []): array => $editando === 0 ? $o : $o + ['valor' => ''];
echo formulario(url('/predios'), implode('', [
    campo('nombre', 'Nombre', $errores_alta, 'text', '', $en_alta(['obligatorio' => true, 'placeholder' => 'Ej: Fundo El Sauce'])),
    campo('tipo', 'Tipo', $errores_alta, 'select', 'CRIANZA', ['items' => TIPOS_PREDIO]),
    campo('rup', 'RUP', $errores_alta, 'text', '', $en_alta(['placeholder' => 'Ej: 09.1.01.0001', 'ayuda' => 'Rol único pecuario del predio: 9 dígitos'])),
    campo('comuna', 'Comuna', $errores_alta, 'text', '', $en_alta(['placeholder' => 'Ej: Loncoche'])),
    campo('region', 'Región', $errores_alta, 'text', '', $en_alta(['placeholder' => 'Ej: La Araucanía'])),
]), 'Agregar predio');
echo cerrar_alta();
?>

<section class="indicadores">
  <div class="indicador">
    <div class="rotulo">Propietario</div>
    <div class="cifra" style="font-size: 22px"><?= h($propietario['nombre'] ?? '—') ?></div>
    <div class="nota">RUT <?= h($propietario['rut'] ?? 'sin registrar') ?></div>
  </div>
  <div class="indicador">
    <div class="rotulo">Predios</div>
    <div class="cifra"><?= numero(count($predios)) ?></div>
    <div class="nota">bajo el mismo RUT</div>
  </div>
  <div class="indicador ok">
    <div class="rotulo">Hacienda total</div>
    <div class="cifra"><?= numero($total_animales) ?></div>
    <div class="nota">animales activos en todos los predios</div>
  </div>
</section>

<div class="bloque">
  <header>
    <div>
      <h2>Campos</h2>
      <div class="sub">Tocá «Trabajar acá» para que el sistema muestre ese predio</div>
    </div>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead>
        <tr><th>Predio</th><th>Ubicación</th><th class="num">Corrales</th><th class="num">Animales</th><th>Estado</th></tr>
      </thead>
      <tbody>
      <?php foreach ($predios as $predio): ?>
        <tr>
          <td>
            <span class="principal"><?= h($predio['nombre']) ?></span>
            <div class="secundario"><?= h(TIPOS_PREDIO[$predio['tipo']] ?? legible($predio['tipo'])) ?><?= $predio['rup'] !== null ? ' · RUP ' . h($predio['rup']) : ' · sin RUP' ?></div>
            <a class="boton menudo" href="<?= h(url_con('/predios', ['editar' => (int) $predio['id']])) ?>#predio-<?= (int) $predio['id'] ?>">Editar</a>
          </td>
          <td>
            <?= h($predio['comuna'] ?? '—') ?>
            <div class="secundario"><?= h($predio['region'] ?? '') ?></div>
          </td>
          <td class="num"><?= numero((int) $predio['corrales']) ?></td>
          <td class="num principal"><?= numero((int) $predio['animales']) ?></td>
          <td>
            <?php if ((int) $predio['id'] === predio_actual()): ?>
              <?= insignia('Trabajando acá', 'ok') ?>
            <?php else: ?>
              <form method="post" action="<?= h(url('/predio')) ?>">
                <input type="hidden" name="csrf" value="<?= h(token_csrf()) ?>">
                <input type="hidden" name="id" value="<?= (int) $predio['id'] ?>">
                <input type="hidden" name="volver" value="/predios">
                <button class="boton suave" type="submit">Trabajar acá</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ((int) $predio['id'] === $editando): ?>
          <?php
          // Con errores vuelve lo que se había escrito; si no, lo guardado.
          $con_error = $errores !== [];
          $id_campo = static fn (string $c): string => 'p' . (int) $predio['id'] . '_' . $c;
          $valor = static fn (string $c): string => $con_error ? valor_previo($c) : (string) ($predio[$c] ?? '');
          ?>
          <tr class="fila-edicion" id="predio-<?= (int) $predio['id'] ?>">
            <td colspan="5">
              <h3>Editar «<?= h($predio['nombre']) ?>»</h3>
              <?= formulario(url('/predios'), implode('', [
                  '<input type="hidden" name="predio_id" value="' . (int) $predio['id'] . '">',
                  campo('nombre', 'Nombre', $errores, 'text', '', ['obligatorio' => true, 'id' => $id_campo('nombre'), 'valor' => $valor('nombre')]),
                  campo('tipo', 'Tipo', $errores, 'select', '', ['items' => TIPOS_PREDIO, 'id' => $id_campo('tipo'), 'valor' => $valor('tipo')]),
                  campo('rup', 'RUP', $errores, 'text', '', ['placeholder' => 'Ej: 09.1.01.0001', 'ayuda' => 'Vacío si el predio no tiene', 'id' => $id_campo('rup'), 'valor' => $valor('rup')]),
                  campo('comuna', 'Comuna', $errores, 'text', '', ['id' => $id_campo('comuna'), 'valor' => $valor('comuna')]),
                  campo('region', 'Región', $errores, 'text', '', ['id' => $id_campo('region'), 'valor' => $valor('region')]),
              ]), 'Guardar cambios', '<a class="boton suave" href="' . h(url('/predios')) . '">Cancelar</a>') ?>
            </td>
          </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($predios === []): ?>
        <tr><td colspan="5" class="vacio">Todavía no hay predios. Agregá el primero arriba.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php cerrar_pagina(); ?>
