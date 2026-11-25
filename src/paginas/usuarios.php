<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vistas/layout.php';

exigir_permiso('gestionar_usuarios');

$org = organizacion_actual();
$volver = url('/usuarios');

/* ============================================================
   Dar acceso a una persona
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_post_valido('gestionar_usuarios', $volver);
    $accion = entrada('accion');
    $yo = (int) usuario_actual()['id'];

    /* Cambiar rol, quitar o devolver el acceso y reiniciar la contraseña.
       Tres reglas: nadie se toca a sí mismo (así nadie se deja afuera ni se sube
       el rol), siempre tiene que quedar un administrador activo, y cualquier
       cambio de acceso revoca las sesiones abiertas de la app de terreno, que
       si no seguirían sincronizando durante días. */
    if (in_array($accion, ['rol', 'acceso', 'clave'], true)) {
        $usuario_id = entrada_entera('usuario_id') ?? 0;

        $miembro = fila(
            'SELECT u.id, u.nombre, u.apellido, m.rol, m.activa
               FROM membresias m JOIN usuarios u ON u.id = m.usuario_id
              WHERE m.organizacion_id = ? AND m.usuario_id = ?',
            [$org, $usuario_id],
        );
        if ($miembro === null) {
            flash('error', 'Esa persona no trabaja en esta organización.');
            redirigir($volver);
        }
        if ($usuario_id === $yo) {
            flash('error', 'No podés cambiar tu propio acceso: pedíselo a otro administrador.');
            redirigir($volver);
        }

        $admins_activos = (int) valor(
            "SELECT COUNT(*) FROM membresias m JOIN usuarios u ON u.id = m.usuario_id
              WHERE m.organizacion_id = ? AND m.rol = 'ADMINISTRADOR' AND m.activa = 1 AND u.activo = 1",
            [$org],
        );
        $ultimo_admin = $miembro['rol'] === 'ADMINISTRADOR' && (int) $miembro['activa'] === 1 && $admins_activos <= 1;
        $nombre_miembro = $miembro['nombre'] . ' ' . $miembro['apellido'];

        if ($accion === 'rol') {
            $rol_nuevo = entrada('rol');
            if (!isset(ROLES[$rol_nuevo])) {
                flash('error', 'Ese rol no existe.');
                redirigir($volver);
            }
            if ($ultimo_admin && $rol_nuevo !== 'ADMINISTRADOR') {
                flash('error', 'Es el único administrador activo: nombrá a otro antes de cambiarle el rol.');
                redirigir($volver);
            }
            if ($rol_nuevo === $miembro['rol']) {
                flash('error', $nombre_miembro . ' ya tenía ese rol.');
                redirigir($volver);
            }

            en_transaccion(static function () use ($org, $usuario_id, $rol_nuevo, $yo, $miembro, $nombre_miembro): void {
                consultar(
                    'UPDATE membresias SET rol = ? WHERE organizacion_id = ? AND usuario_id = ?',
                    [$rol_nuevo, $org, $usuario_id],
                );
                consultar(
                    'UPDATE tokens_api SET revocado = 1 WHERE organizacion_id = ? AND usuario_id = ?',
                    [$org, $usuario_id],
                );
                registrar_bitacora(
                    $org, $yo, 'CAMBIA_ROL', 'membresias', $usuario_id,
                    $nombre_miembro . ': ' . nombre_rol($miembro['rol']) . ' → ' . nombre_rol($rol_nuevo),
                );
            });

            flash('exito', $nombre_miembro . ' ahora es ' . mb_strtolower(nombre_rol($rol_nuevo))
                . '. Si tenía la app abierta, va a tener que ingresar de nuevo.');
            redirigir($volver);
        }

        if ($accion === 'acceso') {
            $activar = (int) $miembro['activa'] !== 1;
            if (!$activar && $ultimo_admin) {
                flash('error', 'Es el único administrador activo: la organización quedaría sin quien la administre.');
                redirigir($volver);
            }

            en_transaccion(static function () use ($org, $usuario_id, $activar, $yo, $nombre_miembro): void {
                consultar(
                    'UPDATE membresias SET activa = ? WHERE organizacion_id = ? AND usuario_id = ?',
                    [$activar ? 1 : 0, $org, $usuario_id],
                );
                if (!$activar) {
                    consultar(
                        'UPDATE tokens_api SET revocado = 1 WHERE organizacion_id = ? AND usuario_id = ?',
                        [$org, $usuario_id],
                    );
                }
                registrar_bitacora(
                    $org, $yo, $activar ? 'ACTIVA_ACCESO' : 'DESACTIVA_ACCESO', 'membresias', $usuario_id,
                    $nombre_miembro,
                );
            });

            flash('exito', $activar
                ? $nombre_miembro . ' vuelve a tener acceso.'
                : $nombre_miembro . ' ya no puede entrar, ni desde la web ni desde la app.');
            redirigir($volver);
        }

        // Contraseña nueva: se muestra una sola vez y el administrador se la entrega.
        $temporal = generar_password_temporal();
        $hash = hash_password($temporal);
        $cambiada = en_transaccion(static function () use ($org, $usuario_id, $hash, $yo, $nombre_miembro): bool {
            $actualizada = consultar(
                'UPDATE usuarios SET password_hash = ? WHERE id = ?
                 AND NOT EXISTS (SELECT 1 FROM membresias WHERE usuario_id = ? AND organizacion_id <> ?)',
                [$hash, $usuario_id, $usuario_id, $org],
            );
            if ($actualizada->rowCount() !== 1) {
                return false;
            }
            consultar('UPDATE tokens_api SET revocado = 1 WHERE usuario_id = ?', [$usuario_id]);
            registrar_bitacora($org, $yo, 'REINICIA_CLAVE', 'usuarios', $usuario_id, $nombre_miembro);
            return true;
        });
        if (!$cambiada) {
            flash('error', 'Esta cuenta pertenece a varias organizaciones. La contraseña debe recuperarse a nivel de cuenta.');
            redirigir($volver);
        }

        flash('exito', 'Contraseña nueva de ' . $nombre_miembro . ': ' . $temporal
            . ' — anotala ahora, no se vuelve a mostrar.');
        redirigir($volver);
    }

    $validacion = (new Validacion())
        ->obligatorio('nombre', 'El nombre')
        ->obligatorio('apellido', 'El apellido')
        ->obligatorio('email', 'El email')
        ->obligatorio('rol', 'El rol');

    $email = mb_strtolower(entrada('email'));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $validacion->agregar('email', 'Ese email no tiene un formato válido.');
    }

    // Solo los tres roles del sistema: un valor inventado en el formulario
    // dejaría a la persona con un rol sin ningún permiso y sin explicación.
    $rol = entrada('rol');
    if ($rol !== '' && !isset(ROLES[$rol])) {
        $validacion->agregar('rol', 'Ese rol no existe.');
    }

    /* Se busca en toda la base y no solo en esta organización, porque una misma
       persona (el veterinario que atiende varios campos) usa una sola cuenta. Para
       que eso no sirva para averiguar qué correos están registrados en otras
       organizaciones, el nombre y el apellido tienen que coincidir con los de la
       cuenta: quien la conoce, ya sabe de quién es. */
    $existente = fila('SELECT * FROM usuarios WHERE email = ?', [$email]);
    if ($existente !== null) {
        $coincide = mb_strtolower($existente['nombre']) === mb_strtolower(entrada('nombre'))
            && mb_strtolower($existente['apellido']) === mb_strtolower(entrada('apellido'));
        if (!$coincide) {
            $validacion->agregar('email', 'Ese correo ya está en uso. Si es la misma persona, escribí su nombre y apellido tal como los tiene registrados.');
            $existente = null;
        }
    }
    if ($existente !== null) {
        $ya_esta = valor(
            'SELECT COUNT(*) FROM membresias WHERE organizacion_id = ? AND usuario_id = ?',
            [$org, (int) $existente['id']],
        );
        if ((int) $ya_esta > 0) {
            $validacion->agregar('email', 'Esa persona ya tiene acceso a esta organización.');
        }
    }

    $validacion->o_volver($volver);

    $temporal = null;

    db()->beginTransaction();
    try {
        if ($existente !== null) {
            // La persona ya usa el sistema en otro campo: se le suma el acceso,
            // no se le crea una cuenta nueva ni se le cambia la contraseña.
            $usuario_id = (int) $existente['id'];
        } else {
            $temporal = generar_password_temporal();
            consultar(
                'INSERT INTO usuarios (email, password_hash, nombre, apellido, telefono) VALUES (?, ?, ?, ?, ?)',
                [
                    $email, hash_password($temporal),
                    entrada('nombre'), entrada('apellido'),
                    entrada('telefono') !== '' ? entrada('telefono') : null,
                ],
            );
            $usuario_id = (int) db()->lastInsertId();
        }

        consultar(
            'INSERT INTO membresias (organizacion_id, usuario_id, rol) VALUES (?, ?, ?)',
            [$org, $usuario_id, $rol],
        );

        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }

    flash(
        'exito',
        $temporal !== null
            ? 'Cuenta creada para ' . entrada('nombre') . '. Contraseña temporal: ' . $temporal
              . ' — anotala ahora, no se vuelve a mostrar.'
            : entrada('nombre') . ' ya tenía cuenta en el sistema: se le dio acceso a esta organización con su contraseña de siempre.',
    );
    redirigir($volver);
}

