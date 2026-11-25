<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vistas/layout.php';
require_once __DIR__ . '/../dominio/reproduccion.php';
require_once __DIR__ . '/../dominio/retiros.php';

/**
 * Ficha individual del animal.
 *
 * Es la pantalla que reúne todo lo que se sabe de un animal: de quién es hijo,
 * qué dejó, cómo creció, qué se le aplicó y dónde está. En un sistema de ciclo
 * completo es la vista central, porque el animal vive años y pasa por varias
 * etapas.
 */

exigir_permiso('ver_hacienda');

$org = organizacion_actual();
$id = (int) ($_GET['id'] ?? 0);

$animal = fila(
    'SELECT a.*, c.nombre AS corral, l.codigo AS lote,
            m.caravana_visual AS madre_caravana, m.numero_interno AS madre_interno,
            p.caravana_visual AS padre_caravana,
            pj.codigo AS pajuela_codigo, pj.toro AS pajuela_toro, pj.raza AS pajuela_raza
       FROM animales a
       LEFT JOIN corrales c ON c.id = a.corral_actual_id
       LEFT JOIN lotes l ON l.id = a.lote_actual_id
       LEFT JOIN animales m ON m.id = a.madre_id AND m.organizacion_id = a.organizacion_id
       LEFT JOIN animales p ON p.id = a.padre_id AND p.organizacion_id = a.organizacion_id
       LEFT JOIN pajuelas pj ON pj.id = a.pajuela_id AND pj.organizacion_id = a.organizacion_id
      WHERE a.organizacion_id = ? AND a.id = ?',
    [$org, $id],
);

if ($animal === null) {
    // 404 y no 403: para esta organización, ese animal no existe.
    http_response_code(404);
    abrir_pagina('Animal no encontrado', '', '/hacienda', ['Hacienda', 'Ficha']);
    echo '<div class="bloque"><div class="interior" style="padding: var(--e5); text-align: center;">'
        . '<p>No existe ese animal en esta organización.</p>'
        . '<a class="boton" href="' . h(url('/hacienda')) . '">Volver a Hacienda</a></div></div>';
    cerrar_pagina();
    return;
}

$volver_ficha = url_con('/animal', ['id' => $id]);

/* Nota en texto libre: lo que el productor anotaría en la libreta. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion_ficha = entrada('accion');
    $usuario_ficha = (int) usuario_actual()['id'];

    if ($accion_ficha === 'anular') {
        exigir_post_valido('anular_registro', $volver_ficha);
        try {
            $resultado = op_anular($org, $usuario_ficha, $_POST);
        } catch (ErrorOperacion $error) {
            volver_con_errores($error, $volver_ficha);
        }
        flash('exito', $resultado['mensaje']);
        redirigir($volver_ficha);
    }

    if ($accion_ficha === 'editar') {
        exigir_post_valido('editar_animal', $volver_ficha);
        try {
            // El animal es el de la URL, no el que venga en el POST: al revés,
            // un formulario armado a mano editaría otra ficha de la organización.
            $resultado = op_editar_animal($org, $usuario_ficha, ['animal_id' => $id] + $_POST);
        } catch (ErrorOperacion $error) {
            volver_con_errores($error, $volver_ficha);
        }
        flash('exito', $resultado['mensaje']);
        redirigir($volver_ficha);
    }

    exigir_post_valido('registrar_nota', $volver_ficha);
    try {
        $resultado = op_nota($org, $usuario_ficha, ['animal_id' => $id] + $_POST);
    } catch (ErrorOperacion $error) {
        volver_con_errores($error, $volver_ficha);
    }
    flash('exito', $resultado['mensaje']);
    redirigir($volver_ficha);
}

$nombre = $animal['caravana_visual'] ?? $animal['numero_interno'] ?? ('#' . $id);
$es_hembra_reproductora = $animal['sexo'] === 'HEMBRA' && $animal['etapa'] === 'REPRODUCCION';

/* Abuelos: los padres de la madre y los del padre, para el árbol genealógico. */
$abuelos = [];
foreach (['madre' => $animal['madre_id'], 'padre' => $animal['padre_id']] as $lado => $progenitor_id) {
    $abuelos[$lado] = $progenitor_id === null ? null : fila(
        'SELECT m.id AS madre_id, m.caravana_visual AS madre_caravana, m.numero_interno AS madre_interno,
                p.id AS padre_id, p.caravana_visual AS padre_caravana, pj.toro AS pajuela_toro
           FROM animales a
           LEFT JOIN animales m ON m.id = a.madre_id AND m.organizacion_id = a.organizacion_id
           LEFT JOIN animales p ON p.id = a.padre_id AND p.organizacion_id = a.organizacion_id
           LEFT JOIN pajuelas pj ON pj.id = a.pajuela_id AND pj.organizacion_id = a.organizacion_id
          WHERE a.organizacion_id = ? AND a.id = ?',
        [$org, (int) $progenitor_id],
    );
}

$movimientos = filas(
    'SELECT m.fecha, m.motivo, co.nombre AS origen, cd.nombre AS destino, u.nombre AS responsable
       FROM detalles_movimiento d
       JOIN movimientos_hacienda m ON m.id = d.movimiento_id
       LEFT JOIN corrales co ON co.id = m.corral_origen_id
       LEFT JOIN corrales cd ON cd.id = m.corral_destino_id
       LEFT JOIN usuarios u ON u.id = m.responsable_id
      WHERE d.organizacion_id = ? AND d.animal_id = ?
      ORDER BY m.fecha DESC, m.id DESC',
    [$org, $id],
);

