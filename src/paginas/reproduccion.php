<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vistas/layout.php';
require_once __DIR__ . '/../dominio/reproduccion.php';

/**
 * Reproducción del rodeo de cría.
 *
 * Responde tres preguntas: qué vacas van a parir y cuándo, cómo viene la
 * temporada comparada con lo esperable, y qué vacas conviene descartar.
 */

exigir_permiso('ver_reproduccion');

$org = organizacion_actual();
$predio = predio_actual();
$volver = url('/reproduccion');

/* ============================================================
   Carga: servicio, diagnóstico de preñez y parto
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = entrada('accion');

    // Cada registro tiene su permiso: el operario anota partos, pero el
    // servicio y el diagnóstico de preñez son del veterinario.
    exigir_post_valido(match ($accion) {
        'servicio'    => 'registrar_servicio',
        'diagnostico' => 'registrar_diagnostico',
        'secado'      => 'registrar_secado',
        default       => 'registrar_parto',
    }, $volver);

    if ($accion === 'secado') {
        try {
            $resultado = op_secado($org, (int) usuario_actual()['id'], $_POST);
        } catch (ErrorOperacion $error) {
            volver_con_errores($error, $volver);
        }
        flash('exito', $resultado['mensaje']);
        redirigir($volver);
    }

    /** Busca una vaca del rodeo por cualquiera de sus identificaciones. */
    $buscar_vaca = static function (string $identificacion) use ($org): ?array {
        return fila(
            "SELECT * FROM animales
              WHERE organizacion_id = ? AND estado = 'ACTIVO' AND sexo = 'HEMBRA'
                AND (caravana_visual = ? OR numero_interno = ? OR eid = ?)",
            [$org, $identificacion, $identificacion, $identificacion],
        );
    };

    if ($accion === 'servicio') {
        $validacion = (new Validacion())
            ->obligatorio('identificacion', 'La vaca')
            ->obligatorio('fecha', 'La fecha')
            ->fecha('fecha', 'La fecha');

        $vaca = $buscar_vaca(entrada('identificacion'));
        if ($vaca === null) {
            $validacion->agregar('identificacion', 'No existe una hembra activa con esa identificación.');
        } else {
            // Una vaca no puede tener dos servicios abiertos: o está esperando
            // diagnóstico, o ya está preñada.
            $abierto = fila(
                "SELECT s.id, s.resultado FROM servicios s
                   LEFT JOIN partos p ON p.servicio_id = s.id
                  WHERE s.organizacion_id = ? AND s.vaca_id = ? AND p.id IS NULL
                    AND s.resultado IN ('PENDIENTE', 'PRENADA')",
                [$org, (int) $vaca['id']],
            );
            if ($abierto !== null) {
                $validacion->agregar(
                    'identificacion',
                    'Esa vaca ya tiene un servicio sin cerrar (' . mb_strtolower(legible($abierto['resultado'])) . ').',
                );
            }
        }

        $es_inseminacion = entrada('tipo') === 'INSEMINACION';
        if (!in_array(entrada('tipo'), ['MONTA_NATURAL', 'INSEMINACION'], true)) {
            $validacion->agregar('tipo', 'Ese tipo de servicio no existe.');
        }
        $pajuela = null;
        $toro = null;
        if ($es_inseminacion) {
            $pajuela = fila('SELECT * FROM pajuelas WHERE organizacion_id = ? AND id = ?', [$org, entrada_entera('pajuela_id') ?? 0]);
            if ($pajuela === null) {
                $validacion->agregar('pajuela_id', 'Elegí la pajuela usada.');
            } elseif ((int) $pajuela['dosis_disponibles'] < 1) {
                $validacion->agregar('pajuela_id', 'No quedan dosis de esa pajuela.');
            }
        } else {
            /* El toro se busca dentro de la organización: sin esto, un id
               cualquiera dejaba apuntada la ficha a un animal de otro campo. */
            $toro = fila(
                "SELECT id FROM animales
                  WHERE organizacion_id = ? AND id = ? AND sexo != 'HEMBRA' AND estado = 'ACTIVO'",
                [$org, entrada_entera('toro_id') ?? 0],
            );
            if ($toro === null) {
                $validacion->agregar('toro_id', 'Elegí el toro que la sirvió.');
            }
        }

        $validacion->o_volver($volver);

        db()->beginTransaction();
        try {
            consultar(
                'INSERT INTO servicios (organizacion_id, vaca_id, tipo, fecha, toro_id, pajuela_id,
                                        resultado, responsable_id, observaciones)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $org, (int) $vaca['id'], entrada('tipo'), entrada('fecha'),
                    $es_inseminacion ? null : (int) $toro['id'],
                    $es_inseminacion ? (int) $pajuela['id'] : null,
                    'PENDIENTE', usuario_actual()['id'],
                    entrada('observaciones') !== '' ? entrada('observaciones') : null,
                ],
            );
            if ($es_inseminacion) {
                consultar(
                    'UPDATE pajuelas SET dosis_disponibles = dosis_disponibles - 1 WHERE id = ? AND organizacion_id = ?',
                    [(int) $pajuela['id'], $org],
                );
            }
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $error;
        }

        flash(
            'exito',
            'Servicio registrado. Si queda preñada, el parto sería alrededor del '
            . fecha_corta(fecha_probable_parto(entrada('fecha'))) . '.',
        );
        redirigir($volver);
    }

    if ($accion === 'diagnostico') {
        if (!in_array(entrada('resultado'), ['PRENADA', 'VACIA'], true)) {
            flash('error', 'El diagnóstico solo puede ser preñada o vacía.');
            redirigir($volver);
        }

        $validacion = (new Validacion())
            ->obligatorio('servicio_id', 'El servicio')
            ->obligatorio('resultado', 'El resultado')
            ->obligatorio('fecha_diagnostico', 'La fecha')
            ->fecha('fecha_diagnostico', 'La fecha');

        $servicio = fila(
            "SELECT * FROM servicios WHERE organizacion_id = ? AND id = ? AND resultado = 'PENDIENTE'",
            [$org, entrada_entera('servicio_id') ?? 0],
        );
        if ($servicio === null) {
            $validacion->agregar('servicio_id', 'Ese servicio no está esperando diagnóstico.');
        } elseif (entrada('fecha_diagnostico') < $servicio['fecha']) {
            $validacion->agregar('fecha_diagnostico', 'El diagnóstico no puede ser anterior al servicio.');
        }

        $validacion->o_volver($volver);

        consultar(
            'UPDATE servicios SET resultado = ?, fecha_diagnostico = ? WHERE id = ? AND organizacion_id = ?',
            [entrada('resultado'), entrada('fecha_diagnostico'), (int) $servicio['id'], $org],
        );

        flash(
            'exito',
            entrada('resultado') === 'PRENADA'
                ? 'Preñez confirmada. Parto probable: ' . fecha_corta(fecha_probable_parto($servicio['fecha'])) . '.'
                : 'Diagnóstico registrado: la vaca quedó vacía.',
        );
        redirigir($volver);
    }

    if ($accion === 'parto') {
        // El alta del ternero, su pesaje de nacimiento y el enlace con la madre
        // viven en la operación compartida con la app de terreno.
        try {
            $resultado = op_parto($org, (int) usuario_actual()['id'], $_POST);
        } catch (ErrorOperacion $error) {
            volver_con_errores($error, $volver);
        }
        flash('exito', $resultado['mensaje']);
        redirigir($volver);
    }

    flash('error', 'No se reconoció qué se quería registrar.');
    redirigir($volver);
}

