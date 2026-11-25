<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

abrir_pagina('Sin permiso', '', '', ['Sin permiso']);
?>
<div class="bloque">
  <div class="interior" style="padding: var(--e5); text-align: center;">
    <div class="marca-error"><?= marca(44) ?></div>
    <h2 style="margin: 0 0 8px; font-size: 19px;">Esta sección no está disponible para tu rol</h2>
    <p style="color: var(--tinta-2); max-width: 46ch; margin: 0 auto var(--e3);">
      Tu rol es <strong><?= h(nombre_rol(usuario_actual()['rol'])) ?></strong>.
      Si necesitás entrar acá, pedile a un administrador que te cambie el rol.
    </p>
    <a class="boton" href="<?= h(url('/')) ?>">Volver al panel</a>
  </div>
</div>
<?php
cerrar_pagina();
