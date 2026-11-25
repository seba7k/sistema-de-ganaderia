<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vistas/layout.php';
require_once __DIR__ . '/../dominio/retiros.php';
require_once __DIR__ . '/../dominio/indicadores.php';

/**
 * Portada.
 *
 * Criterio: cuatro números arriba, lo que necesita atención después, y recién
 * entonces los accesos a cada módulo. El detalle vive en su propia pantalla —
 * acá va lo que se mira de un vistazo antes de salir al corral.
 */

$org = organizacion_actual();

$predio = predio_actual();

$cabezas = (int) valor(
    "SELECT COUNT(*) FROM animales a WHERE a.organizacion_id = ? AND a.estado = 'ACTIVO'" . filtro_predio('a'),
    [$org],
);
$lotes_activos = !GESTION_AVANZADA ? 0 : (int) valor(
    "SELECT COUNT(*) FROM lotes l WHERE l.organizacion_id = ? AND l.estado != 'CERRADO'" . filtro_predio('l'),
    [$org],
);

$corrales = filas(
    "SELECT c.id, c.nombre, c.tipo, c.capacidad_maxima,
            (SELECT COUNT(*) FROM animales a
              WHERE a.corral_actual_id = c.id AND a.estado = 'ACTIVO') AS ocupacion
       FROM corrales c
      WHERE c.organizacion_id = ?" . filtro_predio('c') . "
      ORDER BY c.nombre",
    [$org],
);
$capacidad_total = (int) array_sum(array_column($corrales, 'capacidad_maxima'));
$ocupacion_total = (int) array_sum(array_column($corrales, 'ocupacion'));
$ocupacion_pct = $capacidad_total > 0 ? (int) round($ocupacion_total / $capacidad_total * 100) : 0;

/* Ganancia diaria promedio: kilos ganados sobre días-animal. Los días se
   cuentan en PHP, agrupando por fechas, para que la consulta sirva igual en
   MySQL y en SQLite. */
$kg_ganados = 0.0;
$dias_animal = 0;
foreach (filas(
    "SELECT a.fecha_ingreso, a.fecha_ultima_pesada, COUNT(*) AS cabezas, SUM(a.peso_actual_kg - a.peso_ingreso_kg) AS kg
       FROM animales a
      WHERE a.organizacion_id = ? AND a.estado = 'ACTIVO' AND a.peso_actual_kg IS NOT NULL" . filtro_predio('a') . "
      GROUP BY a.fecha_ingreso, a.fecha_ultima_pesada",
    [$org],
) as $grupo) {
    $kg_ganados += (float) $grupo['kg'];
    $dias_animal += max(dias_entre($grupo['fecha_ingreso'], $grupo['fecha_ultima_pesada'] ?? hoy()), 0) * (int) $grupo['cabezas'];
}
$adg = $dias_animal > 0 ? round($kg_ganados / $dias_animal, 2) : null;

/* --- Lo que requiere atención --- */

$pendientes = [];

/* Las carencias del predio activo, con la misma función que usa Hacienda: antes
   esta pantalla contaba los tratamientos de toda la organización y avisaba de
   animales que ni siquiera estaban en este campo. Viene un animal por fila, ya
   filtrados los que volvieron a estar aptos. */
$carencias_panel = !GESTION_AVANZADA ? [] : carencias_activas($org, $predio);
$animales_con_retiro = count($carencias_panel);
$proximo_retiro = null;
foreach ($carencias_panel as $retiro) {
    if ($proximo_retiro === null || $retiro['dias_restantes'] < $proximo_retiro['dias']) {
        $proximo_retiro = [
            'dias'     => $retiro['dias_restantes'],
            'producto' => $retiro['producto'],
            'fecha'    => $retiro['fecha_habilitacion'],
        ];
    }
}