/* Se consulta a través de membresías, no de la tabla de usuarios: `usuarios` no
   lleva organizacion_id (una persona puede trabajar en campos de varios dueños),
   así que consultarla directo devolvería gente de otras organizaciones. */
$miembros = filas(
    'SELECT u.id, u.email, u.nombre, u.apellido, u.telefono, u.activo, u.ultimo_acceso_en,
            m.rol, m.activa AS membresia_activa,
            (SELECT COUNT(*) FROM membresias m2 WHERE m2.usuario_id = u.id) AS organizaciones
       FROM membresias m
       JOIN usuarios u ON u.id = m.usuario_id
      WHERE m.organizacion_id = ?
      ORDER BY u.apellido, u.nombre',
    [$org],
);

$descripcion_rol = [
    'ADMINISTRADOR' => GESTION_AVANZADA ? 'Todo el sistema: ventas, lotes, bodega y usuarios' : 'Todo el sistema, incluidos predios y usuarios',
    'VETERINARIO'   => GESTION_AVANZADA ? 'Sanidad, reproducción, pesajes y consulta' : 'Reproducción, pesajes, notas y consulta',
    'OPERARIO'      => 'Pesajes, partos, alimentación y traslados',
];

abrir_pagina(
    'Usuarios',
    'Quién tiene acceso y qué puede hacer cada rol.',
    '/usuarios',
    ['Usuarios'],
);

