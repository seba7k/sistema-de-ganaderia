<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../formato.php';
require_once __DIR__ . '/../vistas/layout.php';

$error = null;
$organizaciones = [];
$email = '';

/* Primera instalación sin datos de demostración: la base no tiene ningún
   usuario, así que nadie podría ingresar. Esta pantalla crea la organización y
   su administrador, y desaparece apenas existe una cuenta. */
$instalacion = !APP_DEMO && (int) valor('SELECT COUNT(*) FROM usuarios') === 0;
$errores_instalacion = [];
$previo = [];

if ($instalacion && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['organizacion', 'nombre', 'apellido', 'email'] as $campo) {
        $previo[$campo] = is_string($_POST[$campo] ?? null) ? trim($_POST[$campo]) : '';
    }
    $clave = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if (!verificar_csrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
        $errores_instalacion[] = 'La página estuvo abierta demasiado tiempo. Volvé a intentar.';
    }
    foreach ([
        'organizacion' => 'El nombre del campo o empresa',
        'nombre'       => 'Tu nombre',
        'apellido'     => 'Tu apellido',
        'email'        => 'El correo',
    ] as $campo => $etiqueta) {
        if ($previo[$campo] === '') {
            $errores_instalacion[] = $etiqueta . ' es obligatorio.';
        }
    }
    if ($previo['email'] !== '' && filter_var($previo['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errores_instalacion[] = 'El correo no tiene un formato válido.';
    }
    if (mb_strlen($clave) < 10) {
        $errores_instalacion[] = 'La contraseña tiene que tener al menos 10 caracteres.';
    } elseif ($clave !== ($_POST['password_repetida'] ?? null)) {
        $errores_instalacion[] = 'Las contraseñas no coinciden.';
    }

    if ($errores_instalacion === []) {
        en_transaccion(static function () use ($previo, $clave): void {
            // Se vuelve a contar ya adentro: si dos personas abren el sitio recién
            // subido a la vez, solo la primera crea el administrador.
            if ((int) valor('SELECT COUNT(*) FROM usuarios') > 0) {
                return;
            }
            consultar('INSERT INTO organizaciones (nombre) VALUES (?)', [$previo['organizacion']]);
            $organizacion_id = ultimo_id();
            consultar(
                'INSERT INTO establecimientos (organizacion_id, nombre) VALUES (?, ?)',
                [$organizacion_id, $previo['organizacion']],
            );
            consultar(
                'INSERT INTO usuarios (email, password_hash, nombre, apellido) VALUES (?, ?, ?, ?)',
                [mb_strtolower($previo['email']), hash_password($clave), $previo['nombre'], $previo['apellido']],
            );
            consultar(
                'INSERT INTO membresias (organizacion_id, usuario_id, rol) VALUES (?, ?, ?)',
                [$organizacion_id, ultimo_id(), 'ADMINISTRADOR'],
            );
        });

        $resultado = intentar_login($previo['email'], $clave);
        if ($resultado['ok'] ?? false) {
            header('Location: ' . url('/'));
            exit;
        }
        $errores_instalacion[] = 'Ya se había creado una cuenta: ingresá con ella.';
    }
}

if (!$instalacion && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = (string) ($_POST['email'] ?? '');
    $organizacion = isset($_POST['organizacion_id']) && $_POST['organizacion_id'] !== ''
        ? (int) $_POST['organizacion_id']
        : null;

    // Sin un token válido no se procesa: evita que otro sitio envíe este
    // formulario en nombre de quien tenga la página abierta.
    $resultado = verificar_csrf(isset($_POST['csrf']) ? (string) $_POST['csrf'] : null)
        ? intentar_login($email, (string) ($_POST['password'] ?? ''), $organizacion)
        : ['ok' => false, 'error' => 'La página estuvo abierta demasiado tiempo. Volvé a intentar.'];

    if ($resultado['ok'] ?? false) {
        header('Location: ' . url('/'));
        exit;
    }
    if (isset($resultado['organizaciones'])) {
        // Pertenece a varias: falta saber con cuál va a trabajar.
        $organizaciones = $resultado['organizaciones'];
        $error = 'Trabajás en más de una organización. Elegí una para continuar.';
    } else {
        $error = $resultado['error'];
    }
}

/* Aviso de cierre por inactividad: llega marcado en la sesión (lo detectó el
   servidor) o en la dirección (lo disparó el temporizador de la página). */
if ($error === null && (sesion_cerrada_por_inactividad() || isset($_GET['inactividad']))) {
    $error = 'Tu sesión se cerró después de ' . MINUTOS_INACTIVIDAD . ' minutos sin actividad. Volvé a ingresar.';
}

$cuentas = [
    ['admin@ganaderia.cl', 'Administrador (dos campos)'],
    ['vet@ganaderia.cl', 'Médico veterinario'],
    ['operario@ganaderia.cl', 'Operario de manga'],
    ['tractor@ganaderia.cl', 'Operario (alimentación)'],
];
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#183a26">
<title>Ingresar · Gestión ganadera</title>
<?= enlaces_tipografia() ?>
<link rel="stylesheet" href="<?= h(recurso('estilos.css')) ?>">
<script src="<?= h(recurso('app.js')) ?>" defer></script>
</head>
<body>
<div class="escena-ingreso">
  <aside class="ingreso-marca">
    <div class="firma"><?= marca(30) ?> Gestión ganadera</div>

    <div class="caravana-grande" aria-hidden="true">
      <svg viewBox="0 0 200 230">
        <path d="M78 6h44c6 0 10 4 10 10v26l40 30c6 4 9 11 9 18v118c0 10-8 18-18 18H23c-10 0-18-8-18-18V90c0-7 3-14 9-18l40-30V16c0-6 4-10 10-10z" fill="currentColor"/>
        <circle cx="100" cy="30" r="11" fill="#183a26"/>
        <path d="M5 96h190" stroke="#183a26" stroke-opacity=".12" stroke-width="2"/>
      </svg>
      <div class="impreso">
        <strong>Gestión<br>ganadera</strong>
        <span><?= GESTION_AVANZADA ? 'FEEDLOT Y CAMPO' : 'CICLO PRODUCTIVO' ?></span>
      </div>
    </div>

    <p class="bajada">
      <?= GESTION_AVANZADA
          ? '<strong>Cada animal con su caravana, su peso y su historia.</strong> Hacienda, alimentación, sanidad y cierres por lote en un solo lugar.'
          : '<strong>Cada animal con su caravana, su peso y su historia.</strong> Hacienda, ciclo reproductivo y alimentación, con o sin señal en el campo.' ?>
    </p>
  </aside>

  <main class="ingreso-formulario">
  <?php if ($instalacion): ?>
  <form class="tarjeta-ingreso" method="post" action="<?= h(url('/ingresar')) ?>">
    <input type="hidden" name="csrf" value="<?= h(token_csrf()) ?>">
    <h1>Primera configuración</h1>
    <p class="lema">Creá el campo y la cuenta de administrador. Después vas a poder dar acceso al resto del equipo.</p>

    <?php if ($errores_instalacion !== []): ?>
      <div class="mensaje error">
        <span class="marca-aviso">!</span>
        <div><?= h(implode(' ', $errores_instalacion)) ?></div>
      </div>
    <?php endif; ?>

    <div class="campo">
      <label for="organizacion">Campo o empresa</label>
      <input id="organizacion" name="organizacion" value="<?= h($previo['organizacion'] ?? '') ?>" placeholder="Ej: Agrícola Los Aromos" required>
    </div>
    <div class="campo">
      <label for="nombre">Nombre</label>
      <input id="nombre" name="nombre" value="<?= h($previo['nombre'] ?? '') ?>" required>
    </div>
    <div class="campo">
      <label for="apellido">Apellido</label>
      <input id="apellido" name="apellido" value="<?= h($previo['apellido'] ?? '') ?>" required>
    </div>
    <div class="campo">
      <label for="email">Correo</label>
      <input type="email" id="email" name="email" value="<?= h($previo['email'] ?? '') ?>" placeholder="nombre@campo.cl" required>
    </div>
    <div class="campo">
      <label for="password">Contraseña</label>
      <input type="password" id="password" name="password" minlength="10" autocomplete="new-password" required>
    </div>
    <div class="campo">
      <label for="password_repetida">Repetir contraseña</label>
      <input type="password" id="password_repetida" name="password_repetida" minlength="10" autocomplete="new-password" required>
    </div>

    <button class="boton" type="submit">Crear y entrar</button>

    <div class="credito-ingreso">
      Gestión ganadera <?= VERSION_APP ?>
    </div>
  </form>
  <?php else: ?>
  <form class="tarjeta-ingreso" method="post" action="<?= h(url('/ingresar')) ?>">
    <input type="hidden" name="csrf" value="<?= h(token_csrf()) ?>">
    <h1>Ingresar</h1>
    <p class="lema">Usá el correo y la contraseña de tu cuenta.</p>

    <?php if ($error !== null): ?>
      <div class="mensaje error">
        <span class="marca-aviso">!</span>
        <div><?= h($error) ?></div>
      </div>
    <?php endif; ?>

    <div class="campo">
      <label for="email">Correo</label>
      <input type="email" id="email" name="email" value="<?= h($email) ?>"
             placeholder="nombre@campo.cl" autocomplete="username" required autofocus>
    </div>

    <div class="campo">
      <label for="password">Contraseña</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>
    </div>

    <?php if ($organizaciones !== []): ?>
      <div class="campo">
        <label for="organizacion_id">Organización</label>
        <select id="organizacion_id" name="organizacion_id" required>
          <?php foreach ($organizaciones as $organizacion): ?>
            <option value="<?= (int) $organizacion['organizacion_id'] ?>">
              <?= h($organizacion['nombre']) ?> — <?= h(nombre_rol($organizacion['rol'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <button class="boton" type="submit">Entrar</button>

    <?php if (APP_DEMO): ?>
    <div class="cuentas-demo">
      <div class="titulo-demo">Cuentas de prueba, todas con la contraseña <code>demo1234</code>. Tocá una para completar el formulario.</div>
      <div class="lista">
      <?php foreach ($cuentas as [$correo, $descripcion]): ?>
        <button type="button" class="fila" data-cuenta="<?= h($correo) ?>">
          <code><?= h($correo) ?></code>
          <span><?= h($descripcion) ?></span>
        </button>
      <?php endforeach; ?>
      </div>
    </div>

    <?php endif; ?>

    <div class="credito-ingreso">
      Gestión ganadera <?= VERSION_APP ?>. Proyecto de título, <?= date('Y') ?>.
      · <a href="<?= h(url('/privacidad')) ?>">Política de privacidad</a>
    </div>
  </form>
  <?php endif; ?>
  </main>
</div>
</body>
</html>
