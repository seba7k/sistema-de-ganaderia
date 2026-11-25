<?php
declare(strict_types=1);

require_once __DIR__ . '/../privacidad.php';
require_once __DIR__ . '/../vistas/layout.php';

/**
 * Mis datos y privacidad: cada persona ve qué datos suyos tiene el sistema,
 * los descarga (acceso y portabilidad) y pide rectificación, supresión,
 * oposición o bloqueo. Las solicitudes las responde un administrador en
 * «Solicitudes de datos».
 */

exigir_permiso('ver_mis_datos');

$org = organizacion_actual();
$yo = (int) usuario_actual()['id'];
$volver = url('/mis-datos');

if (($_GET['descargar'] ?? '') === '1') {
    registrar_bitacora($org, $yo, 'DESCARGA_DATOS_PROPIOS', 'usuarios', $yo, 'Portabilidad');
    $json = json_encode(datos_personales_de($yo), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="mis-datos-' . hoy() . '.json"');
    header('Cache-Control: no-store');
    echo $json;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_post_valido('ver_mis_datos', $volver);
    try {
        $resultado = op_solicitud_datos($org, $yo, $_POST);
    } catch (ErrorOperacion $error) {
        volver_con_errores($error, $volver);
    }
    flash('exito', $resultado['mensaje']);
    redirigir($volver);
}

$datos = datos_personales_de($yo);
$persona = $datos['persona'];
$errores = errores_formulario();

abrir_pagina('Mis datos y privacidad', 'Qué datos tuyos guarda el sistema y cómo ejercer tus derechos (Ley 21.719)', '/mis-datos', ['Mis datos']);

echo ayuda_pantalla('¿Qué puedo hacer acá?', [
    'Ver y descargar' => 'Abajo están tus datos. «Descargar mis datos» te da un archivo con todo lo que el sistema guarda de vos: es el derecho de acceso y de portabilidad.',
    'Pedir algo' => 'Para corregir un dato, pedir que se borren, oponerte a un uso o pedir que se bloqueen mientras se revisa, hacé una solicitud. El responsable tiene ' . (int) config_privacidad()['dias_respuesta'] . ' días corridos para responder.',
    'Supresión' => 'Si se acepta, tu cuenta se anonimiza. Los registros sanitarios y de movimientos que exige el SAG se conservan, pero sin tu nombre.',
    'Reclamo' => 'Si no te responden o no estás de acuerdo, podés reclamar ante la Agencia de Protección de Datos Personales.',
]);
?>

<div class="par">
  <div class="bloque">
    <header>
      <div>
        <h2>Tus datos</h2>
        <div class="sub">Lo que el sistema guarda de vos</div>
      </div>
      <a class="boton" href="<?= h(url_con('/mis-datos', ['descargar' => '1'])) ?>">Descargar mis datos</a>
    </header>
    <div class="tabla-scroll">
      <table data-sin-buscador>
        <tbody>
          <tr><th>Nombre</th><td><?= h($persona['nombre'] . ' ' . $persona['apellido']) ?></td></tr>
          <tr><th>Correo</th><td><?= h($persona['email']) ?></td></tr>
          <tr><th>Teléfono</th><td><?= h($persona['telefono'] ?? '—') ?></td></tr>
          <tr><th>Cuenta creada</th><td><?= h(fecha_corta(substr((string) $persona['creado_en'], 0, 10))) ?></td></tr>
          <tr><th>Último ingreso</th><td><?= h($persona['ultimo_acceso_en'] ? fecha_corta(substr((string) $persona['ultimo_acceso_en'], 0, 10)) : '—') ?></td></tr>
          <?php foreach ($datos['organizaciones'] as $m): ?>
            <tr><th><?= h($m['organizacion']) ?></th><td><?= h(nombre_rol($m['rol'])) ?><?= $m['mva_rut'] ? ' · MVA RUT ' . h($m['mva_rut']) . ' N° ' . h($m['mva_numero']) : '' ?><?= (int) $m['activa'] === 1 ? '' : ' · inactiva' ?></td></tr>
          <?php endforeach; ?>
          <tr><th>App de terreno</th><td><?= numero(count($datos['dispositivos_app'])) ?> sesión(es) de dispositivo</td></tr>
          <tr><th>Actividad registrada</th><td><?= numero(count($datos['actividad'])) ?> acciones en la bitácora (se incluyen en la descarga)</td></tr>
          <tr><th>Política recibida</th><td><?= $datos['politica_recibida'] === [] ? 'Todavía no' : h('Versión ' . end($datos['politica_recibida'])['version'] . ' el ' . fecha_corta(substr((string) end($datos['politica_recibida'])['aceptada_en'], 0, 10))) ?>
            · <a href="<?= h(url('/privacidad')) ?>">Leer la política</a></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="bloque">
    <header>
      <div>
        <h2>Hacer una solicitud</h2>
        <div class="sub">La recibe el administrador de tu organización</div>
      </div>
    </header>
    <?= formulario($volver, implode('', [
        campo('tipo', 'Qué querés pedir', $errores, 'select', '', ['items' => ['' => 'Elegí'] + TIPOS_SOLICITUD, 'obligatorio' => true]),
        campo('detalle', 'Detalle', $errores, 'textarea', '', ['placeholder' => 'Ej: mi teléfono correcto es…', 'ayuda' => 'Obligatorio para rectificación y oposición']),
    ]), 'Enviar solicitud') ?>
  </div>
</div>

<div class="bloque">
  <header><div><h2>Tus solicitudes</h2></div></header>
  <div class="tabla-scroll">
    <table>
      <thead><tr><th>Fecha</th><th>Tipo</th><th>Estado</th><th>Plazo</th><th>Respuesta</th></tr></thead>
      <tbody>
      <?php foreach ($datos['solicitudes'] as $s): ?>
        <tr>
          <td><?= h(fecha_corta(substr((string) $s['creada_en'], 0, 10))) ?></td>
          <td><?= h(explode(':', TIPOS_SOLICITUD[$s['tipo']] ?? $s['tipo'])[0]) ?></td>
          <td><?= insignia(ESTADOS_SOLICITUD[$s['estado']] ?? $s['estado'], $s['estado'] === 'PENDIENTE' ? 'aviso' : ($s['estado'] === 'RESPONDIDA' ? 'ok' : 'riesgo')) ?></td>
          <td><?= h(fecha_corta($s['vence_el'])) ?></td>
          <td><?= h($s['respuesta'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($datos['solicitudes'] === []): ?>
        <tr><td colspan="5" class="vacio">No hiciste solicitudes.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php cerrar_pagina(); ?>