/* --- Gestaciones en curso: servicios preñados que todavía no tienen parto --- */
$gestaciones = filas(
    "SELECT s.id, s.fecha, s.tipo, a.id AS vaca_id, a.caravana_visual, a.numero_interno,
            t.caravana_visual AS toro, p.codigo AS pajuela
       FROM servicios s
       JOIN animales a ON a.id = s.vaca_id
       LEFT JOIN animales t ON t.id = s.toro_id AND t.organizacion_id = s.organizacion_id
       LEFT JOIN pajuelas p ON p.id = s.pajuela_id AND p.organizacion_id = s.organizacion_id
       LEFT JOIN partos pa ON pa.servicio_id = s.id
      WHERE s.organizacion_id = ? AND s.resultado = 'PRENADA' AND pa.id IS NULL" . filtro_predio('a') . "
      ORDER BY s.fecha",
    [$org],
);

foreach ($gestaciones as $i => $gestacion) {
    $gestaciones[$i]['calculo'] = estado_gestacion($gestacion['fecha']);
}
usort(
    $gestaciones,
    static fn (array $a, array $b): int => $a['calculo']['dias_para_parto'] <=> $b['calculo']['dias_para_parto'],
);

$por_parir = array_filter(
    $gestaciones,
    static fn (array $g): bool => in_array($g['calculo']['estado'], ['POR_PARIR', 'EN_FECHA', 'ATRASADA'], true),
);

