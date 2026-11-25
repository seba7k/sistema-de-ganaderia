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

const TIPOS_PREDIO = [
    'CRIANZA'        => 'Crianza',
    'LECHERIA'       => 'Lechería',
    'CAMPO_PASTORIL' => 'Campo pastoril',
    'FEEDLOT'        => 'Feedlot',
    'MIXTO'          => 'Mixto',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_post_valido('gestionar_predios', $volver);

    $validacion = (new Validacion())
        ->obligatorio('nombre', 'El nombre del predio')
        ->obligatorio('tipo', 'El tipo');

    if (entrada('tipo') !== '' && !isset(TIPOS_PREDIO[entrada('tipo')])) {
        $validacion->agregar('tipo', 'Ese tipo de predio no existe.');
    }
    if (entrada('nombre') !== '' && (int) valor(
        'SELECT COUNT(*) FROM establecimientos WHERE organizacion_id = ? AND nombre = ?',
        [$org, entrada('nombre')],
    ) > 0) {
        $validacion->agregar('nombre', 'Ya hay un predio con ese nombre.');
    }

    $validacion->o_volver($volver);

    consultar(
        'INSERT INTO establecimientos (organizacion_id, nombre, tipo, rup, comuna, region) VALUES (?, ?, ?, ?, ?, ?)',
        [
            $org, entrada('nombre'), entrada('tipo'),
            entrada('rup') !== '' ? entrada('rup') : null,
            entrada('comuna') !== '' ? entrada('comuna') : null,
            entrada('region') !== '' ? entrada('region') : null,
        ],
    );

    flash('exito', 'Predio «' . entrada('nombre') . '» creado. Elegilo arriba para empezar a cargarle animales.');
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
    'RUP' => 'El rol único pecuario del predio, si lo tiene. Es el número con el que el SAG identifica el campo, no al dueño.',
]);

$errores = errores_formulario();

echo abrir_alta('Agregar un predio', 'Otro campo del mismo propietario', $errores !== []);
echo formulario(url('/predios'), implode('', [
    campo('nombre', 'Nombre', $errores, 'text', '', ['obligatorio' => true, 'placeholder' => 'Ej: Fundo El Sauce']),
    campo('tipo', 'Tipo', $errores, 'select', 'CRIANZA', ['items' => TIPOS_PREDIO]),
    campo('rup', 'RUP', $errores, 'text', '', ['placeholder' => 'Ej: 12.3.45.0001', 'ayuda' => 'Rol único pecuario del predio']),
    campo('comuna', 'Comuna', $errores, 'text', '', ['placeholder' => 'Ej: Loncoche']),
    campo('region', 'Región', $errores, 'text', '', ['placeholder' => 'Ej: La Araucanía']),
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
            <div class="secundario"><?= h(TIPOS_PREDIO[$predio['tipo']] ?? legible($predio['tipo'])) ?><?= $predio['rup'] !== null ? ' · RUP ' . h($predio['rup']) : '' ?></div>
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
      <?php endforeach; ?>
      <?php if ($predios === []): ?>
        <tr><td colspan="5" class="vacio">Todavía no hay predios. Agregá el primero arriba.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php cerrar_pagina(); ?>
