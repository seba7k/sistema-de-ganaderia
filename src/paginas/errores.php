<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vistas/layout.php';

/**
 * Errores del servidor: lo que falló en los últimos 30 días, agrupado, y el
 * estado de los avisos automáticos. Solo para el administrador.
 *
 * El registro y el envío de avisos viven en src/alertas.php.
 */

exigir_permiso('ver_errores');

$volver = url('/errores');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_post_valido('ver_errores', $volver);
    if (entrada('accion') === 'revisado' && preg_match('/^[0-9a-f]{16}$/', entrada('huella')) === 1) {
        marcar_error_revisado(entrada('huella'));
        flash('exito', 'Marcado como revisado. Si vuelve a ocurrir, aparece de nuevo.');
    } elseif (entrada('accion') === 'prueba') {
        if (!alertas_configuradas()) {
            flash('error', 'No hay ningún canal de aviso configurado en config.php → alertas.');
        } else {
            $c = config_alertas();
            $enviado = enviar_alerta([
                'momento' => date('Y-m-d H:i:s'), 'nivel' => 'ERROR', 'tipo' => 'Aviso de prueba',
                'mensaje' => 'Si te llegó esto, los avisos de errores funcionan.', 'archivo' => '', 'linea' => 0,
                'ruta' => '/errores', 'huella' => 'prueba',
            ], 0);
            flash($enviado ? 'exito' : 'error', $enviado
                ? 'Aviso de prueba enviado. Revisá ' . implode(', ', array_filter([
                    $c['correo'] !== '' ? 'el correo' : '', $c['telegram_token'] !== '' ? 'Telegram' : '', $c['webhook'] !== '' ? 'el webhook' : '',
                ])) . '.'
                : 'No se pudo enviar por ningún canal. Revisá los datos en config.php y que el hosting permita salir a internet.');
        }
    }
    redirigir($volver);
}

$errores = errores_recientes(30);
$c = config_alertas();
$canales = array_filter([
    $c['correo'] !== '' ? 'Correo a ' . $c['correo'] : '',
    $c['telegram_token'] !== '' && $c['telegram_chat'] !== '' ? 'Telegram' : '',
    $c['webhook'] !== '' ? 'Webhook' : '',
]);

abrir_pagina('Errores del servidor', 'Lo que falló en los últimos 30 días y a quién se avisa', '/errores', ['Errores del servidor']);

echo ayuda_pantalla('¿Cómo funcionan los avisos?', [
    'Qué se registra' => 'Todo error no controlado: excepciones, errores fatales de PHP, caídas de la base de datos y fallas al sincronizar la app. Se guarda en datos/errores.log aunque la base no responda.',
    'Avisos' => 'Se mandan por los canales de config.php → alertas: correo (con el correo del propio hosting), un bot de Telegram o un webhook. El mismo error se avisa como máximo una vez por hora; las repeticiones se cuentan en el aviso siguiente.',
    'Privacidad' => 'El aviso no lleva datos de formularios, y enmascara correos, RUT y números largos.',
    'Si se cae todo' => 'Si el servidor completo no responde, el sistema no puede avisar. Para eso conviene un monitor externo gratuito (por ejemplo UptimeRobot) que revise cada 5 minutos la dirección /salud.',
    'Revisado' => 'Marca un error como atendido. Si vuelve a ocurrir, reaparece.',
]);
?>

<section class="indicadores">
  <div class="indicador <?= $canales === [] ? 'riesgo' : 'ok' ?>">
    <div class="rotulo">Avisos automáticos</div>
    <div class="cifra" style="font-size: 22px"><?= $canales === [] ? 'Sin configurar' : h(implode(' · ', $canales)) ?></div>
    <div class="nota"><?= $canales === [] ? 'Agregá un canal en config.php → alertas' : 'un aviso por error y por hora, como máximo' ?></div>
  </div>
  <div class="indicador <?= errores_sin_revisar() > 0 ? 'aviso' : 'ok' ?>">
    <div class="rotulo">Sin revisar · 24 h</div>
    <div class="cifra"><?= numero(errores_sin_revisar()) ?></div>
    <div class="nota">errores distintos</div>
  </div>
  <div class="indicador">
    <div class="rotulo">Monitor externo</div>
    <div class="cifra" style="font-size: 16px; word-break: break-all"><?= h((es_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . url('/salud')) ?></div>
    <div class="nota">dirección para UptimeRobot u otro monitor</div>
  </div>
</section>

<?php if ($canales !== []): ?>
<form method="post" action="<?= h($volver) ?>" style="margin-bottom: 16px">
  <input type="hidden" name="csrf" value="<?= h(token_csrf()) ?>">
  <input type="hidden" name="accion" value="prueba">
  <button class="boton suave" type="submit">Enviar un aviso de prueba</button>
</form>
<?php endif; ?>

<div class="bloque">
  <header>
    <div>
      <h2>Últimos 30 días</h2>
      <div class="sub">Agrupados: el mismo error repetido aparece una sola vez con su cantidad</div>
    </div>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead><tr><th>Última vez</th><th>Error</th><th>Dónde</th><th class="num">Veces</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($errores as $e): ?>
        <tr>
          <td><?= h(fecha_corta(substr($e['momento'], 0, 10)) . ' ' . substr($e['momento'], 11, 5)) ?>
            <div class="secundario">desde <?= h(fecha_corta(substr($e['primera'], 0, 10))) ?></div></td>
          <td><?= insignia($e['tipo'], $e['nivel'] === 'CRITICO' ? 'riesgo' : 'aviso') ?>
            <div style="max-width: 520px; overflow-wrap: anywhere"><?= h($e['mensaje']) ?></div></td>
          <td><span class="secundario"><?= h($e['archivo'] !== '' ? $e['archivo'] . ':' . $e['linea'] : '—') ?></span>
            <div class="secundario"><?= h($e['ruta']) ?></div></td>
          <td class="num"><?= numero($e['veces']) ?></td>
          <td>
            <?php if ($e['revisado']): ?>
              <?= insignia('Revisado', 'ok') ?>
            <?php else: ?>
              <form method="post" action="<?= h($volver) ?>">
                <input type="hidden" name="csrf" value="<?= h(token_csrf()) ?>">
                <input type="hidden" name="accion" value="revisado">
                <input type="hidden" name="huella" value="<?= h($e['huella']) ?>">
                <button class="boton menudo suave" type="submit">Marcar revisado</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($errores === []): ?>
        <tr><td colspan="5" class="vacio">Sin errores en los últimos 30 días.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php cerrar_pagina(); ?>