$notas = filas(
    'SELECT n.id, n.fecha, n.texto, u.nombre AS autor, u.apellido AS autor_apellido
       FROM notas_animal n
       LEFT JOIN usuarios u ON u.id = n.usuario_id
      WHERE n.organizacion_id = ? AND n.animal_id = ? AND n.anulada = 0
      ORDER BY n.fecha DESC, n.id DESC',
    [$org, $id],
);

$alimentacion_individual = filas(
    'SELECT e.fecha, e.kg_entregados, e.kg_materia_seca, e.costo_total, r.nombre AS racion
       FROM entregas_alimento e JOIN raciones r ON r.id = e.racion_id
      WHERE e.organizacion_id = ? AND e.animal_id = ? AND e.anulada = 0
      ORDER BY e.fecha DESC, e.id DESC
      LIMIT 20',
    [$org, $id],
);

$pesadas = filas(
    'SELECT id, fecha, peso_kg, motivo FROM pesadas
      WHERE organizacion_id = ? AND animal_id = ? AND anulada = 0 ORDER BY fecha',
    [$org, $id],
);

$hijos = filas(
    'SELECT id, caravana_visual, numero_interno, sexo, categoria, etapa, fecha_nacimiento, estado
       FROM animales
      WHERE organizacion_id = ? AND (madre_id = ? OR padre_id = ?)
      ORDER BY fecha_nacimiento DESC',
    [$org, $id, $id],
);

$servicios = $es_hembra_reproductora ? filas(
    'SELECT s.*, t.caravana_visual AS toro, pj.codigo AS pajuela
       FROM servicios s
       LEFT JOIN animales t ON t.id = s.toro_id AND t.organizacion_id = s.organizacion_id
       LEFT JOIN pajuelas pj ON pj.id = s.pajuela_id AND pj.organizacion_id = s.organizacion_id
      WHERE s.organizacion_id = ? AND s.vaca_id = ?
      ORDER BY s.fecha DESC',
    [$org, $id],
) : [];

$partos = $es_hembra_reproductora ? filas(
    'SELECT p.*, t.caravana_visual AS ternero_caravana, t.numero_interno AS ternero_interno
       FROM partos p
       LEFT JOIN animales t ON t.id = p.ternero_id AND t.organizacion_id = p.organizacion_id
      WHERE p.organizacion_id = ? AND p.vaca_id = ?
      ORDER BY p.fecha DESC',
    [$org, $id],
) : [];

$tratamientos = filas(
    'SELECT t.fecha, t.tipo, t.dias_carencia_carne, t.diagnostico, i.nombre AS producto
       FROM aplicaciones_tratamiento ap
       JOIN tratamientos t ON t.id = ap.tratamiento_id
       JOIN insumos i ON i.id = t.insumo_id
      WHERE ap.organizacion_id = ? AND ap.animal_id = ? AND t.anulada = 0
      ORDER BY t.fecha DESC',
    [$org, $id],
);

/* Retiro efectivo: manda el tratamiento más restrictivo. */
$retiro = null;
foreach ($tratamientos as $tratamiento) {
    $estado = estado_retiro($tratamiento['fecha'], (int) $tratamiento['dias_carencia_carne']);
    if ($retiro === null || $estado['fecha_habilitacion'] > $retiro['fecha_habilitacion']) {
        $retiro = $estado;
    }
}

/* Crecimiento: entre el primer y el último peso conocido. */
$peso_inicial = $animal['peso_ingreso_kg'] !== null ? (float) $animal['peso_ingreso_kg'] : null;
$fecha_inicial = $animal['fecha_nacimiento'] ?? $animal['fecha_ingreso'];
$adg = ($animal['peso_actual_kg'] !== null && $peso_inicial !== null)
    ? calcular_adg($peso_inicial, (float) $animal['peso_actual_kg'], dias_entre($fecha_inicial, $animal['fecha_ultima_pesada'] ?? hoy()))
    : null;

$peso_por_motivo = [];
foreach ($pesadas as $pesada) {
    $peso_por_motivo[$pesada['motivo']] = $pesada;
}
$adg_predestete = null;
if (isset($peso_por_motivo['NACIMIENTO'], $peso_por_motivo['DESTETE'])) {
    $adg_predestete = ganancia_predestete(
        (float) $peso_por_motivo['NACIMIENTO']['peso_kg'],
        (float) $peso_por_motivo['DESTETE']['peso_kg'],
        dias_entre($peso_por_motivo['NACIMIENTO']['fecha'], $peso_por_motivo['DESTETE']['fecha']),
    );
}

$edad = edad_meses($animal['fecha_nacimiento']);
$descarte = $es_hembra_reproductora
    ? evaluar_descarte(
        array_map(static fn (array $p): array => [
            'fecha'         => $p['fecha'],
            'resultado'     => $p['resultado'],
            'dificultad'    => $p['dificultad'],
            'ternero_debil' => (int) $p['ternero_debil'] === 1,
        ], $partos),
        array_map(static fn (array $s): array => ['fecha' => $s['fecha'], 'resultado' => $s['resultado']], $servicios),
    )
    : null;

$corrales_ficha = ['' => 'Sin corral'];
foreach (filas(
    'SELECT c.id, c.nombre FROM corrales c WHERE c.organizacion_id = ?' . filtro_predio('c') . ' ORDER BY c.nombre',
    [$org],
) as $corral_opcion) {
    $corrales_ficha[(int) $corral_opcion['id']] = $corral_opcion['nombre'];
}

