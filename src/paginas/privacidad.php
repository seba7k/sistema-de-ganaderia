<?php
declare(strict_types=1);

require_once __DIR__ . '/../privacidad.php';
require_once __DIR__ . '/../vistas/layout.php';

/**
 * Política de privacidad (Ley 21.719, deber de información).
 *
 * Es pública: se puede leer sin ingresar (está enlazada desde la pantalla de
 * ingreso). Con sesión, muestra la de la organización actual y, si la persona
 * todavía no recibió esta versión, el botón para dejar constancia.
 */

$usuario = usuario_actual();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $usuario !== null) {
    if (verificar_csrf(isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : null)) {
        aceptar_privacidad((int) $usuario['id']);
        registrar_bitacora(organizacion_actual(), (int) $usuario['id'], 'RECIBE_POLITICA', 'aceptaciones_privacidad', null, 'Versión ' . VERSION_POLITICA);
        flash('exito', 'Listo. Podés volver a leer la política cuando quieras desde «Mis datos y privacidad».');
    }
    redirigir(url('/'));
}

$secciones = politica_privacidad($usuario !== null ? organizacion_actual() : null);
$pendiente = $usuario !== null && privacidad_pendiente((int) $usuario['id']);

$cuerpo = static function () use ($secciones, $pendiente): void {
    foreach ($secciones as $seccion) {
        echo '<section style="margin-bottom: 20px"><h2 style="font-size: 18px; margin-bottom: 6px">' . h($seccion['titulo']) . '</h2>';
        foreach ($seccion['parrafos'] as $parrafo) {
            echo '<p style="margin: 0 0 8px; line-height: 1.55">' . h($parrafo) . '</p>';
        }
        echo '</section>';
    }
    if ($pendiente) {
        echo '<form method="post" action="' . h(url('/privacidad')) . '" style="margin-top: 24px">'
            . '<input type="hidden" name="csrf" value="' . h(token_csrf()) . '">'
            . '<button class="boton" type="submit">Leí y entendí la política</button>'
            . ' <span class="secundario">Queda constancia de que la recibiste (versión ' . h(VERSION_POLITICA) . ').</span></form>';
    }
};

if ($usuario !== null) {
    abrir_pagina('Política de privacidad', 'Cómo se tratan los datos personales en este sistema (Ley 21.719)', '/privacidad', ['Privacidad']);
    if ($pendiente) {
        echo '<div class="mensaje info"><span class="marca-aviso">i</span><div>Antes de seguir, leé cómo se tratan tus datos. Es una sola vez por cada versión de la política.</div></div>';
    }
    echo '<div class="bloque">';
    $cuerpo();
    echo '</div>';
    cerrar_pagina('/privacidad');
    exit;
}

// Sin sesión: página simple, sin menú.
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Política de privacidad · Gestión ganadera</title>
<?= enlaces_tipografia() ?>
<link rel="stylesheet" href="<?= h(recurso('estilos.css')) ?>">
</head>
<body>
<main style="max-width: 760px; margin: 0 auto; padding: 32px 20px">
  <p><a href="<?= h(url('/ingresar')) ?>">← Volver al ingreso</a></p>
  <h1 style="margin-bottom: 4px">Política de privacidad</h1>
  <p class="secundario" style="margin-bottom: 24px">Gestión ganadera · Ley 21.719 de protección de datos personales</p>
  <?php $cuerpo(); ?>
</main>
</body>
</html>
