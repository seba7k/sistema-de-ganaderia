<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vistas/layout.php';
require_once __DIR__ . '/../dominio/indicadores.php';
require_once __DIR__ . '/../dominio/retiros.php';

exigir_permiso('ver_hacienda');

$org = organizacion_actual();
$predio = predio_actual();
$volver = url('/hacienda');

/* ============================================================
   Carga: pesada, alta de animal y baja
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = entrada('accion');
    $usuario_id = (int) usuario_actual()['id'];

    if ($accion === 'pesada') {
        exigir_post_valido('registrar_pesada', $volver);
        try {
            $resultado = op_pesada($org, $usuario_id, $_POST);
        } catch (ErrorOperacion $error) {
            volver_con_errores($error, $volver);
        }
        flash(
            'exito',
            $resultado['mensaje'] . ($resultado['alerta'] !== null ? ' Se guardó confirmado: ' . $resultado['alerta'] : ''),
        );
        redirigir($volver);
    }

    if ($accion === 'anular') {
        exigir_post_valido('anular_registro', $volver);
        try {
            $resultado = op_anular($org, $usuario_id, $_POST);
        } catch (ErrorOperacion $error) {
            volver_con_errores($error, $volver);
        }
        flash('exito', $resultado['mensaje']);
        redirigir($volver);
    }

    if ($accion === 'mortalidad') {
        exigir_post_valido('registrar_baja', $volver);
        try {
            $resultado = op_baja($org, $usuario_id, $_POST);
        } catch (ErrorOperacion $error) {
            volver_con_errores($error, $volver);
        }
        flash('exito', $resultado['mensaje']);
        redirigir($volver);
    }

    if ($accion === 'alta') {
        exigir_post_valido('registrar_animal', $volver);
        try {
            // El predio lo pone el servidor, no el formulario.
            $resultado = op_alta_animal($org, $usuario_id, ['establecimiento_id' => $predio] + $_POST);
        } catch (ErrorOperacion $error) {
            volver_con_errores($error, $volver);
        }
        flash('exito', $resultado['mensaje']);
        redirigir(url_con('/animal', ['id' => $resultado['id']]));
    }

    flash('error', 'No se reconoció qué se quería registrar.');
    redirigir($volver);
}

$busqueda = trim((string) ($_GET['q'] ?? ''));
$corral_filtro = (string) ($_GET['corral'] ?? '');
$lote_filtro = (string) ($_GET['lote'] ?? '');

$condiciones = ['a.organizacion_id = ?' . filtro_predio('a')];
$parametros = [$org];

if ($busqueda !== '') {
    $condiciones[] = '(a.caravana_visual LIKE ? OR a.eid LIKE ? OR a.numero_interno LIKE ?)';
    $parametros[] = "%{$busqueda}%";
    $parametros[] = '%' . str_replace(' ', '', $busqueda) . '%';
    $parametros[] = "%{$busqueda}%";
}
if ($corral_filtro !== '') {
    $condiciones[] = 'a.corral_actual_id = ?';
    $parametros[] = (int) $corral_filtro;
}
if ($lote_filtro !== '') {
    $condiciones[] = 'a.lote_actual_id = ?';
    $parametros[] = (int) $lote_filtro;
}

$total = (int) valor(
    'SELECT COUNT(*) FROM animales a WHERE ' . implode(' AND ', $condiciones) . " AND a.estado = 'ACTIVO'",
    $parametros,
);

$por_pagina = 100;
$paginas = max(1, (int) ceil($total / $por_pagina));
$pagina = max(1, min($paginas, (int) ($_GET['pagina'] ?? 1)));
$desplazamiento = ($pagina - 1) * $por_pagina;
$filtros_pagina = array_filter(
    ['q' => $busqueda, 'corral' => $corral_filtro, 'lote' => $lote_filtro],
    static fn (string $valor): bool => $valor !== '',
);

$animales = filas(
    'SELECT a.*, c.nombre AS corral, l.codigo AS lote
       FROM animales a
       LEFT JOIN corrales c ON c.id = a.corral_actual_id
       LEFT JOIN lotes l ON l.id = a.lote_actual_id
      WHERE ' . implode(' AND ', $condiciones) . "
        AND a.estado = 'ACTIVO'
      ORDER BY COALESCE(a.caravana_visual, a.numero_interno), a.id
      LIMIT $por_pagina OFFSET $desplazamiento",
    $parametros,
);

$bajas = filas(
    "SELECT m.id, m.fecha, m.causa_presunta, a.id AS animal_id, a.caravana_visual, a.numero_interno
       FROM mortalidades m
       JOIN animales a ON a.id = m.animal_id
      WHERE m.organizacion_id = ? AND m.anulada = 0" . filtro_predio('a') . "
      ORDER BY m.fecha DESC, m.id DESC
      LIMIT 15",
    [$org],
);

$corrales = filas('SELECT c.id, c.nombre FROM corrales c WHERE c.organizacion_id = ?' . filtro_predio('c') . ' ORDER BY c.nombre', [$org]);
$lotes = !GESTION_AVANZADA ? [] : filas('SELECT l.id, l.codigo, l.nombre FROM lotes l WHERE l.organizacion_id = ?' . filtro_predio('l') . ' ORDER BY l.codigo DESC', [$org]);

/* Carencia efectiva por animal: manda el tratamiento más restrictivo. */
$retiros = GESTION_AVANZADA ? carencias_activas($org, $predio) : [];

