<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

abrir_pagina('Página no encontrada', '', '', ['No encontrada']);
?>
<div class="bloque">
  <div class="interior" style="padding: var(--e5); text-align: center;">
    <div class="marca-error"><?= marca(44) ?></div>
    <h2 style="margin: 0 0 8px; font-size: 19px;">No existe esa página</h2>
    <p style="color: var(--tinta-2); margin: 0 auto var(--e3);">
      Revisá la dirección o volvé al panel para empezar de nuevo.
    </p>
    <a class="boton" href="<?= h(url('/')) ?>">Volver al panel</a>
  </div>
</div>
<?php
cerrar_pagina();