if ($animales_con_retiro > 0) {
    $pendientes[] = [
        'tono'    => 'riesgo',
        'titulo'  => numero($animales_con_retiro) . ' animales no pueden ir a faena',
        'detalle' => $proximo_retiro !== null
            ? 'El primero se habilita el ' . fecha_corta($proximo_retiro['fecha'])
              . ' (' . $proximo_retiro['producto'] . ')'
            : '',
        'ruta'    => '/sanidad',
        'accion'  => 'Ver sanidad',
    ];
}

$stock_bajo = !GESTION_AVANZADA ? [] : filas(
    'SELECT i.nombre, i.unidad, i.stock_actual, i.stock_minimo
       FROM insumos i
      WHERE i.organizacion_id = ? AND i.stock_minimo IS NOT NULL AND i.stock_actual <= i.stock_minimo' . filtro_predio('i') . '
      ORDER BY (i.stock_actual / CASE WHEN i.stock_minimo > 0 THEN i.stock_minimo ELSE 1 END)',
    [$org],
);
if ($stock_bajo !== [] && puede('ver_insumos')) {
    $nombres = array_slice(array_column($stock_bajo, 'nombre'), 0, 3);
    $pendientes[] = [
        'tono'    => 'aviso',
        'titulo'  => numero(count($stock_bajo)) . ' insumo(s) por reponer',
        'detalle' => implode(', ', $nombres) . (count($stock_bajo) > 3 ? ' y otros' : ''),
        'ruta'    => '/insumos',
        'accion'  => 'Ver insumos',
    ];
}

$sobrepoblados = array_filter(
    $corrales,
    static fn (array $c): bool => (int) $c['ocupacion'] > (int) $c['capacidad_maxima'],
);
if ($sobrepoblados !== []) {
    $pendientes[] = [
        'tono'    => 'riesgo',
        'titulo'  => numero(count($sobrepoblados)) . ' corral(es) sobre su capacidad',
        'detalle' => implode(', ', array_column($sobrepoblados, 'nombre')),
        'ruta'    => '/corrales',
        'accion'  => 'Ver corrales',
    ];
}

/* Alerta de descarte: vacas con tres años seguidos de abortos o terneros
   débiles. La decisión es del administrador; el sistema se la pone adelante. */
$vacas_descarte = puede('ver_descartes') ? vacas_para_descarte($org, $predio) : [];
if ($vacas_descarte !== []) {
    $pendientes[] = [
        'tono'    => 'riesgo',
        'titulo'  => numero(count($vacas_descarte)) . ' vaca(s) sugeridas para descarte',
        'detalle' => implode(', ', array_map(
            static fn (array $v): string => (string) ($v['caravana_visual'] ?? $v['numero_interno']),
            array_slice($vacas_descarte, 0, 4),
        )) . ': ' . ANIOS_PARA_DESCARTE . ' años seguidos con abortos o terneros débiles',
        'ruta'    => '/reproduccion',
        'accion'  => 'Ver vacas',
    ];
}

/* Pesajes que bajaron o que suben más de lo posible: casi siempre es un error
   de tipeo o se pesó otro animal. */
$alertas_pesaje = puede('ver_hacienda') ? alertas_de_pesaje($org, 30, $predio) : [];
if ($alertas_pesaje !== []) {
    $pendientes[] = [
        'tono'    => 'aviso',
        'titulo'  => numero(count($alertas_pesaje)) . ' pesaje(s) con variación anormal',
        'detalle' => implode(' · ', array_map(
            static fn (array $a): string => $a['nombre'] . ': ' . $a['alerta']['mensaje'],
            array_slice($alertas_pesaje, 0, 2),
        )),
        'href'    => count($alertas_pesaje) === 1
            ? url_con('/animal', ['id' => $alertas_pesaje[0]['animal_id']])
            : url('/hacienda'),
        'ruta'    => '/hacienda',
        'accion'  => count($alertas_pesaje) === 1 ? 'Ver ficha' : 'Ver hacienda',
    ];
}

/* --- Dato de cabecera para cada tarjeta de módulo --- */

/* Las entregas de un predio son las de sus corrales Y las de sus animales: una
   entrega individual no tiene corral, y mirando solo el corral desaparecía del
   total justo en el caso de crianza. */