echo ayuda_pantalla('¿Qué puede hacer cada rol?', [
    'Cambios de acceso' => 'Cambiar el rol, quitar el acceso o reiniciar la contraseña cierra las sesiones abiertas de esa persona en la app de terreno, y queda anotado en la bitácora. Nadie puede cambiar su propio acceso, y siempre tiene que quedar un administrador activo.',
    'Administrador' => GESTION_AVANZADA
        ? 'Ve y registra todo. Es el único que vende hacienda, cierra lotes, compra insumos, arma raciones y da acceso a otras personas. Recibe las alertas de descarte.'
        : 'Ve y registra todo. Es el único que da de alta animales, edita fichas, agrega predios y da acceso a otras personas. Recibe las alertas de descarte.',
    'Médico veterinario' => GESTION_AVANZADA
        ? 'Registra tratamientos, servicios, diagnósticos de preñez, partos y pesajes. Consulta hacienda, corrales, alimentación e insumos.'
        : 'Registra servicios, diagnósticos de preñez, partos, secados, pesajes y notas. Puede anular lo que se cargó mal.',
    'Operario de manga' => 'Registra pesajes, partos, entregas de alimento, traslados y bajas. No medica, no vende ni administra usuarios.',
]);

/* ---------- Formulario de carga ---------- */

$errores = errores_formulario();

$roles_disponibles = [];
foreach (ROLES as $rol_posible => $nombre_posible) {
    $roles_disponibles[$rol_posible] = $nombre_posible . ' — ' . $descripcion_rol[$rol_posible];
}

