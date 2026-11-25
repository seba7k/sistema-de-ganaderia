<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../formato.php';
require_once __DIR__ . '/../formulario.php';

const VERSION_APP = '3.2';

/**
 * Íconos de trazo. Reemplazan a los emojis, que cambian de dibujo y de tamaño
 * en cada sistema operativo y hacían ver el menú desparejo.
 */
function icono(string $nombre): string
{
    static $trazos = [
        'exportar'     => '<path d="M12 3v12"/><path d="m8 11 4 4 4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
        'panel'        => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'hacienda'     => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'corrales'     => '<path d="M4 4v17M20 4v17M12 4v17"/><path d="M4 9h16M4 15h16"/>',
        'alimentacion' => '<path d="M12 22V10"/><path d="M12 10c0-3 2-5.5 5-6.5 0 3-2 5.5-5 6.5z"/><path d="M12 10C12 7 10 4.5 7 3.5c0 3 2 5.5 5 6.5z"/><path d="M12 16c0-3 2-5.5 5-6.5 0 3-2 5.5-5 6.5z"/><path d="M12 16c0-3-2-5.5-5-6.5 0 3 2 5.5 5 6.5z"/>',
        'sanidad'      => '<path d="m18 2 4 4"/><path d="m17 7 3-3"/><path d="M19 9 8.7 19.3a2.4 2.4 0 0 1-3.4 0l-.6-.6a2.4 2.4 0 0 1 0-3.4L15 5"/><path d="m9 11 4 4"/><path d="m5 19-3 3"/><path d="m14 4 6 6"/>',
        'reproduccion' => '<path d="M19.5 12.6 12 20l-7.5-7.4A5 5 0 1 1 12 6.1a5 5 0 1 1 7.5 6.5z"/>',
        'salidas'      => '<path d="M3 12h13"/><path d="m12 7 5 5-5 5"/><path d="M20 4v16"/>',
        'lotes'        => '<path d="M3 3v18h18"/><path d="M8 16v-5"/><path d="M13 16V8"/><path d="M18 16V5"/>',
        'insumos'      => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/>',
        'usuarios'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
        'predios'      => '<path d="M3 21h18"/><path d="M5 21V9l7-5 7 5v12"/><path d="M10 21v-6h4v6"/>',
        'errores'      => '<path d="M12 3 2 21h20L12 3z"/><path d="M12 10v5"/><path d="M12 18h.01"/>',
        'privacidad'   => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6l-8-3z"/><path d="m9 12 2 2 4-4"/>',
        'bitacora'     => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><path d="M9 7h7"/><path d="M9 11h7"/>',
        'app'          => '<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/>',
        'ayuda'        => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 4.9.8c0 1.7-2.4 2.2-2.4 3.7"/><path d="M12 17h.01"/>',
        'menu'         => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($trazos[$nombre] ?? '') . '</svg>';
}

/**
 * Marca del sistema: una caravana de oreja, la identificación que lleva cada
 * animal. Se dibuja en SVG para que se vea igual en todos los sistemas.
 */
function marca(int $tamano = 22): string
{
    return '<svg class="caravana" viewBox="0 0 24 24" width="' . $tamano . '" height="' . $tamano . '" aria-hidden="true">'
        . '<path d="M9.2 1.8h5.6c.7 0 1.2.5 1.2 1.2v2.6l3.4 2.6c.5.4.8 1 .8 1.6v10c0 1.2-1 2.2-2.2 2.2H6c-1.2 0-2.2-1-2.2-2.2v-10c0-.6.3-1.2.8-1.6L8 5.6V3c0-.7.5-1.2 1.2-1.2z" fill="currentColor"/>'
        . '<circle cx="12" cy="4.4" r="1.25" fill="var(--hueco, #183a26)"/>'
        . '<path d="M7.6 13.2h8.8M7.6 16.8h5.6" stroke="var(--hueco, #183a26)" stroke-width="1.6" stroke-linecap="round" opacity=".55"/>'
        . '</svg>';
}

/**
 * Precarga de la tipografía. La fuente vive en /fuentes y no en un servicio
 * externo: la política de seguridad del sitio solo acepta recursos propios.
 */
function enlaces_tipografia(): string
{
    return '<link rel="preload" href="' . h(BASE_URL . '/fuentes/archivo-latin.woff2') . '" as="font" type="font/woff2" crossorigin>';
}

/**
 * Módulos del sistema.
 *
 * Una sola declaración alimenta cuatro cosas: el menú desplegable de la barra,
 * el menú lateral del teléfono, las tarjetas de la portada y el pie. Agregar un
 * módulo acá lo hace aparecer en los cuatro lugares, con el mismo texto y el
 * mismo permiso.
 */
function modulos(): array
{
    return [
        'Operación' => [
            [
                'ruta' => '/hacienda', 'icono' => icono('hacienda'), 'color' => '#15703a', 'nombre' => 'Hacienda',
                'detalle' => 'Inventario individual: DIIO, peso y ganancia diaria',
                'permiso' => 'ver_hacienda', 'rapido' => true,
            ],
            [
                'ruta' => '/corrales', 'icono' => icono('corrales'), 'color' => '#0e7470', 'nombre' => 'Corrales',
                'detalle' => 'Aforo, plazas libres, comedero y traslados',
                'permiso' => 'ver_corrales', 'rapido' => false,
            ],
            [
                'ruta' => '/alimentacion', 'icono' => icono('alimentacion'), 'color' => '#95650f', 'nombre' => 'Alimentación',
                'detalle' => GESTION_AVANZADA ? 'Raciones, entregas y descuento de bodega' : 'Alimento entregado por corral o lote',
                'permiso' => 'ver_alimentacion', 'rapido' => true,
            ],
            [
                'ruta' => '/sanidad', 'icono' => icono('sanidad'), 'color' => '#1f5f8b', 'nombre' => 'Sanidad',
                'detalle' => 'Tratamientos y períodos de carencia',
                'permiso' => 'ver_sanidad', 'rapido' => true, 'avanzado' => true,
            ],
            [
                'ruta' => '/reproduccion', 'icono' => icono('reproduccion'), 'color' => '#a3386b', 'nombre' => 'Reproducción',
                'detalle' => 'Servicios, partos, próximas pariciones y descartes',
                'permiso' => 'ver_reproduccion', 'rapido' => false,
            ],
        ],
        'Gestión' => [
            [
                'ruta' => '/salidas', 'icono' => icono('salidas'), 'color' => '#9a3412', 'nombre' => 'Salidas y FMA',
                'detalle' => 'Venta, faena o traslado con bloqueo sanitario y borrador del FMA',
                'permiso' => 'registrar_venta', 'rapido' => false,
            ],
            [
                'ruta' => '/lotes', 'icono' => icono('lotes'), 'color' => '#b4531a', 'nombre' => 'Lotes y cierres',
                'detalle' => 'Ventas y resultado económico por lote',
                'permiso' => 'gestionar_lotes', 'rapido' => false, 'avanzado' => true,
            ],
            [
                'ruta' => '/insumos', 'icono' => icono('insumos'), 'color' => '#5b4b9a', 'nombre' => 'Insumos',
                'detalle' => 'Bodega de alimento y productos veterinarios',
                'permiso' => 'ver_insumos', 'rapido' => false, 'avanzado' => true,
            ],
            [
                'ruta' => '/predios', 'icono' => icono('predios'), 'color' => '#0e7470', 'nombre' => 'Predios',
                'detalle' => 'Los campos del propietario y en cuál se está trabajando',
                'permiso' => 'gestionar_predios', 'rapido' => false,
            ],
            [
                'ruta' => '/exportar', 'icono' => icono('exportar'), 'color' => '#15703a', 'nombre' => 'Exportar',
                'detalle' => 'Planillas para abrir en Excel o entregar al SAG',
                'permiso' => 'ver_hacienda', 'rapido' => false,
            ],
            [
                'ruta' => '/bitacora', 'icono' => icono('bitacora'), 'color' => '#7a5c1e', 'nombre' => 'Bitácora',
                'detalle' => 'Qué se corrigió o se anuló, quién y cuándo',
                'permiso' => 'ver_bitacora', 'rapido' => false,
            ],
            [
                'ruta' => '/usuarios', 'icono' => icono('usuarios'), 'color' => '#475569', 'nombre' => 'Usuarios',
                'detalle' => 'Personas con acceso y sus roles',
                'permiso' => 'gestionar_usuarios', 'rapido' => false,
            ],
            [
                'ruta' => '/solicitudes-datos', 'icono' => icono('privacidad'), 'color' => '#334155', 'nombre' => 'Solicitudes de datos',
                'detalle' => 'Pedidos de acceso, corrección o supresión de datos personales',
                'permiso' => 'gestionar_usuarios', 'rapido' => false,
            ],
            [
                'ruta' => '/mis-datos', 'icono' => icono('privacidad'), 'color' => '#334155', 'nombre' => 'Mis datos y privacidad',
                'detalle' => 'Tus datos, la política de privacidad y tus derechos',
                'permiso' => 'ver_mis_datos', 'rapido' => false,
            ],
            [
                'ruta' => '/errores', 'icono' => icono('errores'), 'color' => '#9f1d1d', 'nombre' => 'Errores del servidor',
                'detalle' => 'Qué falló y a quién se avisa automáticamente',
                'permiso' => 'ver_errores', 'rapido' => false,
            ],
            [
                'ruta' => '/app/', 'icono' => icono('app'), 'color' => '#0f2417', 'nombre' => 'App de terreno',
                'detalle' => 'Manga y corral sin señal: se instala en el teléfono',
                'permiso' => 'usar_app', 'rapido' => false,
            ],
        ],
    ];
}

/**
 * Módulos del grupo que se muestran: los que el rol puede abrir y, si el módulo
 * es de gestión avanzada (sanidad con carencia, bodega, lotes), solo cuando esa
 * gestión está encendida en config.php.
 */
function modulos_visibles(string $grupo): array
{
    return array_values(array_filter(
        modulos()[$grupo] ?? [],
        static fn (array $modulo): bool => puede($modulo['permiso'])
            && (GESTION_AVANZADA || empty($modulo['avanzado'])),
    ));
}

/**
 * Dirección de un módulo. La app de terreno es una carpeta de archivos
 * estáticos y no una ruta del sistema: se enlaza directo, sin pasar por index.php.
 */
function url_modulo(string $ruta): string
{
    return str_starts_with($ruta, '/app/') ? BASE_URL . $ruta : url($ruta);
}

/**
 * Accesos de la barra inferior del teléfono: lo que se usa parado en el corral.
 * Son los módulos marcados como rápidos, más el panel.
 */
function accesos_rapidos(): array
{
    $rapidos = [['ruta' => '/', 'icono' => icono('panel'), 'color' => '#15703a', 'nombre' => 'Panel']];
    foreach (modulos() as $grupo => $_) {
        foreach (modulos_visibles($grupo) as $modulo) {
            if ($modulo['rapido']) {
                $rapidos[] = $modulo;
            }
        }
    }
    return $rapidos;
}

/**
 * Recuerda qué sección está abierta.
 *
 * La barra inferior del teléfono se dibuja al cerrar la página, cuando ya se
 * perdió el parámetro que recibió `abrir_pagina()`. Guardarlo acá evita tener
 * que pasar la ruta dos veces en cada una de las páginas.
 */
function ruta_activa_actual(?string $definir = null): string
{
    static $ruta = '';
    if ($definir !== null) {
        $ruta = $definir;
    }
    return $ruta;
}

function abrir_pagina(
    string $titulo,
    string $subtitulo = '',
    string $ruta_activa = '',
    array $migas = [],
): void {
    $usuario = usuario_actual();
    ruta_activa_actual($ruta_activa);
    ?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#183a26">
<title><?= h($titulo) ?> · Gestión ganadera</title>
<?= enlaces_tipografia() ?>
<link rel="stylesheet" href="<?= h(recurso('estilos.css')) ?>">
<script src="<?= h(recurso('app.js')) ?>" defer></script>
<script src="<?= h(recurso('app/lector.js')) ?>" defer></script>
</head>
<body data-csrf="<?= h(token_csrf()) ?>" data-inactividad="<?= MINUTOS_INACTIVIDAD ?>" data-salir="<?= h(url_con('/salir', ['inactividad' => '1'])) ?>" data-mantener="<?= h(url('/mantener-sesion')) ?>">

<a class="saltar" href="#contenido">Saltar al contenido</a>

<!-- Menú principal: barra lateral fija en escritorio, cajón deslizante en teléfono -->
<div class="velo" data-velo></div>
<aside class="cajon" id="cajon" data-cajon aria-label="Menú principal">
  <div class="cajon-cabecera">
    <a class="logo" href="<?= h(url('/')) ?>">
      <span class="sello"><?= marca(24) ?></span>
      <span class="texto">
        <strong>Gestión ganadera</strong>
        <span><?= h($usuario['organizacion'] ?? '') ?></span>
      </span>
    </a>
    <button class="cajon-cerrar" type="button" data-cerrar-cajon aria-label="Cerrar menú">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
    </button>
  </div>

  <nav class="cajon-nav">
    <a class="opcion <?= $ruta_activa === '/' ? 'activo' : '' ?>" href="<?= h(url('/')) ?>" <?= $ruta_activa === '/' ? 'aria-current="page"' : '' ?>>
      <span class="icono" style="--c: #1e7a45"><?= icono('panel') ?></span>
      <span>
        Panel
        <span class="detalle">Resumen del día y avisos</span>
      </span>
    </a>

    <?php if (count(predios_permitidos()) > 1): ?>
      <div class="cajon-predios">
        <div class="cajon-grupo">Predio</div>
        <?php foreach (predios_permitidos() as $predio): ?>
          <form method="post" action="<?= h(url('/predio')) ?>">
            <input type="hidden" name="csrf" value="<?= h(token_csrf()) ?>">
            <input type="hidden" name="id" value="<?= (int) $predio['id'] ?>">
            <input type="hidden" name="volver" value="<?= h($ruta_activa) ?>">
            <button type="submit" class="opcion <?= $predio['id'] === predio_actual() ? 'activo' : '' ?>">
              <span class="icono" style="--c: #0e7470"><?= icono('predios') ?></span>
              <span>
                <?= h($predio['nombre']) ?>
                <span class="detalle"><?= h(legible($predio['tipo'])) ?></span>
              </span>
            </button>
          </form>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php foreach (array_keys(modulos()) as $grupo): ?>
      <?php $visibles = modulos_visibles($grupo); ?>
      <?php if ($visibles === []) { continue; } ?>
      <div class="cajon-grupo"><?= h($grupo) ?></div>
      <?php foreach ($visibles as $modulo): ?>
        <a class="opcion <?= $ruta_activa === $modulo['ruta'] ? 'activo' : '' ?>" href="<?= h(url_modulo($modulo['ruta'])) ?>" <?= $ruta_activa === $modulo['ruta'] ? 'aria-current="page"' : '' ?>>
          <span class="icono" style="--c: <?= $modulo['color'] ?>"><?= $modulo['icono'] ?></span>
          <span>
            <?= h($modulo['nombre']) ?>
            <span class="detalle"><?= h($modulo['detalle']) ?></span>
          </span>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <div class="cajon-pie">
    <a class="cajon-salir" href="<?= h(url('/salir')) ?>">Cerrar sesión</a>
    <span class="version">Versión <?= VERSION_APP ?></span>
  </div>
</aside>

<div class="marco">
<header class="barra">
  <div class="barra-interior">
    <button class="hamburguesa" type="button" data-abrir-cajon aria-label="Abrir menú" aria-controls="cajon"><?= icono('menu') ?></button>

    <a class="logo logo-barra" href="<?= h(url('/')) ?>">
      <span class="sello"><?= marca(22) ?></span>
      <span class="texto"><strong>Gestión ganadera</strong></span>
    </a>

    <?php $predios_barra = predios_permitidos(); ?>
    <?php if (count($predios_barra) > 1): ?>
      <div class="selector-predio" data-desplegable>
        <button class="enlace" type="button" aria-haspopup="true">
          <?= icono('predios') ?>
          <span class="nombre"><?= h(nombre_predio()) ?></span>
          <span class="flecha" aria-hidden="true"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span>
        </button>
        <div class="desplegable">
          <div class="desplegable-titulo">Cambiar de predio</div>
          <?php foreach ($predios_barra as $predio): ?>
            <form method="post" action="<?= h(url('/predio')) ?>">
              <input type="hidden" name="csrf" value="<?= h(token_csrf()) ?>">
              <input type="hidden" name="id" value="<?= (int) $predio['id'] ?>">
              <input type="hidden" name="volver" value="<?= h($ruta_activa) ?>">
              <button type="submit" class="<?= $predio['id'] === predio_actual() ? 'activo' : '' ?>">
                <span class="icono" style="--c: #0e7470"><?= icono('predios') ?></span>
                <span>
                  <span class="titulo"><?= h($predio['nombre']) ?></span>
                  <span class="detalle"><?= h(legible($predio['tipo'])) ?></span>
                </span>
              </button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
    <?php else: ?>
      <span class="predio-fijo"><?= icono('predios') ?><?= h(nombre_predio()) ?></span>
    <?php endif; ?>

    <?php if (APP_DEMO): ?><span class="sello-demo" title="Datos de prueba y cuentas conocidas: no cargar datos reales">Demo</span><?php endif; ?>

    <div class="usuario-barra" data-usuario>
      <button class="boton-usuario" type="button" aria-label="Menú de la cuenta" aria-haspopup="true">
        <span class="avatar"><?= h(iniciales($usuario['nombre'], $usuario['apellido'])) ?></span>
        <span class="quien">
          <?= h($usuario['nombre']) ?>
          <small><?= h(nombre_rol($usuario['rol'])) ?></small>
        </span>
      </button>
      <div class="menu-usuario">
        <div class="cabecera">
          <strong><?= h($usuario['nombre'] . ' ' . $usuario['apellido']) ?></strong>
          <span><?= h($usuario['email']) ?></span>
          <span><?= h($usuario['organizacion']) ?>, <?= h(mb_strtolower(nombre_rol($usuario['rol']))) ?></span>
        </div>
        <a href="<?= h(url('/salir')) ?>">Cerrar sesión</a>
      </div>
    </div>
  </div>
</header>


<main class="contenido" id="contenido">
  <div class="limite">
    <div class="titulo-pagina">
      <?php if ($migas !== []): ?>
        <nav class="migas" aria-label="Ubicación">
          <a href="<?= h(url('/')) ?>">Panel</a>
          <?php foreach ($migas as $miga): ?><span class="separador" aria-hidden="true">/</span><span><?= h($miga) ?></span><?php endforeach; ?>
        </nav>
      <?php endif; ?>
      <h1><?= h($titulo) ?></h1>
      <?php if ($subtitulo !== ''): ?><p><?= h($subtitulo) ?></p><?php endif; ?>
    </div>

    <?php /* Resultado de lo último que se guardó, después de la redirección. */ ?>
    <?= mensaje_flash() ?>
<?php
}

function cerrar_pagina(string $ruta_activa = ''): void
{
    $ruta_activa = $ruta_activa !== '' ? $ruta_activa : ruta_activa_actual();
    // Los datos del último intento fallido ya se dibujaron: no deben reaparecer
    // en la próxima visita.
    olvidar_formulario();
    ?>
  </div>
</main>

<footer class="pie">
  <div class="pie-interior">
    <span>Gestión ganadera <?= VERSION_APP ?>, <?= GESTION_AVANZADA ? 'con control económico por lote' : 'ciclo productivo' ?></span>
    <span>Proyecto de título, <?= date('Y') ?></span>
  </div>
</footer>
</div>

<!-- Accesos de una mano, para usar el sistema parado en el corral -->
<nav class="nav-inferior" aria-label="Accesos rápidos">
  <div class="fila">
    <?php foreach (accesos_rapidos() as $acceso): ?>
      <a class="<?= $ruta_activa === $acceso['ruta'] ? 'activo' : '' ?>" href="<?= h(url_modulo($acceso['ruta'])) ?>">
        <span class="icono" style="--c: <?= $acceso['color'] ?>"><?= $acceso['icono'] ?></span>
        <?= h($acceso['nombre']) ?>
      </a>
    <?php endforeach; ?>
    <button type="button" data-abrir-cajon aria-label="Abrir menú completo">
      <span class="icono"><?= icono('menu') ?></span>
      Menú
    </button>
  </div>
</nav>

</body>
</html>
<?php
}

/** Insignia de estado. El tono comunica el nivel de atención que pide el dato. */
function insignia(string $texto, string $tono = 'neutro', bool $con_punto = true): string
{
    return '<span class="insignia ' . $tono . ($con_punto ? '' : ' simple') . '">' . h($texto) . '</span>';
}

/** Barra de progreso con su porcentaje al lado. */
function medidor(int $porcentaje): string
{
    $clase = $porcentaje > 100 ? 'excedido' : ($porcentaje >= 90 ? 'lleno' : '');
    return '<div class="medidor">'
        . '<span class="canal"><span class="relleno ' . $clase . '" style="width:' . min($porcentaje, 100) . '%"></span></span>'
        . '<span class="cifra">' . $porcentaje . '%</span>'
        . '</div>';
}

/**
 * Bloque desplegable que explica cómo se lee la pantalla.
 *
 * Va cerrado por defecto: quien ya conoce el sistema no lo abre nunca, y quien
 * llega por primera vez tiene la explicación a un clic en vez de en un manual.
 *
 * @param array<string, string> $terminos Término => explicación
 */
function ayuda_pantalla(string $titulo, array $terminos): string
{
    $html = '<details class="ayuda"><summary>' . icono('ayuda') . ' ' . h($titulo) . '</summary>'
        . '<div class="interior"><dl>';
    foreach ($terminos as $termino => $explicacion) {
        $html .= '<dt>' . h($termino) . '</dt><dd>' . h($explicacion) . '</dd>';
    }
    return $html . '</dl></div></details>';
}