/* ============================================================
   La ficha en PDF
   ============================================================

   Se arma con los mismos datos que la pantalla, ya consultados arriba. Sirve
   para archivar, para llevar al campo y para entregarla cuando la piden por un
   animal en particular. */
if (($_GET['formato'] ?? '') === 'pdf') {
    require_once __DIR__ . '/../pdf.php';

    /** Una tabla del PDF: las filas ya formateadas para leer. */
    $tabla_pdf = static fn (array $registros, callable $fila): array => array_map($fila, $registros);

    $kg = static fn (mixed $valor): string => $valor === null ? '' : numero((float) $valor, 1) . ' kg';

    $bloques = [
        ['tipo' => 'datos', 'titulo' => 'Identificación', 'pares' => [
            ['DIIO (caravana)', $animal['caravana_visual'] ?? ''],
            ['Número interno', $animal['numero_interno'] ?? ''],
            ['EID (chip)', $animal['eid'] ?? ''],
            ['Sexo', legible($animal['sexo'])],
            ['Categoría', legible($animal['categoria'])],
            ['Raza', $animal['raza'] ?? ''],
            ['Etapa', legible($animal['etapa'])],
            ['Origen', legible($animal['origen'])],
            ['Estado', legible($animal['estado'])],
        ]],
        ['tipo' => 'datos', 'titulo' => 'Fechas y ubicación', 'pares' => array_merge([
            ['Nacimiento', fecha_corta($animal['fecha_nacimiento'])],
            ['Edad', $edad !== null ? numero($edad) . ' meses' : ''],
            ['Ingreso al predio', fecha_corta($animal['fecha_ingreso'])],
            ['Destete', fecha_corta($animal['fecha_destete'])],
            ['Secado', fecha_corta($animal['fecha_secado'] ?? null)],
            ['Corral', $animal['corral'] ?? ''],
        ], GESTION_AVANZADA ? [['Lote', $animal['lote'] ?? '']] : [])],
        ['tipo' => 'datos', 'titulo' => 'Pesos y crecimiento', 'pares' => [
            ['Peso de ingreso', $kg($animal['peso_ingreso_kg'])],
            ['Peso actual', $kg($animal['peso_actual_kg'])],
            ['Última pesada', fecha_corta($animal['fecha_ultima_pesada'])],
            ['Ganancia diaria', $adg !== null ? decimal_es($adg, 2) . ' kg/día' : ''],
            ['Ganancia predestete', $adg_predestete !== null ? decimal_es($adg_predestete, 2) . ' kg/día' : ''],
            ['Pesajes registrados', numero(count($pesadas))],
        ]],
        ['tipo' => 'datos', 'titulo' => 'Genealogía', 'pares' => [
            ['Madre', $animal['madre_caravana'] ?? $animal['madre_interno'] ?? ''],
            ['Padre', $animal['padre_caravana'] ?? $animal['pajuela_toro'] ?? ''],
            ['Pajuela', $animal['pajuela_codigo'] ?? ''],
        ]],
        ['tipo' => 'tabla', 'titulo' => 'Pesajes', 'vacio' => 'Sin pesajes registrados.',
         'encabezados' => ['Fecha', 'Peso (kg)', 'Motivo'],
         'filas' => $tabla_pdf($pesadas, static fn (array $p): array => [
             fecha_corta($p['fecha']), numero((float) $p['peso_kg'], 1), legible($p['motivo']),
         ])],
    ];

    if ($es_hembra_reproductora) {
        $bloques[] = ['tipo' => 'tabla', 'titulo' => 'Servicios', 'vacio' => 'Sin servicios registrados.',
            'encabezados' => ['Fecha', 'Tipo', 'Toro o pajuela', 'Resultado'],
            'filas' => $tabla_pdf($servicios, static fn (array $s): array => [
                fecha_corta($s['fecha']), legible($s['tipo']),
                $s['toro'] ?? $s['pajuela'] ?? '', legible($s['resultado'] ?? null),
            ])];
        $bloques[] = ['tipo' => 'tabla', 'titulo' => 'Partos', 'vacio' => 'Sin partos registrados.',
            'encabezados' => ['Fecha', 'Resultado', 'Dificultad', 'Ternero', 'Peso al nacer', 'Débil'],
            'filas' => $tabla_pdf($partos, static fn (array $p): array => [
                fecha_corta($p['fecha']), legible($p['resultado']), legible($p['dificultad']),
                $p['ternero_caravana'] ?? $p['ternero_interno'] ?? '',
                $p['peso_nacimiento_kg'] !== null ? numero((float) $p['peso_nacimiento_kg'], 1) : '',
                (int) $p['ternero_debil'] === 1 ? 'Sí' : 'No',
            ])];
        if ($descarte !== null && $descarte['motivos'] !== []) {
            $bloques[] = ['tipo' => 'notas', 'titulo' => 'Alerta de descarte',
                'vacio' => '', 'items' => array_map(static fn (string $motivo): array => [
                    'encabezado' => 'Motivo', 'texto' => $motivo,
                ], $descarte['motivos'])];
        }
    }

    if ($hijos !== []) {
        $bloques[] = ['tipo' => 'tabla', 'titulo' => 'Descendencia', 'vacio' => 'Sin crías registradas.',
            'encabezados' => ['Animal', 'Sexo', 'Categoría', 'Nacimiento', 'Estado'],
            'filas' => $tabla_pdf($hijos, static fn (array $h): array => [
                $h['caravana_visual'] ?? $h['numero_interno'] ?? '', legible($h['sexo']),
                legible($h['categoria']), fecha_corta($h['fecha_nacimiento']), legible($h['estado']),
            ])];
    }

    if (GESTION_AVANZADA) {
        $bloques[] = ['tipo' => 'tabla', 'titulo' => 'Sanidad',
            'detalle' => $retiro !== null && !$retiro['apto']
                ? 'No apto para faena hasta el ' . fecha_corta($retiro['fecha_habilitacion'])
                : '',
            'vacio' => 'Sin tratamientos registrados.',
            'encabezados' => ['Fecha', 'Producto', 'Tipo', 'Diagnóstico', 'Carencia (días)'],
            'filas' => $tabla_pdf($tratamientos, static fn (array $tr): array => [
                fecha_corta($tr['fecha']), $tr['producto'], legible($tr['tipo']),
                $tr['diagnostico'] ?? '', numero((int) $tr['dias_carencia_carne']),
            ])];
    }

    if ($alimentacion_individual !== []) {
        $bloques[] = ['tipo' => 'tabla', 'titulo' => 'Alimentación individual',
            'detalle' => 'Últimas 20 entregas', 'vacio' => 'Sin entregas individuales.',
            'encabezados' => array_merge(
                ['Fecha', 'Ración', 'Entregado (kg)', 'Materia seca (kg)'],
                GESTION_AVANZADA ? ['Costo'] : [],
            ),
            'filas' => $tabla_pdf($alimentacion_individual, static fn (array $e): array => array_merge([
                fecha_corta($e['fecha']), $e['racion'],
                numero((float) $e['kg_entregados'], 1), numero((float) $e['kg_materia_seca'], 1),
            ], GESTION_AVANZADA ? [pesos((float) $e['costo_total'])] : []))];
    }

    $bloques[] = ['tipo' => 'tabla', 'titulo' => 'Movimientos', 'vacio' => 'Sin traslados registrados.',
        'encabezados' => ['Fecha', 'Desde', 'Hacia', 'Motivo', 'Responsable'],
        'filas' => $tabla_pdf($movimientos, static fn (array $m): array => [
            fecha_corta($m['fecha']), $m['origen'] ?? '', $m['destino'] ?? '',
            legible($m['motivo']), $m['responsable'] ?? '',
        ])];

    $bloques[] = ['tipo' => 'notas', 'titulo' => 'Notas', 'vacio' => 'Sin notas escritas.',
        'items' => array_map(static fn (array $n): array => [
            'encabezado' => fecha_corta($n['fecha'])
                . ($n['autor'] !== null ? ' · ' . $n['autor'] . ' ' . ($n['autor_apellido'] ?? '') : ''),
            'texto' => $n['texto'],
        ], $notas)];

    registrar_bitacora($org, (int) usuario_actual()['id'], 'EXPORTA', 'animales', $id, 'Ficha en PDF');

    pdf_descargar(
        pdf_ficha([
            'titulo'    => 'Ficha de ' . $nombre,
            'subtitulo' => trim(legible($animal['categoria']) . ' · ' . ($animal['raza'] ?? ''), ' ·')
                           . ' · ' . usuario_actual()['organizacion'] . ' · ' . nombre_predio(),
            'pie'       => 'Gestión ganadera · Ficha individual',
            'bloques'   => $bloques,
        ]),
        'ficha-' . preg_replace('/[^A-Za-z0-9_-]+/', '', $nombre) . '-' . hoy() . '.pdf',
    );
}