$consumo_ms = (float) valor(
    'SELECT COALESCE(SUM(e.kg_materia_seca), 0) FROM entregas_alimento e
       LEFT JOIN corrales c ON c.id = e.corral_id
       LEFT JOIN animales a ON a.id = e.animal_id
      WHERE e.organizacion_id = ? AND e.fecha >= ? AND e.anulada = 0'
    . ($predio === null ? '' : ' AND (c.establecimiento_id = ' . $predio . ' OR a.establecimiento_id = ' . $predio . ')'),
    [$org, hace_dias(30)],
);

$resumen_modulos = [
    '/hacienda'     => [numero($cabezas), 'cabezas activas'],
    '/corrales'     => [$ocupacion_pct . '%', 'de ocupación'],
    '/alimentacion' => [numero($consumo_ms / 1000, 1) . ' t', 'materia seca / 30 días'],
    '/sanidad'      => [numero($animales_con_retiro), 'con retiro sin cumplir'],
    '/reproduccion' => [
        numero((int) valor(
            "SELECT COUNT(*) FROM servicios s LEFT JOIN partos p ON p.servicio_id = s.id
              JOIN animales a ON a.id = s.vaca_id
              WHERE s.organizacion_id = ? AND s.resultado = 'PRENADA' AND p.id IS NULL" . filtro_predio('a'),
            [$org],
        )),
        'vacas gestando',
    ],
    '/lotes'        => [numero($lotes_activos), 'lotes en curso'],
    '/app/'         => ['Sin señal', 'se instala en el teléfono'],
    '/insumos'      => [numero(count($stock_bajo)), 'por reponer'],
    '/usuarios'     => [
        numero((int) valor('SELECT COUNT(*) FROM membresias WHERE organizacion_id = ? AND activa = 1', [$org])),
        'personas con acceso',
    ],
    '/predios'      => [
        numero(count(predios_permitidos())),
        'campos del propietario',
    ],
];

abrir_pagina(
    'Panel',
    'Resumen del día. El detalle de cada tema está en su módulo.',
    '/',
);

echo ayuda_pantalla('¿Qué significa cada número?', [
    'Hacienda' => 'Cabezas vivas en el establecimiento, sin contar las vendidas ni las muertas.',
    'Ocupación' => 'Cuántas de las plazas disponibles en los corrales están usadas. Sobre 90% conviene mirar los movimientos.',
    'Ganancia diaria' => 'Kilos que gana por día un animal promedio (ADG). Es el indicador central de la engorda.',
    'Requiere atención' => 'Temas abiertos que conviene resolver: '
        . (GESTION_AVANZADA ? 'animales bloqueados para faena, ' : '')
        . 'vacas para descarte, pesajes anormales'
        . (GESTION_AVANZADA ? ', insumos por reponer' : '') . ' o corrales pasados de capacidad.',
]);
?>

<section class="indicadores">
  <div class="indicador">
    <div class="rotulo">Hacienda</div>
    <div class="cifra"><?= numero($cabezas) ?></div>
    <div class="nota">
      <?= GESTION_AVANZADA ? 'cabezas en ' . numero($lotes_activos) . ' lote(s)' : 'cabezas activas en ' . h(nombre_predio()) ?>
    </div>
  </div>

  <div class="indicador <?= $ocupacion_pct >= 90 ? 'aviso' : '' ?>">
    <div class="rotulo">Ocupación</div>
    <div class="cifra"><?= $ocupacion_pct ?><small>%</small></div>
    <div class="nota"><?= numero($ocupacion_total) ?> de <?= numero($capacidad_total) ?> plazas</div>
  </div>

  <div class="indicador ok">
    <div class="rotulo">Ganancia diaria</div>
    <div class="cifra"><?= $adg === null ? '—' : numero($adg, 2) ?><small> kg</small></div>
    <div class="nota">por animal por día</div>
  </div>

  <div class="indicador <?= $pendientes === [] ? 'ok' : 'riesgo' ?>">
    <div class="rotulo">Requiere atención</div>
    <div class="cifra"><?= numero(count($pendientes)) ?></div>
    <div class="nota"><?= $pendientes === [] ? 'todo en orden' : 'temas abiertos' ?></div>
  </div>
