<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vistas/layout.php';
require_once __DIR__ . '/../dominio/sag.php';

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

    /* Alta y edición comparten las reglas. Al editar, el predio tiene que ser
       de esta organización: el id llega del formulario y se puede cambiar. */
    $editando = entrada('accion') === 'editar';
    $predio_id = $editando ? (entrada_entera('predio_id') ?? 0) : 0;
    $anterior = null;
    if ($editando) {
        $anterior = fila('SELECT * FROM establecimientos WHERE organizacion_id = ? AND id = ?', [$org, $predio_id]);
        if ($anterior === null) {
            flash('error', 'Ese predio no es de esta organización.');
            redirigir($volver);
        }
    }

    $validacion = (new Validacion())
        ->obligatorio('nombre', 'El nombre del predio')
        ->obligatorio('tipo', 'El tipo');

    if (entrada('tipo') !== '' && !isset(TIPOS_PREDIO[entrada('tipo')])) {
        $validacion->agregar('tipo', 'Ese tipo de predio no existe.');
    }
    if (entrada('nombre') !== '' && (int) valor(
        'SELECT COUNT(*) FROM establecimientos WHERE organizacion_id = ? AND nombre = ? AND id <> ?',
        [$org, entrada('nombre'), $predio_id],
    ) > 0) {
        $validacion->agregar('nombre', 'Ya hay un predio con ese nombre.');
    }

    /* El RUP identifica al predio ante el SAG: se guarda siempre con el mismo
       formato y no puede repetirse entre los campos del propietario. */
    $rup = null;
    if (entrada('rup') !== '') {
        $rup = normalizar_rup(entrada('rup'));
        if ($rup === null) {
            $validacion->agregar('rup', 'El RUP tiene 9 dígitos: región, provincia, comuna y número del predio (ej: 09.1.01.0001).');
        } elseif ((int) valor(
            'SELECT COUNT(*) FROM establecimientos WHERE organizacion_id = ? AND rup = ? AND id <> ?',
            [$org, $rup, $predio_id],
        ) > 0) {
            $validacion->agregar('rup', 'Ese RUP ya está cargado en otro predio.');
        }
    }

    // Al editar, los errores vuelven abiertos en el formulario de ese predio.
    if ($editando) {
        $_POST['editando'] = (string) $predio_id;
    }
    $validacion->o_volver($volver);

    $datos = [
        'nombre' => entrada('nombre'),
        'tipo'   => entrada('tipo'),
        'rup'    => $rup,
        'comuna' => entrada('comuna') !== '' ? entrada('comuna') : null,
        'region' => entrada('region') !== '' ? entrada('region') : null,
    ];

    if (!$editando) {
        consultar(
            'INSERT INTO establecimientos (organizacion_id, nombre, tipo, rup, comuna, region) VALUES (?, ?, ?, ?, ?, ?)',
            [$org, ...array_values($datos)],
        );
        flash('exito', 'Predio «' . entrada('nombre') . '» creado. Elegilo arriba para empezar a cargarle animales.');
        redirigir($volver);
    }

    $cambios = [];
    foreach (['nombre' => 'nombre', 'tipo' => 'tipo', 'rup' => 'RUP', 'comuna' => 'comuna', 'region' => 'región'] as $columna => $rotulo) {
        if ((string) $anterior[$columna] !== (string) $datos[$columna]) {
            $cambios[] = $rotulo . ': ' . ($anterior[$columna] ?? '—') . ' → ' . ($datos[$columna] ?? '—');
        }
    }
    if ($cambios === []) {
        flash('error', 'No cambiaste ningún dato de «' . $anterior['nombre'] . '».');
        redirigir($volver);
    }

    en_transaccion(static function () use ($org, $predio_id, $datos, $cambios): void {
        consultar(
            'UPDATE establecimientos SET nombre = ?, tipo = ?, rup = ?, comuna = ?, region = ? WHERE id = ? AND organizacion_id = ?',
            [...array_values($datos), $predio_id, $org],
        );
        // Cambiar el RUP de un predio con animales es delicado: queda en la bitácora.
        registrar_bitacora($org, (int) usuario_actual()['id'], 'EDITA_PREDIO', 'establecimientos', $predio_id, implode(' · ', $cambios));
    });

    flash('exito', 'Predio «' . entrada('nombre') . '» actualizado.');
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
    'Editar' => 'Cada predio tiene su botón «Editar» para corregir nombre, tipo, RUP o ubicación. Los cambios quedan en la bitácora.',
]);