abrir_pagina(
    $nombre,
    trim(legible($animal['categoria']) . ' · ' . legible($animal['raza'] ?? '')),
    '/hacienda',
    ['Hacienda', $nombre],
);

/* ---------- Llevarse la ficha ---------- */

echo '<div class="barra-acciones">'
    . '<a class="boton suave" href="' . h(url_con('/animal', ['id' => $id, 'formato' => 'pdf'])) . '">'
    . 'Descargar la ficha en PDF</a></div>';

/* ---------- Corregir la ficha ---------- */

if (puede('editar_animal')) {
    $errores_ficha = errores_formulario();

    echo abrir_alta(
        'Corregir la ficha',
        'Para arreglar un dato mal cargado: queda en la bitácora',
        $errores_ficha !== [],
    );
    echo formulario($volver_ficha, implode('', [
        '<input type="hidden" name="accion" value="editar">',
        campo('caravana_visual', 'DIIO (caravana)', $errores_ficha, 'text', (string) ($animal['caravana_visual'] ?? '')),
        campo('numero_interno', 'Número interno', $errores_ficha, 'text', (string) ($animal['numero_interno'] ?? '')),
        campo('eid', 'EID (chip)', $errores_ficha, 'text', (string) ($animal['eid'] ?? ''), ['maxlength' => 18]),
        campo('sexo', 'Sexo', $errores_ficha, 'select', $animal['sexo'], ['items' => [
            'HEMBRA' => 'Hembra', 'MACHO' => 'Macho', 'MACHO_CASTRADO' => 'Macho castrado',
        ]]),
        campo('categoria', 'Categoría', $errores_ficha, 'select', $animal['categoria'], ['items' => [
            'TERNERO' => 'Ternero', 'TERNERA' => 'Ternera', 'NOVILLO' => 'Novillo',
            'VAQUILLA' => 'Vaquilla', 'VACA' => 'Vaca', 'TORO' => 'Toro', 'BUEY' => 'Buey',
        ]]),
        campo('raza', 'Raza', $errores_ficha, 'text', (string) ($animal['raza'] ?? '')),
        campo('etapa', 'Etapa', $errores_ficha, 'select', $animal['etapa'], ['items' => [
            'CRIA' => 'Cría', 'RECRIA' => 'Recría', 'ENGORDA' => 'Engorda', 'REPRODUCCION' => 'Reproducción',
        ]]),
        campo('origen', 'Origen', $errores_ficha, 'select', $animal['origen'], ['items' => [
            'COMPRADO' => 'Comprado', 'NACIDO_EN_PREDIO' => 'Nacido en el predio',
        ]]),
        campo('fecha_nacimiento', 'Nacimiento', $errores_ficha, 'date', (string) ($animal['fecha_nacimiento'] ?? ''), ['max' => hoy()]),
        campo('fecha_destete', 'Destete', $errores_ficha, 'date', (string) ($animal['fecha_destete'] ?? ''), ['max' => hoy()]),
        campo('fecha_secado', 'Secado', $errores_ficha, 'date', (string) ($animal['fecha_secado'] ?? ''), ['max' => hoy()]),
        campo('corral_actual_id', 'Corral', $errores_ficha, 'select', (string) ($animal['corral_actual_id'] ?? ''), [
            'items' => $corrales_ficha,
        ]),
    ]), 'Guardar corrección');
    echo cerrar_alta();
}
?>