/*
 * Fichas de lo que se está filtrando.
 *
 * Cuando se llega desde otra pantalla —por ejemplo, tocando un corral— la lista
 * aparece recortada sin que nadie haya tocado un filtro. Las fichas dicen qué se
 * está viendo y permiten sacarlo de a uno.
 */
$fichas = [];
$sin = static function (string $quitar) use ($busqueda, $corral_filtro, $lote_filtro): string {
    $parametros = array_filter([
        'q'      => $quitar === 'q' ? '' : $busqueda,
        'corral' => $quitar === 'corral' ? '' : $corral_filtro,
        'lote'   => $quitar === 'lote' ? '' : $lote_filtro,
    ], static fn (string $valor): bool => $valor !== '');
    return url_con('/hacienda', $parametros);
};

if ($busqueda !== '') {
    $fichas[] = ['texto' => 'Coincide con «' . $busqueda . '»', 'url' => $sin('q')];
}
if ($corral_filtro !== '') {
    $nombre = '';
    foreach ($corrales as $corral) {
        if ((string) $corral['id'] === $corral_filtro) {
            $nombre = $corral['nombre'];
        }
    }
    $fichas[] = ['texto' => 'Corral: ' . ($nombre !== '' ? $nombre : $corral_filtro), 'url' => $sin('corral')];
}
if ($lote_filtro !== '') {
    $codigo = '';
    foreach ($lotes as $lote) {
        if ((string) $lote['id'] === $lote_filtro) {
            $codigo = $lote['codigo'];
        }
    }
    $fichas[] = ['texto' => 'Lote: ' . ($codigo !== '' ? $codigo : $lote_filtro), 'url' => $sin('lote')];
}

abrir_pagina(
    'Hacienda',
    'Cada animal con su identificación, su peso, su ganancia diaria'
    . (GESTION_AVANZADA ? ' y si puede salir a faena.' : ' y dónde está.'),
    '/hacienda',
    ['Hacienda'],
);

$ayuda = [
    'DIIO' => 'La caravana oficial del SAG, que se lee a distancia en el corral. Debajo, en gris, va el EID: el chip que levanta el lector en la manga. Los terneros sin DIIO se identifican con el número interno del predio.',
    'Peso' => 'El último pesaje registrado. Debajo, con cuánto entró el animal al establecimiento.',
    'Ganancia' => 'Kilos que gana por día (GDP). Se calcula entre el peso de ingreso y el último control. En feedlot, entre 1,0 y 1,5 kg/día es lo esperable. Un pesaje que baja o que supera ' . numero(GDP_MAXIMA_NORMAL, 1) . ' kg/día pide confirmación antes de guardarse.',
    'Faena' => '«Apto» significa que puede salir. Si dice «Faltan N días», tiene un medicamento en período de carencia: el sistema no deja venderlo, faenarlo ni sacarlo del predio (en Salidas ni en la venta por lote) hasta que se cumpla.',
];

// Sin gestión avanzada no hay carencia ni columna de faena que explicar.
if (!GESTION_AVANZADA) {
    unset($ayuda['Faena']);
}

echo ayuda_pantalla('¿Cómo se lee esta pantalla?', $ayuda);

/* ---------- Formularios de carga ---------- */

$errores = errores_formulario();
$fallo = valor_previo('accion');

$corrales_opciones = ['' => 'Sin corral'];
foreach ($corrales as $corral_opcion) {
    $corrales_opciones[(int) $corral_opcion['id']] = $corral_opcion['nombre'];
}

