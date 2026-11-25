<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vistas/layout.php';
require_once __DIR__ . '/../dominio/mva.php';

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

    /* Registro MVA (Médico Veterinario Autorizado ante el SAG).
       Lo declara el administrador para su organización. Como el resto de los
       cambios de acceso, nadie se lo carga a sí mismo: quien valida tratamientos
       no puede ser también quien se dio la autorización. Dejar los tres campos
       vacíos quita el registro; las validaciones ya hechas no cambian, porque
       guardaron su propia copia del RUT y del número. */
    if ($accion === 'mva') {
        $usuario_id = entrada_entera('usuario_id') ?? 0;
        $miembro = fila(
            'SELECT u.nombre, u.apellido, m.mva_numero FROM membresias m JOIN usuarios u ON u.id = m.usuario_id
              WHERE m.organizacion_id = ? AND m.usuario_id = ?',
            [$org, $usuario_id],
        );
        if ($miembro === null) {
            flash('error', 'Esa persona no trabaja en esta organización.');
            redirigir($volver);
        }
        if ($usuario_id === $yo) {
            flash('error', 'No podés cargar tu propio registro MVA: pedíselo a otro administrador.');
            redirigir($volver);
        }
        $nombre_miembro = $miembro['nombre'] . ' ' . $miembro['apellido'];

        $quitar = entrada('mva_rut') === '' && entrada('mva_numero') === '' && entrada('mva_vigente_hasta') === '';
        $rut = null;
        $numero = null;
        $vigencia = null;
        if (!$quitar) {
            $errores_mva = [];
            $rut = normalizar_rut(entrada('mva_rut'));
            if ($rut === null) {
                $errores_mva[] = 'El RUT no es válido: revisá el dígito verificador.';
            }
            $numero = entrada('mva_numero');
            if ($numero === '' || mb_strlen($numero) > 30) {
                $errores_mva[] = 'Escribí el número de registro MVA (hasta 30 caracteres).';
            }
            $vigencia = entrada('mva_vigente_hasta');
            $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $vigencia);
            if ($fecha === false || $fecha->format('Y-m-d') !== $vigencia) {
                $errores_mva[] = 'Indicá hasta qué fecha está vigente el registro.';
            }
            if ($errores_mva !== []) {
                flash('error', 'Registro MVA de ' . $nombre_miembro . ': ' . implode(' ', $errores_mva));
                redirigir($volver);
            }
        } elseif ($miembro['mva_numero'] === null) {
            flash('error', $nombre_miembro . ' no tenía registro MVA cargado.');
            redirigir($volver);
        }

        en_transaccion(static function () use ($org, $usuario_id, $rut, $numero, $vigencia, $yo, $nombre_miembro, $quitar): void {
            consultar(
                'UPDATE membresias SET mva_rut = ?, mva_numero = ?, mva_vigente_hasta = ?
                  WHERE organizacion_id = ? AND usuario_id = ?',
                [$rut, $numero, $vigencia, $org, $usuario_id],
            );
            registrar_bitacora(
                $org, $yo, $quitar ? 'QUITA_MVA' : 'ACTUALIZA_MVA', 'membresias', $usuario_id,
                $quitar
                    ? $nombre_miembro
                    : $nombre_miembro . ': MVA N° ' . $numero . ' · RUT ' . rut_legible($rut) . ' · vigente hasta ' . fecha_corta($vigencia),
            );
        });

        flash('exito', $quitar
            ? $nombre_miembro . ' ya no tiene registro MVA: no va a poder validar tratamientos.'
            : 'Registro MVA de ' . $nombre_miembro . ' guardado, vigente hasta el ' . fecha_corta($vigencia) . '.');
        redirigir($volver);
    }

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
            m.rol, m.activa AS membresia_activa, m.mva_rut, m.mva_numero, m.mva_vigente_hasta,
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
    ...(GESTION_AVANZADA ? [
        'Registro MVA' => 'Médico Veterinario Autorizado ante el SAG. Solo quien tiene un registro MVA vigente puede validar tratamientos, y anular uno ya validado.',
    ] : []),
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

<?php
/* Registro MVA: lo tienen los veterinarios y, si corresponde, algún
   administrador que también lo sea. */
$con_mva = array_values(array_filter(
    $miembros,
    static fn (array $m): bool => $m['rol'] === 'VETERINARIO' || $m['mva_numero'] !== null,
));
?>
<?php if (GESTION_AVANZADA && $con_mva !== []): ?>
<div class="bloque">
  <header>
    <div>
      <h2>Registro MVA</h2>
      <div class="sub">Médicos veterinarios autorizados ante el SAG: son los que pueden validar tratamientos</div>
    </div>
  </header>
  <div class="tabla-scroll">
    <table>
      <thead>
        <tr><th>Veterinario</th><th>Registro actual</th><th>Actualizar (RUT · N° MVA · vigente hasta)</th></tr>
      </thead>
      <tbody>
      <?php foreach ($con_mva as $miembro): ?>
        <?php $vigente = mva_vigente($miembro, hoy()); ?>
        <tr>
          <td><span class="principal"><?= h($miembro['nombre'] . ' ' . $miembro['apellido']) ?></span></td>
          <td>
            <?php if ($miembro['mva_numero'] === null): ?>
              <?= insignia('Sin registro', 'neutro') ?>
            <?php else: ?>
              <?= $vigente ? insignia('Vigente', 'ok') : insignia('Vencido', 'riesgo') ?>
              <div class="secundario">
                N° <?= h($miembro['mva_numero']) ?> · RUT <?= h(rut_legible($miembro['mva_rut'])) ?>
                · hasta <?= fecha_corta($miembro['mva_vigente_hasta']) ?>
              </div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ((int) $miembro['id'] === (int) usuario_actual()['id']): ?>
              <div class="secundario">Tu registro lo carga otro administrador</div>
            <?php else: ?>
              <form method="post" action="<?= h(url('/usuarios')) ?>" class="acciones-fila">
                <input type="hidden" name="csrf" value="<?= h(token_csrf()) ?>">
                <input type="hidden" name="accion" value="mva">
                <input type="hidden" name="usuario_id" value="<?= (int) $miembro['id'] ?>">
                <input type="text" name="mva_rut" value="<?= h($miembro['mva_rut'] !== null ? rut_legible($miembro['mva_rut']) : '') ?>"
                       placeholder="12.345.678-9" aria-label="RUT de <?= h($miembro['nombre']) ?>" size="12">
                <input type="text" name="mva_numero" value="<?= h($miembro['mva_numero'] ?? '') ?>"
                       placeholder="N° MVA" maxlength="30" aria-label="Número MVA de <?= h($miembro['nombre']) ?>" size="10">
                <input type="date" name="mva_vigente_hasta" value="<?= h(substr((string) $miembro['mva_vigente_hasta'], 0, 10)) ?>"
                       aria-label="Vigencia del registro de <?= h($miembro['nombre']) ?>">
                <button class="boton menudo" type="submit">Guardar</button>
              </form>
              <div class="secundario">Dejá los tres campos vacíos para quitar el registro</div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php cerrar_pagina(); ?>