/* --- Temporada actual: el último servicio de cada vaca --- */
$temporada = fila(
    "SELECT COUNT(*) AS servidas,
            SUM(CASE WHEN resultado = 'PRENADA' THEN 1 ELSE 0 END) AS prenadas,
            SUM(CASE WHEN resultado = 'VACIA' THEN 1 ELSE 0 END) AS vacias,
            SUM(CASE WHEN resultado = 'PENDIENTE' THEN 1 ELSE 0 END) AS pendientes
       FROM servicios s
       JOIN animales a ON a.id = s.vaca_id
      WHERE s.organizacion_id = ?" . filtro_predio('a') . "
        AND s.fecha = (SELECT MAX(s2.fecha) FROM servicios s2 WHERE s2.vaca_id = s.vaca_id)",
    [$org],
);

$nacidos_temporada = (int) valor(
    "SELECT COUNT(*) FROM partos p JOIN animales a ON a.id = p.vaca_id
      WHERE p.organizacion_id = ? AND p.resultado = 'VIVO' AND p.fecha >= ?" . filtro_predio('a'),
    [$org, hace_dias(365)],
);
$destetados_temporada = (int) valor(
    "SELECT COUNT(*) FROM animales a
      WHERE a.organizacion_id = ? AND a.fecha_destete IS NOT NULL AND a.fecha_destete >= ?" . filtro_predio('a'),
    [$org, hace_dias(365)],
);

$indicadores = indicadores_rodeo(
    (int) $temporada['servidas'],
    (int) $temporada['prenadas'],
    $nacidos_temporada,
    $destetados_temporada,
);

/* --- Vacas para descarte: tres años seguidos con abortos o terneros débiles --- */
$para_descarte = vacas_para_descarte($org, $predio);

/* --- Secados: fin de la lactancia, en lechería --- */
$ultimos_secados = filas(
    "SELECT a.id, a.caravana_visual, a.numero_interno, a.fecha_secado, a.raza,
            (SELECT MAX(p.fecha) FROM partos p WHERE p.vaca_id = a.id) AS ultimo_parto
       FROM animales a
      WHERE a.organizacion_id = ? AND a.fecha_secado IS NOT NULL AND a.estado = 'ACTIVO'" . filtro_predio('a') . "
      ORDER BY a.fecha_secado DESC
      LIMIT 15",
    [$org],
);

$ultimos_partos = filas(
    'SELECT p.*, m.caravana_visual AS madre, t.caravana_visual AS ternero_caravana,
            t.numero_interno AS ternero_interno, t.sexo AS ternero_sexo
       FROM partos p
       JOIN animales m ON m.id = p.vaca_id
       LEFT JOIN animales t ON t.id = p.ternero_id AND t.organizacion_id = p.organizacion_id
      WHERE p.organizacion_id = ?' . filtro_predio('m') . '
      ORDER BY p.fecha DESC
      LIMIT 25',
    [$org],
);

abrir_pagina(
    'Reproducción',
    'Qué vacas van a parir, cómo viene la temporada y cuáles conviene descartar.',
    '/reproduccion',
    ['Reproducción'],
);