if (puede('registrar_pesada')) {
    $pide_confirmacion = $fallo === 'pesada' && valor_previo('requiere_confirmacion') === '1';

    echo abrir_alta('Registrar pesaje', 'Peso, hito de crecimiento y ganancia diaria', $errores !== [] && $fallo === 'pesada');
    echo formulario(url('/hacienda'), implode('', [
        '<input type="hidden" name="accion" value="pesada">',
        campo('identificacion', 'Animal', $errores, 'text', '', [
            'obligatorio' => true, 'placeholder' => 'DIIO, número interno o EID',
        ]),
        campo('fecha', 'Fecha', $errores, 'date', hoy(), ['obligatorio' => true, 'max' => hoy()]),
        campo('peso_kg', 'Peso', $errores, 'number', '', [
            'obligatorio' => true, 'step' => '0.5', 'min' => '10', 'ayuda' => 'En kilos',
        ]),
        campo('motivo', 'Hito', $errores, 'select', 'CONTROL', ['items' => [
            'CONTROL' => 'Control de engorda',
            'NACIMIENTO' => 'Nacimiento',
            'DESTETE' => 'Destete (pasa a recría)',
            'ENCASTE' => 'Primer encaste',
            'PREVENTA' => 'Previa a la venta',
            'SALIDA' => 'Salida',
        ]]),
        $pide_confirmacion
            ? '<div class="campo confirmar-dato"><label for="c_confirmar">'
              . '<input type="checkbox" id="c_confirmar" name="confirmar" value="1">'
              . 'Revisé el animal y la balanza: el peso es correcto</label></div>'
            : '',
    ]), 'Registrar pesaje');
    echo cerrar_alta();
}

if (puede('registrar_animal')) {
    echo abrir_alta('Dar de alta un animal', 'Compra o nacimiento sin registrar', $errores !== [] && $fallo === 'alta');
    echo formulario(url('/hacienda'), implode('', [
        '<input type="hidden" name="accion" value="alta">',
        campo('caravana_visual', 'DIIO (caravana oficial)', $errores, 'text', '', [
            'placeholder' => 'Ej: A-0142', 'ayuda' => 'DIIO o número interno: al menos uno',
        ]),
        campo('numero_interno', 'Número interno (paleta)', $errores, 'text', '', ['placeholder' => 'Ej: P-0087']),
        campo('eid', 'EID (chip)', $errores, 'text', '', ['placeholder' => '15 dígitos', 'maxlength' => 18]),
        campo('sexo', 'Sexo', $errores, 'select', 'HEMBRA', ['items' => [
            'HEMBRA' => 'Hembra', 'MACHO' => 'Macho', 'MACHO_CASTRADO' => 'Macho castrado',
        ]]),
        campo('categoria', 'Categoría', $errores, 'select', 'TERNERO', ['items' => [
            'TERNERO' => 'Ternero', 'TERNERA' => 'Ternera', 'NOVILLO' => 'Novillo',
            'VAQUILLA' => 'Vaquilla', 'VACA' => 'Vaca', 'TORO' => 'Toro', 'BUEY' => 'Buey',
        ]]),
        campo('raza', 'Raza', $errores, 'text', '', ['placeholder' => 'Ej: Angus']),
        campo('origen', 'Origen', $errores, 'select', 'COMPRADO', ['items' => [
            'COMPRADO' => 'Comprado', 'NACIDO_EN_PREDIO' => 'Nacido en el predio',
        ]]),
        campo('etapa', 'Etapa', $errores, 'select', 'ENGORDA', ['items' => [
            'CRIA' => 'Cría', 'RECRIA' => 'Recría', 'ENGORDA' => 'Engorda', 'REPRODUCCION' => 'Reproducción',
        ]]),
        campo('fecha_nacimiento', 'Fecha de nacimiento', $errores, 'date', '', ['max' => hoy()]),
        campo('fecha_ingreso', 'Fecha de ingreso', $errores, 'date', hoy(), ['obligatorio' => true, 'max' => hoy()]),
        campo('peso_ingreso_kg', 'Peso de ingreso', $errores, 'number', '', [
            'obligatorio' => true, 'step' => '0.5', 'min' => '10', 'ayuda' => 'En kilos',
        ]),
        campo('corral_id', 'Corral', $errores, 'select', '', ['items' => $corrales_opciones]),
        campo('madre', 'Madre', $errores, 'text', '', [
            'placeholder' => 'DIIO o número interno', 'ayuda' => 'Solo si nació en el predio',
        ]),
        campo('padre', 'Padre (toro)', $errores, 'text', '', [
            'placeholder' => 'DIIO del toro', 'ayuda' => 'Si fue por monta natural',
        ]),
    ]), 'Dar de alta');
    echo cerrar_alta();
}