echo abrir_alta('Dar acceso a una persona', 'Crea la cuenta y su contraseña temporal', $errores !== []);
echo formulario(url('/usuarios'), implode('', [
    campo('nombre', 'Nombre', $errores, 'text', '', ['obligatorio' => true]),
    campo('apellido', 'Apellido', $errores, 'text', '', ['obligatorio' => true]),
    campo('email', 'Email', $errores, 'email', '', [
        'obligatorio' => true, 'placeholder' => 'nombre@campo.cl',
        'ayuda' => 'Si ya trabaja en otro campo del sistema, se le suma el acceso',
    ]),
    campo('telefono', 'Teléfono', $errores, 'text', '', ['placeholder' => '+56 9 ...']),
    campo('rol', 'Rol', $errores, 'select', 'OPERARIO', [
        'items' => $roles_disponibles, 'obligatorio' => true,
    ]),
]), 'Crear acceso');
echo cerrar_alta();
?>

<div class="bloque">
  <header>
    <div>
      <h2>Nómina</h2>
      <div class="sub"><?= numero(count($miembros)) ?> persona<?= count($miembros) === 1 ? '' : 's' ?> con acceso</div>
    </div>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead>
        <tr><th>Persona</th><th>Rol</th><th>Contacto</th><th>Último acceso</th><th>Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
      <?php foreach ($miembros as $miembro): ?>
        <tr>
          <td>
            <span class="principal"><?= h($miembro['nombre'] . ' ' . $miembro['apellido']) ?></span>
            <?php if ((int) $miembro['organizaciones'] > 1): ?>
              <div class="secundario">Trabaja en <?= (int) $miembro['organizaciones'] ?> organizaciones</div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ((int) $miembro['id'] === (int) usuario_actual()['id']): ?>
              <?= insignia(nombre_rol($miembro['rol']), 'neutro', false) ?>
              <div class="secundario">sos vos</div>
            <?php else: ?>
              <form method="post" action="<?= h(url('/usuarios')) ?>" class="acciones-fila">
                <input type="hidden" name="csrf" value="<?= h(token_csrf()) ?>">
                <input type="hidden" name="accion" value="rol">
                <input type="hidden" name="usuario_id" value="<?= (int) $miembro['id'] ?>">
                <select name="rol" aria-label="Rol de <?= h($miembro['nombre']) ?>">
                  <?php foreach (ROLES as $clave_rol => $nombre_rol_opcion): ?>
                    <option value="<?= h($clave_rol) ?>" <?= $miembro['rol'] === $clave_rol ? 'selected' : '' ?>>
                      <?= h($nombre_rol_opcion) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button class="boton menudo" type="submit">Cambiar</button>
              </form>
              <div class="secundario"><?= h($descripcion_rol[$miembro['rol']] ?? '') ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?= h($miembro['email']) ?>
            <div class="secundario"><?= h($miembro['telefono'] ?? '—') ?></div>
          </td>
          <td><?= $miembro['ultimo_acceso_en'] === null ? 'Nunca' : fecha_corta($miembro['ultimo_acceso_en']) ?></td>
          <td>
            <?php if ((int) $miembro['activo'] !== 1): ?>
              <?= insignia('Cuenta desactivada', 'riesgo') ?>
            <?php elseif ((int) $miembro['membresia_activa'] !== 1): ?>
              <?= insignia('Sin acceso', 'aviso') ?>
            <?php else: ?>
              <?= insignia('Activo', 'ok') ?>
            <?php endif; ?>
          </td>
          <td class="num">
            <?php if ((int) $miembro['id'] !== (int) usuario_actual()['id']): ?>
              <div class="acciones-fila">
                <?php foreach ([
                    ['acceso', (int) $miembro['membresia_activa'] === 1 ? 'Quitar acceso' : 'Devolver acceso', (int) $miembro['membresia_activa'] === 1 ? 'peligro' : ''],
                    ...((int) $miembro['organizaciones'] === 1 ? [['clave', 'Nueva contraseña', '']] : []),
                ] as [$accion_fila, $texto_fila, $tono_fila]): ?>
                  <form method="post" action="<?= h(url('/usuarios')) ?>">
                    <input type="hidden" name="csrf" value="<?= h(token_csrf()) ?>">
                    <input type="hidden" name="accion" value="<?= h($accion_fila) ?>">
                    <input type="hidden" name="usuario_id" value="<?= (int) $miembro['id'] ?>">
                    <button class="boton menudo <?= h($tono_fila) ?>" type="submit"><?= h($texto_fila) ?></button>
                  </form>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php cerrar_pagina(); ?>