echo ayuda_pantalla('¿Cómo se lee esta pantalla?', [
    'Servicio o encaste' => 'La monta o inseminación de una vaca. El resultado se confirma con el diagnóstico de preñez, unos 60 días después.',
    'Gestación' => 'Dura unos ' . DIAS_GESTACION . ' días. La fecha probable de parto se calcula desde el servicio, y avisa ' . DIAS_AVISO_PARTO . ' días antes.',
    'Tasa de preñez' => 'De las vacas servidas, cuántas quedaron preñadas.',
    'Tasa de destete' => 'De las vacas servidas, cuántos terneros llegaron al destete. Es el número que termina siendo plata.',
    'Intervalo entre partos' => 'Cuánto tarda una vaca entre un ternero y el siguiente. Lo ideal es un ternero por año (365 días); sobre ' . IEP_ACEPTABLE . ' la vaca come más de lo que produce.',
    'Secado' => 'El día en que se deja de ordeñar a la vaca para que descanse antes del próximo parto. Solo se usa en lechería.',
]);

/* ---------- Formularios de carga ---------- */

$errores = errores_formulario();
$fallo = valor_previo('accion');

$toros_opciones = ['' => 'Elegí el toro'];
foreach (filas(
    "SELECT a.id, a.caravana_visual, a.raza FROM animales a
      WHERE a.organizacion_id = ? AND a.categoria = 'TORO' AND a.estado = 'ACTIVO'" . filtro_predio('a') . "
      ORDER BY a.caravana_visual",
    [$org],
) as $toro) {
    $toros_opciones[(int) $toro['id']] = $toro['caravana_visual'] . ' · ' . ($toro['raza'] ?? '');
}

$pajuelas_opciones = ['' => 'Elegí la pajuela'];
foreach (filas(
    'SELECT id, codigo, toro, dosis_disponibles FROM pajuelas WHERE organizacion_id = ? ORDER BY codigo',
    [$org],
) as $pajuela_opcion) {
    $pajuelas_opciones[(int) $pajuela_opcion['id']] = $pajuela_opcion['codigo'] . ' · ' . $pajuela_opcion['toro']
        . ' (' . (int) $pajuela_opcion['dosis_disponibles'] . ' dosis)';
}

$pendientes_opciones = ['' => 'Elegí el servicio'];
foreach (filas(
    "SELECT s.id, s.fecha, a.caravana_visual, a.numero_interno
       FROM servicios s JOIN animales a ON a.id = s.vaca_id
      WHERE s.organizacion_id = ? AND s.resultado = 'PENDIENTE'" . filtro_predio('a') . "
      ORDER BY s.fecha DESC",
    [$org],
) as $pendiente) {
    $pendientes_opciones[(int) $pendiente['id']] =
        ($pendiente['caravana_visual'] ?? $pendiente['numero_interno']) . ' — servida el ' . fecha_corta($pendiente['fecha']);
}

$gestando_opciones = ['' => 'Elegí la vaca'];
foreach ($gestaciones as $abierta) {
    $gestando_opciones[(int) $abierta['id']] =
        ($abierta['caravana_visual'] ?? $abierta['numero_interno'])
        . ' — parto probable ' . fecha_corta($abierta['calculo']['fecha_probable']);
}

// 1) Servicio: lo registra el veterinario
if (puede('registrar_servicio')) {
    echo abrir_alta('Registrar servicio (encaste)', 'Monta natural o inseminación', $errores !== [] && $fallo === 'servicio');
    echo formulario(url('/reproduccion'), implode('', [
        '<input type="hidden" name="accion" value="servicio">',
        campo('identificacion', 'Vaca', $errores, 'text', '', [
            'obligatorio' => true, 'placeholder' => 'DIIO o número interno',
        ]),
        campo('fecha', 'Fecha del servicio', $errores, 'date', hoy(), ['obligatorio' => true, 'max' => hoy()]),
        campo('tipo', 'Tipo', $errores, 'select', 'MONTA_NATURAL', ['items' => [
            'MONTA_NATURAL' => 'Monta natural',
            'INSEMINACION' => 'Inseminación',
        ]]),
        campo('toro_id', 'Toro (si fue monta)', $errores, 'select', '', ['items' => $toros_opciones]),
        campo('pajuela_id', 'Pajuela (si fue inseminación)', $errores, 'select', '', [
            'items' => $pajuelas_opciones, 'ayuda' => 'Descuenta una dosis del stock',
        ]),
        campo('observaciones', 'Observaciones', $errores, 'text', ''),
    ]), 'Registrar servicio');
    echo cerrar_alta();
}

