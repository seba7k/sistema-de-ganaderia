<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vistas/layout.php';
require_once __DIR__ . '/../documentos_sag.php';

/**
 * Salidas del predio: venta, faena o traslado a otro predio.
 *
 * Las reglas (bloqueo por resguardo, RUP, DIIO) viven en op_salida. Acá están
 * el formulario, los animales que hoy no pueden salir, el historial y la
 * descarga del borrador de FMA y de la planilla para SIPEC.
 */

exigir_permiso('registrar_venta');

$org = organizacion_actual();
$volver = url('/salidas');

/* ---------- Documentos de una salida ---------- */
$documento = (string) ($_GET['documento'] ?? '');
if ($documento !== '') {
    $salida = salida_completa($org, (int) ($_GET['id'] ?? 0));
    if ($salida === null) {
        http_response_code(404);
        require __DIR__ . '/../vistas/error-404.php';
        exit;
    }
    match ($documento) {
        'fma'   => pdf_descargar(pdf_borrador_fma($salida), 'FMA-borrador-' . $salida['id'] . '.pdf'),
        'sipec' => xlsx_descargar(xlsx_planilla_sipec($salida), 'SIPEC-movimiento-' . $salida['id'] . '.xlsx'),
        default => redirigir($volver),
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_post_valido('registrar_venta', $volver);
    try {
        $resultado = op_salida($org, (int) usuario_actual()['id'], $_POST);
    } catch (ErrorOperacion $error) {
        volver_con_errores($error, $volver);
    }
    flash('exito', $resultado['mensaje']);
    redirigir(url_con('/salidas', ['nueva' => $resultado['id']]));
}

$predio = predio_actual();
$origen = fila('SELECT * FROM establecimientos WHERE organizacion_id = ? AND id = ?', [$org, $predio]);

// Animales del predio que hoy no pueden salir, con su producto y fecha.
$retiros = carencias_activas($org, $predio);
$bloqueados = $retiros === [] ? [] : filas(
    "SELECT id, caravana_visual, numero_interno, categoria FROM animales
      WHERE organizacion_id = ? AND estado = 'ACTIVO' AND id IN (" . implode(',', array_map('intval', array_keys($retiros))) . ')
      ORDER BY caravana_visual',
    [$org],
);

$salidas = filas(
    'SELECT s.* FROM salidas s WHERE s.organizacion_id = ? AND s.establecimiento_id = ? ORDER BY s.fecha DESC, s.id DESC LIMIT 50',
    [$org, $predio],
);
$nueva = (int) ($_GET['nueva'] ?? 0);

abrir_pagina(
    'Salidas',
    'Venta, faena o traslado a otro predio, con bloqueo sanitario y borrador del FMA',
    '/salidas',
    ['Salidas'],
);

echo ayuda_pantalla('¿Cómo funcionan las salidas?', [
    'Bloqueo sanitario' => 'Un animal con un medicamento en período de resguardo no puede salir del predio. El sistema lo revisa a la fecha de la salida, esté o no validado el tratamiento por el MVA, y dice desde qué día puede salir.',
    'DIIO y RUP' => 'Cada animal tiene que tener su DIIO, y el predio de origen y el de destino su RUP. Son los datos del Formulario de Movimiento Animal (FMA).',
    'Traslado propio' => 'Si el RUP de destino es otro predio tuyo, los animales siguen activos y pasan a ese predio. Si es de un tercero, salen del inventario.',
    'Documentos' => 'Cada salida deja listo un borrador del FMA en PDF y una planilla Excel con los DIIO para cargar en SIPEC Web. Revisá la planilla contra el formato vigente del SAG antes de subirla.',
]);

$errores = errores_formulario();

if ($origen !== null && trim((string) $origen['rup']) === '') {
    echo '<div class="mensaje error"><span class="marca-aviso">!</span><div>El predio ' . h($origen['nombre']) . ' no tiene RUP. Cargalo en <a href="' . h(url('/predios')) . '">Predios</a> para poder registrar salidas.</div></div>';
}

echo abrir_alta('Registrar una salida', 'Desde ' . ($origen['nombre'] ?? '') . ($origen['rup'] ? ' · RUP ' . $origen['rup'] : ''), $errores !== []);
echo formulario(url('/salidas'), implode('', [
    campo('tipo', 'Tipo de salida', $errores, 'select', 'VENTA', ['items' => TIPOS_SALIDA, 'obligatorio' => true]),
    campo('fecha', 'Fecha de salida', $errores, 'date', hoy(), ['obligatorio' => true, 'max' => hoy()]),
    campo('identificaciones', 'DIIO de los animales', $errores, 'textarea', '', [
        'obligatorio' => true, 'placeholder' => 'Uno por línea o separados por coma. También se pueden leer con el bastón o la cámara.',
    ]),
    campo('destino_rup', 'RUP de destino', $errores, 'text', '', ['obligatorio' => true, 'placeholder' => 'Ej: 08.1.02.0044']),
    campo('destino_nombre', 'Predio o planta de destino', $errores, 'text', '', ['placeholder' => 'Ej: Frigorífico Temuco']),
    campo('destino_comuna', 'Comuna de destino', $errores, 'text'),
    campo('comprador', 'Comprador', $errores, 'text', '', ['ayuda' => 'Vacío en un traslado propio']),
    campo('transportista', 'Transportista', $errores, 'text'),
    campo('transportista_rut', 'RUT del transportista', $errores, 'text', '', ['placeholder' => 'Ej: 12.345.678-9']),
    campo('patente', 'Patente del camión', $errores, 'text', '', ['placeholder' => 'Ej: ABCD12']),
    campo('peso_total_kg', 'Peso total (kg)', $errores, 'number', '', ['min' => 1, 'step' => '0.1', 'ayuda' => 'Opcional; con precio, calcula el monto']),
    campo('precio_por_kg', 'Precio por kilo', $errores, 'number', '', ['min' => 1, 'step' => '1']),
    campo('descuentos', 'Descuentos', $errores, 'number', '', ['min' => 0, 'step' => '1', 'ayuda' => 'Comisión, flete, etc.']),
    campo('observaciones', 'Observaciones', $errores, 'textarea'),
]), 'Registrar salida');
echo cerrar_alta();
?>

<?php if ($bloqueados !== []): ?>
<div class="bloque">
  <header>
    <div>
      <h2>Bloqueados por resguardo</h2>
      <div class="sub">Estos animales no pueden venderse, faenarse ni salir del predio hasta la fecha indicada</div>
    </div>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead><tr><th>Animal</th><th>Producto</th><th>Puede salir desde</th><th class="num">Faltan</th></tr></thead>
      <tbody>
      <?php foreach ($bloqueados as $animal): $retiro = $retiros[(int) $animal['id']]; ?>
        <tr>
          <td><a class="principal" href="<?= h(url_con('/animal', ['id' => (int) $animal['id']])) ?>"><?= h(nombre_animal($animal)) ?></a>
            <div class="secundario"><?= h(legible($animal['categoria'])) ?></div></td>
          <td><?= h($retiro['producto']) ?></td>
          <td><?= h(fecha_corta($retiro['fecha_habilitacion'])) ?></td>
          <td class="num"><?= insignia(numero($retiro['dias_restantes']) . ' d', $retiro['estado'] === 'POR_VENCER' ? 'aviso' : 'riesgo') ?></td>
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
      <h2>Salidas registradas</h2>
      <div class="sub">Las últimas 50 de este predio, con su FMA y su planilla para SIPEC</div>
    </div>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead><tr><th>Fecha</th><th>Tipo</th><th>Destino</th><th class="num">Animales</th><th class="num">Monto</th><th>Documentos</th></tr></thead>
      <tbody>
      <?php foreach ($salidas as $salida): ?>
        <tr<?= (int) $salida['id'] === $nueva ? ' style="background: var(--ok-fondo)"' : '' ?>>
          <td><?= h(fecha_corta($salida['fecha'])) ?></td>
          <td><?= h(TIPOS_SALIDA[$salida['tipo']] ?? legible($salida['tipo'])) ?></td>
          <td><span class="principal">RUP <?= h($salida['destino_rup']) ?></span>
            <div class="secundario"><?= h(trim(($salida['destino_nombre'] ?? '') . ' ' . ($salida['destino_comuna'] ?? ''))) ?></div></td>
          <td class="num"><?= numero((int) $salida['cantidad_animales']) ?></td>
          <td class="num"><?= $salida['monto_neto'] !== null ? pesos((float) $salida['monto_neto']) : '—' ?></td>
          <td>
            <a class="boton menudo" href="<?= h(url_con('/salidas', ['documento' => 'fma', 'id' => (int) $salida['id']])) ?>">FMA (PDF)</a>
            <a class="boton menudo suave" href="<?= h(url_con('/salidas', ['documento' => 'sipec', 'id' => (int) $salida['id']])) ?>">SIPEC (Excel)</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($salidas === []): ?>
        <tr><td colspan="6" class="vacio">Todavía no hay salidas registradas en este predio.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php cerrar_pagina(); ?>