</section>

<?php if ($pendientes !== []): ?>
  <section class="bloque">
    <header>
      <div>
        <h2>Requiere atención</h2>
        <div class="sub">Lo que conviene resolver antes de seguir</div>
      </div>
    </header>
    <div class="tabla-scroll">
      <table class="lista-avisos">
        <tbody>
        <?php foreach ($pendientes as $pendiente): ?>
          <tr>
            <td style="width: 130px">
              <?= insignia($pendiente['tono'] === 'riesgo' ? 'Urgente' : 'Revisar', $pendiente['tono']) ?>
            </td>
            <td>
              <div class="principal"><?= h($pendiente['titulo']) ?></div>
              <?php if ($pendiente['detalle'] !== ''): ?>
                <div class="secundario"><?= h($pendiente['detalle']) ?></div>
              <?php endif; ?>
            </td>
            <td class="num" style="width: 160px">
              <a class="boton suave" href="<?= h($pendiente['href'] ?? url($pendiente['ruta'])) ?>"><?= h($pendiente['accion']) ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>

<section class="bloque" style="border: 0; background: none; box-shadow: none; overflow: visible;">
  <header style="border: 0; padding: 0 0 var(--e3);">
    <div>
      <h2>Módulos</h2>
      <div class="sub">Cada uno abre su propia pantalla con el detalle completo</div>
    </div>
  </header>

  <?php foreach (array_keys(modulos()) as $grupo): ?>
    <?php $visibles = modulos_visibles($grupo); ?>
    <?php if ($visibles === []) { continue; } ?>
    <div class="modulos">
      <?php foreach ($visibles as $modulo): ?>
        <?php [$valor_clave, $unidad] = $resumen_modulos[$modulo['ruta']] ?? ['', '']; ?>
        <a class="modulo" href="<?= h(url_modulo($modulo['ruta'])) ?>">
          <div class="cabeza">
            <span class="simbolo" style="--c: <?= $modulo['color'] ?>"><?= $modulo['icono'] ?></span>
            <h3><?= h($modulo['nombre']) ?></h3>
          </div>
          <p class="descripcion"><?= h($modulo['detalle']) ?></p>
          <div class="remate">
            <span class="valor"><?= h($valor_clave) ?></span>
            <span class="unidad"><?= h($unidad) ?></span>
            <span class="ir">Abrir</span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</section>

<section class="bloque">
  <header>
    <div>
      <h2>Ocupación por corral</h2>
      <div class="sub">La capacidad se valida en cada movimiento de entrada</div>
    </div>
    <a class="boton suave" href="<?= h(url('/corrales')) ?>">Ver todo</a>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead>
        <tr><th>Corral</th><th class="num">Cabezas</th><th style="width: 210px">Ocupación</th></tr>
      </thead>
      <tbody>
      <?php foreach (array_slice($corrales, 0, 6) as $corral): ?>
        <?php
        $capacidad = (int) $corral['capacidad_maxima'];
        $ocupado = (int) $corral['ocupacion'];
        $pct = $capacidad > 0 ? (int) round($ocupado / $capacidad * 100) : 0;
        ?>
        <tr>
          <td>
            <span class="principal"><?= h($corral['nombre']) ?></span>
            <?php if ($corral['tipo'] !== 'CORRAL'): ?>
              <?= insignia(legible($corral['tipo']), 'neutro', false) ?>
            <?php endif; ?>
          </td>
          <td class="num"><?= numero($ocupado) ?> <span style="color:var(--tinta-3)">/ <?= numero($capacidad) ?></span></td>
          <td><?= medidor($pct) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($corrales) > 6): ?>
    <div class="pie-bloque">
      <a href="<?= h(url('/corrales')) ?>">Ver los <?= numero(count($corrales)) ?> corrales →</a>
    </div>
  <?php endif; ?>
</section>

<?php cerrar_pagina(); ?>