// 2) Diagnóstico de preñez: también del veterinario
if (puede('registrar_diagnostico')) {
    echo abrir_alta('Registrar diagnóstico de preñez', 'Cierra un servicio pendiente', $errores !== [] && $fallo === 'diagnostico');
    echo formulario(url('/reproduccion'), implode('', [
        '<input type="hidden" name="accion" value="diagnostico">',
        campo('servicio_id', 'Servicio', $errores, 'select', '', [
            'items' => $pendientes_opciones, 'obligatorio' => true,
        ]),
        campo('resultado', 'Resultado', $errores, 'select', 'PRENADA', ['items' => [
            'PRENADA' => 'Preñada',
            'VACIA' => 'Vacía',
        ]]),
        campo('fecha_diagnostico', 'Fecha del diagnóstico', $errores, 'date', hoy(), ['obligatorio' => true, 'max' => hoy()]),
    ]), 'Registrar diagnóstico');
    echo cerrar_alta();
}

// 3) Parto: lo anota quien esté en el potrero, veterinario u operario
if (puede('registrar_parto')) {
    echo abrir_alta('Registrar parto', 'Da de alta al ternero con su madre y su padre', $errores !== [] && $fallo === 'parto');
    echo formulario(url('/reproduccion'), implode('', [
        '<input type="hidden" name="accion" value="parto">',
        campo('servicio_id', 'Vaca que parió', $errores, 'select', '', [
            'items' => $gestando_opciones, 'ayuda' => 'Las que tienen una gestación registrada',
        ]),
        campo('identificacion', 'O la vaca por su identificación', $errores, 'text', '', [
            'placeholder' => 'DIIO, número interno o EID', 'ayuda' => 'Si el servicio no estaba anotado',
        ]),
        campo('fecha', 'Fecha del parto', $errores, 'date', hoy(), ['obligatorio' => true, 'max' => hoy()]),
        campo('resultado', 'Resultado', $errores, 'select', 'VIVO', ['items' => [
            'VIVO' => 'Parto exitoso: ternero vivo',
            'MUERTO' => 'Ternero muerto',
            'ABORTO' => 'Aborto',
        ]]),
        campo('dificultad', 'Dificultad', $errores, 'select', 'NORMAL', ['items' => [
            'NORMAL' => 'Normal',
            'DISTOCIA' => 'Distocia (parto difícil)',
            'CESAREA' => 'Cesárea',
        ]]),
        campo('sexo', 'Sexo del ternero', $errores, 'select', 'HEMBRA', ['items' => [
            'HEMBRA' => 'Hembra',
            'MACHO' => 'Macho',
        ]]),
        campo('peso_nacimiento', 'Peso al nacer', $errores, 'number', '', [
            'step' => '0.5', 'min' => '10', 'max' => '90', 'ayuda' => 'En kilos: queda como hito de nacimiento',
        ]),
        '<div class="campo confirmar-dato"><label for="c_debil">'
            . '<input type="checkbox" id="c_debil" name="ternero_debil" value="1"'
            . (valor_previo('ternero_debil') === '1' ? ' checked' : '') . '>'
            . 'Ternero débil: necesitó asistencia</label></div>',
        campo('observaciones', 'Observaciones', $errores, 'text', ''),
    ]), 'Registrar parto');
    echo cerrar_alta();
}

// 4) Secado: fin de la lactancia
if (puede('registrar_secado')) {
    echo abrir_alta('Registrar secado', 'Fin de la lactancia, en lechería', $errores !== [] && $fallo === 'secado');
    echo formulario(url('/reproduccion'), implode('', [
        '<input type="hidden" name="accion" value="secado">',
        campo('identificacion', 'Vaca', $errores, 'text', '', [
            'obligatorio' => true, 'placeholder' => 'DIIO, número interno o EID',
        ]),
        campo('fecha', 'Fecha de secado', $errores, 'date', hoy(), ['obligatorio' => true, 'max' => hoy()]),
    ]), 'Registrar secado');
    echo cerrar_alta();
}
?>