<section class="indicadores">
  <div class="indicador">
    <div class="rotulo">Peso actual</div>
    <div class="cifra"><?= $animal['peso_actual_kg'] === null ? '—' : numero((float) $animal['peso_actual_kg']) ?><small> kg</small></div>
    <div class="nota">
      <?= $animal['fecha_ultima_pesada'] !== null ? 'medido el ' . fecha_corta($animal['fecha_ultima_pesada']) : 'sin pesadas' ?>
    </div>
  </div>

  <?php if ($es_hembra_reproductora): ?>
    <?php
    /* En una vaca adulta, la ganancia diaria desde el nacimiento no dice nada:
       lo que importa es cuántos terneros dejó y cada cuánto. */
    $vivos = array_filter($partos, static fn (array $p): bool => $p['resultado'] === 'VIVO');
    $iep_vaca = intervalo_entre_partos(array_column($vivos, 'fecha'));
    ?>
    <div class="indicador <?= $iep_vaca !== null && $iep_vaca > IEP_ACEPTABLE ? 'aviso' : 'ok' ?>">
      <div class="rotulo">Terneros dejados</div>
      <div class="cifra"><?= numero(count($vivos)) ?></div>
      <div class="nota">
        <?= $iep_vaca === null ? 'sin intervalo calculable todavía' : numero($iep_vaca) . ' días entre partos' ?>
      </div>
    </div>
  <?php else: ?>
    <div class="indicador ok">
      <div class="rotulo">Ganancia diaria</div>
      <div class="cifra"><?= $adg === null ? '—' : numero($adg, 2) ?><small> kg</small></div>
      <div class="nota">desde <?= $animal['fecha_nacimiento'] !== null ? 'que nació' : 'que ingresó' ?></div>
    </div>
  <?php endif; ?>

  <div class="indicador">
    <div class="rotulo">Edad</div>
    <div class="cifra"><?= $edad === null ? '—' : numero($edad) ?><small> meses</small></div>
    <div class="nota"><?= h(legible($animal['etapa'])) ?> · <?= h(legible($animal['origen'])) ?></div>
  </div>

  <?php if (GESTION_AVANZADA): ?>
  <div class="indicador <?= $retiro !== null && !$retiro['apto'] ? 'riesgo' : 'ok' ?>">
    <div class="rotulo">Faena</div>
    <div class="cifra" style="font-size: 22px">
      <?= $retiro === null || $retiro['apto'] ? 'Apto' : 'Faltan ' . $retiro['dias_restantes'] . ' d' ?>
    </div>
    <div class="nota">
      <?= $retiro === null || $retiro['apto'] ? 'sin carencia pendiente' : 'se habilita el ' . fecha_corta($retiro['fecha_habilitacion']) ?>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if ($descarte !== null && $descarte['descartar']): ?>
  <div class="mensaje error">
    <span class="marca-aviso">!</span>
    <div>
      <strong>El historial sugiere evaluar el descarte de esta vaca.</strong>
      <?php foreach ($descarte['motivos'] as $motivo): ?>
        <div>· <?= h($motivo) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="par">
  <div class="bloque">
    <header><div><h2>Identificación</h2><div class="sub">Oficial e interna</div></div></header>
    <div class="tabla-scroll">
      <table data-sin-orden data-sin-buscador>
        <thead><tr><th>Dato</th><th class="num">Valor</th></tr></thead>
        <tbody>
          <tr><td>DIIO (caravana)</td><td class="num clave"><?= h($animal['caravana_visual'] ?? '— sin DIIO —') ?></td></tr>
          <tr><td>EID (chip)</td><td class="num clave">
            <?= $animal['eid'] !== null ? h(substr($animal['eid'], 0, 3) . ' ' . substr($animal['eid'], 3)) : '—' ?>
          </td></tr>
          <tr><td>Número interno</td><td class="num clave"><?= h($animal['numero_interno'] ?? '—') ?></td></tr>
          <tr><td>Sexo / categoría</td><td class="num"><?= h(legible($animal['sexo'])) ?> · <?= h(legible($animal['categoria'])) ?></td></tr>
          <tr><td>Nacimiento</td><td class="num"><?= fecha_corta($animal['fecha_nacimiento']) ?></td></tr>
          <tr><td>Destete</td><td class="num"><?= fecha_corta($animal['fecha_destete']) ?></td></tr>
          <?php if ($animal['fecha_secado'] !== null || $animal['sexo'] === 'HEMBRA'): ?>
            <tr><td>Secado</td><td class="num"><?= fecha_corta($animal['fecha_secado']) ?></td></tr>
          <?php endif; ?>
          <tr><td>Ubicación</td><td class="num">
            <?= h($animal['corral'] ?? 'Sin corral') ?><?= $animal['lote'] !== null ? ' · ' . h($animal['lote']) : '' ?>
          </td></tr>
          <tr><td>Estado</td><td class="num"><?= insignia(legible($animal['estado']), $animal['estado'] === 'ACTIVO' ? 'ok' : 'neutro') ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="bloque">
    <header><div><h2>Genealogía</h2><div class="sub">De quién viene y qué dejó</div></div></header>
    <?php
    /* Árbol genealógico en tres generaciones: el animal, sus padres y sus
       cuatro abuelos. Cada casillero lleva a la ficha de ese animal. */
    $nodo = static function (string $parentesco, ?int $id_nodo, ?string $texto, string $detalle = '', string $clase = ''): string {
        if ($id_nodo === null && ($texto === null || $texto === '')) {
            return '<div class="nodo vacio"><span class="parentesco">' . h($parentesco) . '</span>'
                . h($detalle !== '' ? $detalle : 'Sin registro') . '</div>';
        }
        $nombre_nodo = $id_nodo !== null
            ? '<a class="clave principal" href="' . h(url_con('/animal', ['id' => $id_nodo])) . '">' . h($texto ?? ('#' . $id_nodo)) . '</a>'
            : '<span class="principal">' . h((string) $texto) . '</span>';
        return '<div class="nodo ' . $clase . '"><span class="parentesco">' . h($parentesco) . '</span>' . $nombre_nodo
            . ($detalle !== '' ? '<div class="secundario">' . h($detalle) . '</div>' : '') . '</div>';
    };

    // No es lo mismo un animal comprado, que no tiene padres en el predio, que
    // uno nacido acá antes de que se llevara registro.
    $sin_registro = $animal['origen'] === 'NACIDO_EN_PREDIO' ? 'Sin registrar' : 'Comprado: sin datos';

    $nodo_madre = $animal['madre_id'] !== null
        ? $nodo('Madre', (int) $animal['madre_id'], $animal['madre_caravana'] ?? $animal['madre_interno'])
        : $nodo('Madre', null, null, $sin_registro);

    if ($animal['padre_id'] !== null) {
        $nodo_padre = $nodo('Padre', (int) $animal['padre_id'], $animal['padre_caravana'], 'Toro del plantel · monta');
    } elseif ($animal['pajuela_id'] !== null) {
        $nodo_padre = $nodo('Padre', null, $animal['pajuela_toro'], 'Pajuela ' . $animal['pajuela_codigo'] . ' · inseminación');
    } else {
        $nodo_padre = $nodo('Padre', null, null, $sin_registro);
    }

    $abuelos_de = static function (?array $progenitor, string $lado) use ($nodo): string {
        if ($progenitor === null) {
            return $nodo('Abuela ' . $lado, null, null) . $nodo('Abuelo ' . $lado, null, null);
        }
        $abuela = $progenitor['madre_id'] !== null
            ? $nodo('Abuela ' . $lado, (int) $progenitor['madre_id'], $progenitor['madre_caravana'] ?? $progenitor['madre_interno'])
            : $nodo('Abuela ' . $lado, null, null);
        if ($progenitor['padre_id'] !== null) {
            $abuelo = $nodo('Abuelo ' . $lado, (int) $progenitor['padre_id'], $progenitor['padre_caravana']);
        } elseif ($progenitor['pajuela_toro'] !== null) {
            $abuelo = $nodo('Abuelo ' . $lado, null, $progenitor['pajuela_toro'], 'pajuela');
        } else {
            $abuelo = $nodo('Abuelo ' . $lado, null, null);
        }
        return $abuela . $abuelo;
    };
    ?>
    <div class="interior">
      <div class="arbol" role="group" aria-label="Árbol genealógico">
        <div class="generacion">
          <?= $nodo('Este animal', null, $nombre, legible($animal['sexo']) . ($animal['fecha_nacimiento'] !== null ? ' · ' . fecha_corta($animal['fecha_nacimiento']) : ''), 'propio') ?>
        </div>
        <div class="generacion padres"><?= $nodo_madre . $nodo_padre ?></div>
        <div class="generacion abuelos">
          <?= $abuelos_de($abuelos['madre'], 'materna') ?>
          <?= $abuelos_de($abuelos['padre'], 'paterna') ?>
        </div>
      </div>
    </div>

    <?php if ($hijos !== []): ?>
      <div class="tabla-scroll">
        <table data-sin-buscador>
          <thead><tr><th>Descendencia (<?= numero(count($hijos)) ?>)</th><th>Nacimiento</th><th>Etapa</th></tr></thead>
          <tbody>
          <?php foreach ($hijos as $hijo): ?>
            <tr>
              <td>
                <a class="clave principal" href="<?= h(url_con('/animal', ['id' => (int) $hijo['id']])) ?>">
                  <?= h($hijo['caravana_visual'] ?? $hijo['numero_interno'] ?? '—') ?>
                </a>
                <div class="secundario"><?= h(legible($hijo['categoria'])) ?></div>
              </td>
              <td><?= fecha_corta($hijo['fecha_nacimiento']) ?></td>
              <td><?= insignia(legible($hijo['etapa']), $hijo['estado'] === 'ACTIVO' ? 'neutro' : 'aviso', false) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="bloque">
  <header>
    <div>
      <h2>Control de pesos</h2>
      <div class="sub">
        <?php if ($adg_predestete !== null): ?>
          Ganancia predestete: <?= numero($adg_predestete, 2) ?> kg/día
        <?php else: ?>
          Hitos y controles registrados
        <?php endif; ?>
      </div>
    </div>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead><tr><th>Fecha</th><th>Hito</th><th class="num">Peso</th><th class="num">Ganancia desde el anterior</th><?php if (puede('anular_registro')): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php $anterior = null; ?>
      <?php foreach ($pesadas as $pesada): ?>
        <?php
        $ganancia = $anterior !== null
            ? calcular_adg((float) $anterior['peso_kg'], (float) $pesada['peso_kg'], dias_entre($anterior['fecha'], $pesada['fecha']))
            : null;
        $alerta_peso = $anterior !== null
            ? evaluar_variacion_peso((float) $anterior['peso_kg'], $anterior['fecha'], (float) $pesada['peso_kg'], $pesada['fecha'])
            : null;
        ?>
        <tr>
          <td><?= fecha_corta($pesada['fecha']) ?></td>
          <td><?= insignia(legible($pesada['motivo']), 'neutro', false) ?></td>
          <td class="num principal"><?= numero((float) $pesada['peso_kg']) ?> kg</td>
          <td class="num">
            <?= $ganancia === null ? '—' : numero($ganancia, 2) . ' kg/día' ?>
            <?php if ($alerta_peso !== null): ?>
              <div><?= insignia($alerta_peso['tipo'] === 'NEGATIVA' ? 'Bajó de peso' : 'Revisar dato', 'riesgo') ?></div>
            <?php endif; ?>
          </td>
          <?php if (puede('anular_registro')): ?>
            <td class="num"><?= boton_anular($volver_ficha, 'PESADA', (int) $pesada['id']) ?></td>
          <?php endif; ?>
        </tr>
        <?php $anterior = $pesada; ?>
      <?php endforeach; ?>
      <?php if ($pesadas === []): ?>
        <tr><td colspan="<?= puede('anular_registro') ? 5 : 4 ?>" class="vacio">Sin pesadas registradas.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($es_hembra_reproductora): ?>