$errores = errores_formulario();

$editando = valor_previo('editando');
echo abrir_alta('Agregar un predio', 'Otro campo del mismo propietario', $errores !== [] && $editando === '');
echo formulario(url('/predios'), implode('', [
    campo('nombre', 'Nombre', $errores, 'text', '', ['obligatorio' => true, 'placeholder' => 'Ej: Fundo El Sauce']),
    campo('tipo', 'Tipo', $errores, 'select', 'CRIANZA', ['items' => TIPOS_PREDIO]),
    campo('rup', 'RUP', $errores, 'text', '', ['placeholder' => 'Ej: 09.1.01.0001', 'ayuda' => 'Rol único pecuario del predio: 9 dígitos']),
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
            <div class="secundario"><?= h(TIPOS_PREDIO[$predio['tipo']] ?? legible($predio['tipo'])) ?><?= $predio['rup'] !== null ? ' · RUP ' . h($predio['rup']) : ' · sin RUP' ?></div>
            <?php
            // Si la última edición de este predio falló, el formulario vuelve
            // abierto con lo que se había escrito; si no, con los datos guardados.
            $con_error = $editando === (string) $predio['id'];
            $previo = static fn (string $c): string => $con_error ? valor_previo($c) : (string) ($predio[$c] ?? '');
            ?>
            <details class="editar-predio"<?= $con_error ? ' open' : '' ?>>
              <summary class="boton menudo">Editar</summary>
              <form method="post" action="<?= h(url('/predios')) ?>" class="formulario">
                <input type="hidden" name="csrf" value="<?= h(token_csrf()) ?>">
                <input type="hidden" name="accion" value="editar">
                <input type="hidden" name="predio_id" value="<?= (int) $predio['id'] ?>">
                <?php foreach ([
                    ['nombre', 'Nombre', 'text', ''], ['rup', 'RUP', 'text', '09.1.01.0001'],
                    ['comuna', 'Comuna', 'text', ''], ['region', 'Región', 'text', ''],
                ] as [$c, $rotulo, $tipo_campo, $ejemplo]): ?>
                  <div class="campo<?= $con_error && isset($errores[$c]) ? ' con-error' : '' ?>">
                    <label for="p<?= (int) $predio['id'] . '_' . $c ?>"><?= h($rotulo) ?></label>
                    <input id="p<?= (int) $predio['id'] . '_' . $c ?>" type="<?= $tipo_campo ?>" name="<?= $c ?>"
                           value="<?= h($previo($c)) ?>" placeholder="<?= h($ejemplo) ?>"<?= $c === 'nombre' ? ' required' : '' ?>>
                    <?php if ($con_error && isset($errores[$c])): ?><div class="error-campo"><?= h($errores[$c]) ?></div><?php endif; ?>
                  </div>
                <?php endforeach; ?>
                <div class="campo">
                  <label for="p<?= (int) $predio['id'] ?>_tipo">Tipo</label>
                  <select id="p<?= (int) $predio['id'] ?>_tipo" name="tipo">
                    <?php foreach (TIPOS_PREDIO as $clave_tipo => $nombre_tipo): ?>
                      <option value="<?= h($clave_tipo) ?>"<?= $previo('tipo') === $clave_tipo ? ' selected' : '' ?>><?= h($nombre_tipo) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="acciones"><button class="boton" type="submit">Guardar cambios</button></div>
              </form>
            </details>
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