<section class="indicadores">
  <div class="indicador">
    <div class="rotulo">Vacas gestando</div>
    <div class="cifra"><?= numero(count($gestaciones)) ?></div>
    <div class="nota">de <?= numero((int) $temporada['servidas']) ?> servidas esta temporada</div>
  </div>

  <div class="indicador <?= count($por_parir) > 0 ? 'aviso' : '' ?>">
    <div class="rotulo">Partos próximos</div>
    <div class="cifra"><?= numero(count($por_parir)) ?></div>
    <div class="nota">dentro de <?= DIAS_AVISO_PARTO ?> días o ya en fecha</div>
  </div>

  <div class="indicador <?= ($indicadores['tasa_prenez'] ?? 0) >= 85 ? 'ok' : 'aviso' ?>">
    <div class="rotulo">Tasa de preñez</div>
    <div class="cifra"><?= $indicadores['tasa_prenez'] === null ? '—' : numero($indicadores['tasa_prenez'], 1) ?><small>%</small></div>
    <div class="nota"><?= numero((int) $temporada['vacias']) ?> vacías · <?= numero((int) $temporada['pendientes']) ?> sin diagnosticar</div>
  </div>

  <div class="indicador <?= $para_descarte !== [] ? 'riesgo' : 'ok' ?>">
    <div class="rotulo">Sugeridas para descarte</div>
    <div class="cifra"><?= numero(count($para_descarte)) ?></div>
    <div class="nota">por su historial reproductivo</div>
  </div>
</section>

<?php if ($para_descarte !== []): ?>
<div class="bloque">
  <header>
    <div>
      <h2>Vacas que conviene revisar</h2>
      <div class="sub">La decisión es del productor: acá está en qué se basa la sugerencia</div>
    </div>
    <?= insignia(numero(count($para_descarte)) . ' vacas', 'riesgo') ?>
  </header>
  <div class="tabla-scroll">
    <table data-sin-buscador>
      <thead>
        <tr><th>Vaca</th><th class="num">Edad</th><th class="num">Intervalo entre partos</th><th>Por qué</th></tr>
      </thead>
      <tbody>
      <?php foreach ($para_descarte as $vaca): ?>
        <tr>
          <td>
            <a class="clave principal" href="<?= h(url_con('/animal', ['id' => (int) $vaca['id']])) ?>">
              <?= h($vaca['caravana_visual'] ?? $vaca['numero_interno'] ?? '—') ?>
            </a>
            <div class="secundario"><?= h($vaca['raza'] ?? '') ?></div>
          </td>
          <td class="num">
            <?php $edad = edad_meses($vaca['fecha_nacimiento']); ?>
            <?= $edad === null ? '—' : numero(intdiv($edad, 12)) . ' años' ?>
          </td>
          <td class="num">
            <?= $vaca['evaluacion']['iep'] === null ? '—' : numero($vaca['evaluacion']['iep']) . ' días' ?>
          </td>
          <td>
            <?php foreach ($vaca['evaluacion']['motivos'] as $motivo): ?>
              <div class="secundario" style="color: var(--riesgo)">· <?= h($motivo) ?></div>
            <?php endforeach; ?>
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
      <h2>Próximos partos</h2>
      <div class="sub">Ordenados por fecha probable, calculada desde el servicio</div>
    </div>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead>
        <tr>
          <th>Vaca</th><th>Servicio</th><th>Padre</th>
          <th class="num">Día de gestación</th><th>Parto probable</th><th>Estado</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($gestaciones as $gestacion): ?>
        <?php
        $calculo = $gestacion['calculo'];
        $tono = match ($calculo['estado']) {
            'ATRASADA'  => 'riesgo',
            'EN_FECHA', 'POR_PARIR' => 'aviso',
            default     => 'ok',
        };
        ?>
        <tr>
          <td>
            <a class="clave principal" href="<?= h(url_con('/animal', ['id' => (int) $gestacion['vaca_id']])) ?>">
              <?= h($gestacion['caravana_visual'] ?? $gestacion['numero_interno'] ?? '—') ?>
            </a>
          </td>
          <td>
            <?= fecha_corta($gestacion['fecha']) ?>
            <div class="secundario"><?= h(legible($gestacion['tipo'])) ?></div>
          </td>
          <td>
            <?= h($gestacion['toro'] ?? $gestacion['pajuela'] ?? '—') ?>
            <div class="secundario"><?= $gestacion['pajuela'] !== null ? 'pajuela' : ($gestacion['toro'] !== null ? 'toro del plantel' : '') ?></div>
          </td>
          <td class="num"><?= numero($calculo['dias_gestacion']) ?> / <?= DIAS_GESTACION ?></td>
          <td><?= fecha_corta($calculo['fecha_probable']) ?></td>
          <td>
            <?= insignia(etiqueta_gestacion($calculo['estado']), $tono) ?>
            <?php if ($calculo['dias_para_parto'] > 0 && $calculo['dias_para_parto'] <= DIAS_AVISO_PARTO): ?>
              <div class="secundario">en <?= $calculo['dias_para_parto'] ?> días</div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($gestaciones === []): ?>
        <tr><td colspan="6" class="vacio">No hay gestaciones en curso.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($ultimos_secados !== []): ?>
