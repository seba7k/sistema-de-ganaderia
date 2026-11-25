<?php
declare(strict_types=1);

require_once __DIR__ . '/../privacidad.php';
require_once __DIR__ . '/../vistas/layout.php';

/**
 * Solicitudes de datos personales (Ley 21.719): las que hicieron las personas
 * de la organización, con su plazo. El administrador responde o rechaza, y en
 * una supresión aceptada puede anonimizar a la persona.
 */

exigir_permiso('gestionar_usuarios');

$org = organizacion_actual();
$volver = url('/solicitudes-datos');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_post_valido('gestionar_usuarios', $volver);
    try {
        $resultado = op_responder_solicitud($org, (int) usuario_actual()['id'], $_POST);
    } catch (ErrorOperacion $error) {
        volver_con_errores($error, $volver);
    }
    flash('exito', $resultado['mensaje']);
    redirigir($volver);
}

$solicitudes = filas(
    "SELECT s.*, u.nombre, u.apellido, u.email FROM solicitudes_datos s JOIN usuarios u ON u.id = s.usuario_id
      WHERE s.organizacion_id = ? ORDER BY CASE WHEN s.estado = 'PENDIENTE' THEN 0 ELSE 1 END, s.vence_el, s.id DESC LIMIT 100",
    [$org],
);
$errores = errores_formulario();
$con_error = (int) valor_previo('solicitud_id', '0');

abrir_pagina('Solicitudes de datos', 'Pedidos de acceso, rectificación, supresión, oposición, portabilidad o bloqueo (Ley 21.719)', '/solicitudes-datos', ['Solicitudes de datos']);

echo ayuda_pantalla('¿Cómo se responde?', [
    'Plazo' => 'Cada solicitud tiene una fecha límite (' . (int) config_privacidad()['dias_respuesta'] . ' días corridos desde que se hizo; se cambia en config.php → privacidad). Las vencidas aparecen en rojo.',
    'Acceso y portabilidad' => 'La persona ya puede descargar sus datos desde «Mis datos». Respondé confirmándolo o agregando lo que falte.',
    'Rectificación' => 'Corregí el dato en Usuarios y respondé contando qué se cambió.',
    'Supresión' => 'Si corresponde, marcá «Anonimizar» al responder: la cuenta queda sin nombre, correo ni teléfono y no puede ingresar. Los registros sanitarios y de movimientos se conservan porque los exige el SAG.',
    'Rechazo' => 'Se puede rechazar con un motivo (por ejemplo, un dato que la normativa sanitaria obliga a guardar). La persona puede reclamar ante la Agencia de Protección de Datos Personales.',
]);
?>
<div class="bloque">
  <div class="tabla-scroll">
    <table>
      <thead><tr><th>Persona</th><th>Pide</th><th>Plazo</th><th>Estado y respuesta</th></tr></thead>
      <tbody>
      <?php foreach ($solicitudes as $s):
          $vencida = $s['estado'] === 'PENDIENTE' && $s['vence_el'] < hoy(); ?>
        <tr>
          <td><span class="principal"><?= h($s['nombre'] . ' ' . $s['apellido']) ?></span><div class="secundario"><?= h($s['email']) ?></div>
            <div class="secundario"><?= h(fecha_corta(substr((string) $s['creada_en'], 0, 10))) ?></div></td>
          <td><?= h(TIPOS_SOLICITUD[$s['tipo']] ?? $s['tipo']) ?><?php if ($s['detalle']): ?><div class="secundario" style="max-width: 320px"><?= h($s['detalle']) ?></div><?php endif; ?></td>
          <td><?= $s['estado'] === 'PENDIENTE' ? insignia(($vencida ? 'Vencida el ' : 'Hasta el ') . fecha_corta($s['vence_el']), $vencida ? 'riesgo' : 'aviso') : h(fecha_corta($s['vence_el'])) ?></td>
          <td>
            <?php if ($s['estado'] !== 'PENDIENTE'): ?>
              <?= insignia(ESTADOS_SOLICITUD[$s['estado']], $s['estado'] === 'RESPONDIDA' ? 'ok' : 'riesgo') ?>
              <div class="secundario"><?= h($s['respuesta'] ?? '') ?></div>
            <?php else:
                $mios = (int) $s['id'] === $con_error ? $errores : [];
                $id = static fn (string $c): string => 's' . (int) $s['id'] . '_' . $c; ?>
              <form method="post" action="<?= h($volver) ?>" class="formulario" style="grid-template-columns: 1fr">
                <input type="hidden" name="csrf" value="<?= h(token_csrf()) ?>">
                <input type="hidden" name="solicitud_id" value="<?= (int) $s['id'] ?>">
                <?= campo('estado', 'Resultado', $mios, 'select', 'RESPONDIDA', ['items' => ['RESPONDIDA' => 'Responder (se acoge)', 'RECHAZADA' => 'Rechazar con motivo'], 'id' => $id('estado')]) ?>
                <?= campo('respuesta', 'Respuesta para la persona', $mios, 'textarea', '', ['obligatorio' => true, 'id' => $id('respuesta')]) ?>
                <?php if ($s['tipo'] === 'SUPRESION'): ?>
                  <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="anonimizar" value="1"> Anonimizar a la persona al responder</label>
                  <?php if (isset($mios['anonimizar'])): ?><div class="error-campo"><?= h($mios['anonimizar']) ?></div><?php endif; ?>
                <?php endif; ?>
                <div class="acciones"><button class="boton" type="submit">Guardar respuesta</button></div>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($solicitudes === []): ?>
        <tr><td colspan="4" class="vacio">Nadie hizo solicitudes sobre sus datos.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php cerrar_pagina(); ?>