if (puede('registrar_baja')) {
    echo abrir_alta(
        'Registrar baja por muerte',
        GESTION_AVANZADA ? 'Descuenta la cabeza del lote' : 'Queda el animal y la fecha',
        $errores !== [] && $fallo === 'mortalidad',
    );
    echo formulario(url('/hacienda'), implode('', [
        '<input type="hidden" name="accion" value="mortalidad">',
        campo('identificacion', 'Animal', $errores, 'text', '', [
            'obligatorio' => true, 'placeholder' => 'DIIO, número interno o EID',
        ]),
        campo('fecha', 'Fecha', $errores, 'date', hoy(), ['obligatorio' => true, 'max' => hoy()]),
        // La causa de muerte solo se pide con la gestión avanzada: el documento de
        // requerimientos pide que la baja sea el animal y la fecha, nada más.
        GESTION_AVANZADA
            ? campo('causa', 'Causa presunta', $errores, 'text', '', [
                'placeholder' => 'Ej: timpanismo, neumonía',
                'ayuda' => 'Si hubo necropsia, anotá el diagnóstico',
            ])
            : '',
    ]), 'Registrar baja');
    echo cerrar_alta();
}
?>

<div class="bloque">
  <div class="interior">
    <form class="filtros" method="get" action="<?= h(url('/hacienda')) ?>">
      <?= campo_ruta('/hacienda') ?>
      <div class="campo">
        <label for="q">Buscar animal</label>
        <input type="search" id="q" name="q" value="<?= h($busqueda) ?>" placeholder="DIIO, número interno o EID">
      </div>
      <div class="campo">
        <label for="corral">Corral</label>
        <select id="corral" name="corral">
          <option value="">Todos los corrales</option>
          <?php foreach ($corrales as $corral): ?>
            <option value="<?= (int) $corral['id'] ?>" <?= $corral_filtro === (string) $corral['id'] ? 'selected' : '' ?>>
              <?= h($corral['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="lote">Lote</label>
        <select id="lote" name="lote">
          <option value="">Todos los lotes</option>
          <?php foreach ($lotes as $lote): ?>
            <option value="<?= (int) $lote['id'] ?>" <?= $lote_filtro === (string) $lote['id'] ? 'selected' : '' ?>>
              <?= h($lote['codigo']) ?><?= $lote['nombre'] !== null ? ' — ' . h($lote['nombre']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="boton" type="submit">Filtrar</button>
      <?php if ($fichas !== []): ?>
        <a class="boton suave" href="<?= h(url('/hacienda')) ?>">Limpiar todo</a>
      <?php endif; ?>
    </form>

    <?php if ($fichas !== []): ?>
      <div class="fichas" style="margin-top: var(--e3)">
        <span style="font-size: 13px; color: var(--tinta-2)">Viendo:</span>
        <?php foreach ($fichas as $ficha): ?>
          <span class="ficha">
            <?= h($ficha['texto']) ?>
            <a class="quitar" href="<?= h($ficha['url']) ?>" title="Quitar este filtro" aria-label="Quitar filtro">✕</a>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="bloque">
  <header>
    <div>
      <h2>Animales</h2>
      <div class="sub"><?= numero($total) ?> activo<?= $total === 1 ? '' : 's' ?><?= $total > 100 ? ' · se muestran los primeros 100' : '' ?> · tocá uno para ver su ficha</div>
    </div>
    <a class="boton suave" href="<?= h(url_con('/exportar', ['tipo' => 'animales'])) ?>">Filtrar y exportar</a>
    <a class="boton suave" href="<?= h(url_con('/exportar', ['tipo' => 'animales', 'formato' => 'pdf'])) ?>">PDF</a>
    <?php if ($corral_filtro !== ''): ?>
      <a class="boton suave" href="<?= h(url('/corrales')) ?>">Ver todos los corrales</a>
    <?php endif; ?>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead>
        <tr>
          <th>Animal</th>
          <th>Categoría</th>
          <th>Ubicación</th>
          <th class="num">Peso</th>
          <th class="num">Ganancia</th>
          <?php if (GESTION_AVANZADA): ?><th>Faena</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($animales as $animal): ?>
        <?php
        $dias = dias_entre($animal['fecha_ingreso'], $animal['fecha_ultima_pesada'] ?? date('Y-m-d'));
        $adg = $animal['peso_actual_kg'] !== null
            ? calcular_adg((float) $animal['peso_ingreso_kg'], (float) $animal['peso_actual_kg'], $dias)
            : null;
        $retiro = $retiros[(int) $animal['id']] ?? null;
        $ficha_animal = url_con('/animal', ['id' => (int) $animal['id']]);
        ?>
        <tr data-ir="<?= h($ficha_animal) ?>">
          <td>
            <a class="clave principal" href="<?= h($ficha_animal) ?>"><?= h($animal['caravana_visual'] ?? $animal['numero_interno'] ?? '—') ?></a>
            <?php if ($animal['eid'] !== null): ?>
              <div class="secundario clave">
                <?= h(substr($animal['eid'], 0, 3) . ' ' . substr($animal['eid'], 3)) ?>
              </div>
            <?php elseif ($animal['caravana_visual'] === null): ?>
              <div class="secundario">sin DIIO todavía</div>
            <?php endif; ?>
          </td>
          <td>
            <?= h(legible($animal['categoria'])) ?>
            <div class="secundario"><?= h($animal['raza'] ?? '') ?></div>
          </td>
          <td>
            <?php if ($animal['corral_actual_id'] !== null): ?>
              <a href="<?= h(url_con('/hacienda', ['corral' => (int) $animal['corral_actual_id']])) ?>"><?= h($animal['corral']) ?></a>
            <?php else: ?>
              Sin corral
            <?php endif; ?>
            <div class="secundario">
              <?php if ($animal['lote_actual_id'] !== null): ?>
                <a href="<?= h(url_con('/hacienda', ['lote' => (int) $animal['lote_actual_id']])) ?>"><?= h($animal['lote']) ?></a>
              <?php else: ?>
                —
              <?php endif; ?>
            </div>
          </td>
          <td class="num">
            <span class="principal"><?= $animal['peso_actual_kg'] === null ? '—' : numero((float) $animal['peso_actual_kg']) ?></span>
            <div class="secundario">entró con <?= numero((float) $animal['peso_ingreso_kg']) ?></div>
          </td>
          <td class="num">
            <span class="principal"><?= $adg === null ? '—' : numero($adg, 2) ?></span>
            <div class="secundario">kg/día</div>
          </td>
          <?php if (GESTION_AVANZADA): ?>
          <td>
            <?php if ($retiro === null): ?>
              <?= insignia('Apto', 'ok') ?>
            <?php else: ?>
              <?= insignia('Faltan ' . $retiro['dias_restantes'] . ' d', $retiro['estado'] === 'POR_VENCER' ? 'aviso' : 'riesgo') ?>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if ($animales === []): ?>
        <tr><td colspan="<?= GESTION_AVANZADA ? 6 : 5 ?>" class="vacio">
          Ningún animal coincide con el filtro.
          <?php if ($fichas !== []): ?>
            <br><a href="<?= h(url('/hacienda')) ?>">Ver todos los animales</a>
          <?php endif; ?>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($paginas > 1): ?>
  <nav class="pie-bloque acciones-fila" aria-label="Paginación de Hacienda">
    <?php if ($pagina > 1): ?>
      <a class="boton menudo" href="<?= h(url_con('/hacienda', $filtros_pagina + ['pagina' => $pagina - 1])) ?>">Anterior</a>
    <?php endif; ?>
    <?php if ($pagina < $paginas): ?>
      <a class="boton menudo" href="<?= h(url_con('/hacienda', $filtros_pagina + ['pagina' => $pagina + 1])) ?>">Siguiente</a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>
</div>

<?php if ($bajas !== []): ?>
<div class="bloque">
  <header>
    <div>
      <h2>Últimas bajas</h2>
      <div class="sub">Si una se cargó por error, se anula y el animal vuelve al inventario</div>
    </div>
  </header>
  <div class="tabla-scroll">
    <table data-sin-buscador>
      <thead>
        <tr><th>Fecha</th><th>Animal</th><?php if (GESTION_AVANZADA): ?><th>Causa</th><?php endif; ?><?php if (puede('anular_registro')): ?><th></th><?php endif; ?></tr>
      </thead>
      <tbody>
      <?php foreach ($bajas as $baja): ?>
        <tr>
          <td><?= fecha_corta($baja['fecha']) ?></td>
          <td>
            <a class="clave principal" href="<?= h(url_con('/animal', ['id' => (int) $baja['animal_id']])) ?>">
              <?= h($baja['caravana_visual'] ?? $baja['numero_interno'] ?? '—') ?>
            </a>
          </td>
          <?php if (GESTION_AVANZADA): ?><td><?= h($baja['causa_presunta'] ?? '—') ?></td><?php endif; ?>
          <?php if (puede('anular_registro')): ?>
            <td class="num"><?= boton_anular(url('/hacienda'), 'BAJA', (int) $baja['id']) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php cerrar_pagina(); ?>