<div class="bloque">
  <header>
    <div>
      <h2>Últimos secados</h2>
      <div class="sub">Vacas que terminaron su lactancia</div>
    </div>
  </header>
  <div class="tabla-scroll">
    <table data-sin-buscador>
      <thead><tr><th>Vaca</th><th>Secado</th><th>Último parto</th><th class="num">Días de lactancia</th></tr></thead>
      <tbody>
      <?php foreach ($ultimos_secados as $secada): ?>
        <tr>
          <td>
            <a class="clave principal" href="<?= h(url_con('/animal', ['id' => (int) $secada['id']])) ?>">
              <?= h($secada['caravana_visual'] ?? $secada['numero_interno'] ?? '—') ?>
            </a>
            <div class="secundario"><?= h($secada['raza'] ?? '') ?></div>
          </td>
          <td><?= fecha_corta($secada['fecha_secado']) ?></td>
          <td><?= fecha_corta($secada['ultimo_parto']) ?></td>
          <td class="num">
            <?= $secada['ultimo_parto'] === null ? '—' : numero(max(dias_entre($secada['ultimo_parto'], $secada['fecha_secado']), 0)) ?>
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
      <h2>Últimos partos</h2>
      <div class="sub">Se registran también los que salieron mal: son la base de la alerta de descarte</div>
    </div>
    <a class="boton suave" href="<?= h(url_con('/exportar', ['tipo' => 'partos'])) ?>">Filtrar y exportar</a>
    <a class="boton suave" href="<?= h(url_con('/exportar', ['tipo' => 'partos', 'formato' => 'pdf'])) ?>">PDF</a>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead>
        <tr><th>Fecha</th><th>Vaca</th><th>Resultado</th><th>Ternero</th><th class="num">Peso al nacer</th></tr>
      </thead>
      <tbody>
      <?php foreach ($ultimos_partos as $parto): ?>
        <?php
        $tono = match ($parto['resultado']) {
            'VIVO'   => (int) $parto['ternero_debil'] === 1 ? 'aviso' : 'ok',
            'ABORTO' => 'riesgo',
            default  => 'riesgo',
        };
        $texto = match ($parto['resultado']) {
            'VIVO'   => (int) $parto['ternero_debil'] === 1 ? 'Vivo, débil' : 'Vivo',
            'MUERTO' => 'Ternero muerto',
            'ABORTO' => 'Aborto',
            default  => legible($parto['resultado']),
        };
        ?>
        <tr>
          <td><?= fecha_corta($parto['fecha']) ?></td>
          <td class="clave"><?= h($parto['madre'] ?? '—') ?></td>
          <td>
            <?= insignia($texto, $tono) ?>
            <?php if ($parto['dificultad'] !== 'NORMAL'): ?>
              <div class="secundario"><?= h(legible($parto['dificultad'])) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($parto['ternero_id'] !== null): ?>
              <a class="clave" href="<?= h(url_con('/animal', ['id' => (int) $parto['ternero_id']])) ?>">
                <?= h($parto['ternero_caravana'] ?? $parto['ternero_interno'] ?? '—') ?>
              </a>
              <div class="secundario"><?= h(legible($parto['ternero_sexo'] ?? '')) ?></div>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td class="num"><?= $parto['peso_nacimiento_kg'] === null ? '—' : kg((float) $parto['peso_nacimiento_kg']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($ultimos_partos === []): ?>
        <tr><td colspan="5" class="vacio">Todavía no hay partos registrados.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php cerrar_pagina(); ?>