<div class="par">
  <div class="bloque">
    <header>
      <div>
        <h2>Servicios</h2>
        <div class="sub">Encastes y su diagnóstico</div>
      </div>
    </header>
    <div class="tabla-scroll">
      <table data-sin-buscador>
        <thead><tr><th>Fecha</th><th>Tipo</th><th>Padre</th><th>Resultado</th></tr></thead>
        <tbody>
        <?php foreach ($servicios as $servicio): ?>
          <tr>
            <td><?= fecha_corta($servicio['fecha']) ?></td>
            <td><?= h(legible($servicio['tipo'])) ?></td>
            <td class="clave"><?= h($servicio['toro'] ?? $servicio['pajuela'] ?? '—') ?></td>
            <td>
              <?= insignia(
                  legible($servicio['resultado']),
                  match ($servicio['resultado']) { 'PRENADA' => 'ok', 'VACIA' => 'riesgo', default => 'aviso' },
              ) ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($servicios === []): ?>
          <tr><td colspan="4" class="vacio">Sin servicios registrados.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="bloque">
    <header>
      <div>
        <h2>Partos</h2>
        <div class="sub">
          <?php $iep = intervalo_entre_partos(array_column(array_filter($partos, static fn (array $p): bool => $p['resultado'] === 'VIVO'), 'fecha')); ?>
          <?= $iep === null ? 'Historial de pariciones' : 'Intervalo entre partos: ' . numero($iep) . ' días' ?>
        </div>
      </div>
    </header>
    <div class="tabla-scroll">
      <table data-sin-buscador>
        <thead><tr><th>Fecha</th><th>Resultado</th><th>Ternero</th><th class="num">Peso</th></tr></thead>
        <tbody>
        <?php foreach ($partos as $parto): ?>
          <tr>
            <td><?= fecha_corta($parto['fecha']) ?></td>
            <td>
              <?= insignia(
                  $parto['resultado'] === 'VIVO'
                      ? ((int) $parto['ternero_debil'] === 1 ? 'Vivo, débil' : 'Vivo')
                      : legible($parto['resultado']),
                  $parto['resultado'] === 'VIVO'
                      ? ((int) $parto['ternero_debil'] === 1 ? 'aviso' : 'ok')
                      : 'riesgo',
              ) ?>
              <?php if ($parto['dificultad'] !== 'NORMAL'): ?>
                <div class="secundario"><?= h(legible($parto['dificultad'])) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($parto['ternero_id'] !== null): ?>
                <a class="clave" href="<?= h(url_con('/animal', ['id' => (int) $parto['ternero_id']])) ?>">
                  <?= h($parto['ternero_caravana'] ?? $parto['ternero_interno'] ?? '—') ?>
                </a>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td class="num"><?= $parto['peso_nacimiento_kg'] === null ? '—' : numero((float) $parto['peso_nacimiento_kg']) . ' kg' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($partos === []): ?>
          <tr><td colspan="4" class="vacio">Sin partos registrados.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="bloque">
  <header>
    <div>
      <h2>Notas</h2>
      <div class="sub">Lo que le pasó al animal, escrito con las palabras del productor</div>
    </div>
    <a class="boton suave" href="<?= h(url_con('/exportar', ['tipo' => 'notas'])) ?>">Planilla</a>
    <a class="boton suave" href="<?= h(url_con('/exportar', ['tipo' => 'notas', 'formato' => 'pdf'])) ?>">PDF</a>
  </header>
  <?php if (puede('registrar_nota')): ?>
    <div class="interior">
      <?php $errores_nota = errores_formulario(); ?>
      <?= formulario($volver_ficha, implode('', [
          campo('fecha', 'Fecha', $errores_nota, 'date', hoy(), ['obligatorio' => true, 'max' => hoy()]),
          campo('texto', 'Qué pasó', $errores_nota, 'textarea', '', [
              'obligatorio' => true, 'maxlength' => 500,
              'placeholder' => 'Ej: mastitis en el cuarto trasero izquierdo',
          ]),
      ]), 'Guardar nota') ?>
    </div>
  <?php endif; ?>
  <div class="tabla-scroll">
    <table data-sin-buscador data-sin-orden>
      <thead><tr><th>Fecha</th><th>Nota</th><th>Quién la escribió</th><?php if (puede('anular_registro')): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($notas as $nota): ?>
        <tr>
          <td><?= fecha_corta($nota['fecha']) ?></td>
          <td class="principal"><?= h($nota['texto']) ?></td>
          <td><?= $nota['autor'] !== null ? h($nota['autor'] . ' ' . $nota['autor_apellido']) : '—' ?></td>
          <?php if (puede('anular_registro')): ?>
            <td class="num"><?= boton_anular($volver_ficha, 'NOTA', (int) $nota['id']) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if ($notas === []): ?>
        <tr><td colspan="<?= puede('anular_registro') ? 4 : 3 ?>" class="vacio">Sin notas todavía.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (GESTION_AVANZADA && $tratamientos !== []): ?>
<div class="bloque">
  <header><div><h2>Sanidad</h2><div class="sub">Todo lo que se le aplicó</div></div></header>
  <div class="tabla-scroll">
    <table data-sin-buscador>
      <thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th>Diagnóstico</th><th>Retiro</th></tr></thead>
      <tbody>
      <?php foreach ($tratamientos as $tratamiento): ?>
        <?php $estado = estado_retiro($tratamiento['fecha'], (int) $tratamiento['dias_carencia_carne']); ?>
        <tr>
          <td><?= fecha_corta($tratamiento['fecha']) ?></td>
          <td class="principal"><?= h($tratamiento['producto']) ?></td>
          <td><?= h(legible($tratamiento['tipo'])) ?></td>
          <td><?= h($tratamiento['diagnostico'] ?? '—') ?></td>
          <td>
            <?= insignia(
                etiqueta_retiro($estado['estado']),
                match ($estado['estado']) { 'VENCIDO' => 'ok', 'POR_VENCER' => 'aviso', default => 'riesgo' },
            ) ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($tratamientos === []): ?>
        <tr><td colspan="5" class="vacio">Sin tratamientos registrados.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="bloque">
  <header><div><h2>Traslados</h2><div class="sub">Por qué corrales pasó y quién lo movió</div></div></header>
  <div class="tabla-scroll">
    <table data-sin-buscador>
      <thead><tr><th>Fecha</th><th>Desde</th><th>Hacia</th><th>Motivo</th><th>Responsable</th></tr></thead>
      <tbody>
      <?php foreach ($movimientos as $movimiento): ?>
        <tr>
          <td><?= fecha_corta($movimiento['fecha']) ?></td>
          <td><?= h($movimiento['origen'] ?? '—') ?></td>
          <td class="principal"><?= h($movimiento['destino'] ?? '—') ?></td>
          <td><?= h($movimiento['motivo'] ?? '—') ?></td>
          <td><?= h($movimiento['responsable'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($movimientos === []): ?>
        <tr><td colspan="5" class="vacio">Sin traslados: está en <?= h($animal['corral'] ?? 'ningún corral') ?> desde que se registró.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($alimentacion_individual !== []): ?>
<div class="bloque">
  <header><div><h2>Alimentación individual</h2><div class="sub">Suplementos entregados a este animal</div></div></header>
  <div class="tabla-scroll">
    <table data-sin-buscador>
      <thead><tr><th>Fecha</th><th>Ración</th><th class="num">Entregado</th><?php if (GESTION_AVANZADA): ?><th class="num">Costo</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($alimentacion_individual as $entrega): ?>
        <tr>
          <td><?= fecha_corta($entrega['fecha']) ?></td>
          <td><?= h($entrega['racion']) ?></td>
          <td class="num"><?= numero((float) $entrega['kg_entregados'], 1) ?> kg
            <div class="secundario"><?= numero((float) $entrega['kg_materia_seca'], 1) ?> kg MS</div></td>
          <?php if (GESTION_AVANZADA): ?><td class="num"><?= pesos((float) $entrega['costo_total']) ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php cerrar_pagina(); ?>
