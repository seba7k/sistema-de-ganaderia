<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/formato.php';
require_once __DIR__ . '/operaciones.php';

/**
 * Infraestructura común de los formularios de carga.
 *
 * Tres cosas que tienen que valer para todos, y por eso viven acá y no en cada
 * pantalla:
 *
 * 1. Todo POST exige token anti-CSRF. Sin esto, otro sitio podría hacer que
 *    alguien con sesión abierta registre datos sin darse cuenta.
 * 2. Después de guardar se redirige (patrón POST-Redirect-GET). Si no, recargar
 *    la página vuelve a enviar el formulario y duplica el registro — en un
 *    sistema donde se cargan tratamientos y partos, eso es grave.
 * 3. Cuando la validación falla, los datos escritos vuelven al formulario. Nadie
 *    debería tener que tipear de nuevo veinte animales porque se equivocó en una
 *    fecha.
 */

/** Guarda un mensaje para mostrarlo después de la redirección. */
function flash(string $tipo, string $mensaje): void
{
    iniciar_sesion_php();
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

/** Devuelve el mensaje pendiente, ya renderizado, y lo consume. */
function mensaje_flash(): string
{
    iniciar_sesion_php();
    if (!isset($_SESSION['flash'])) {
        return '';
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    $clase = $flash['tipo'] === 'error' ? 'error' : 'exito';
    $marca = $flash['tipo'] === 'error' ? '!' : '✓';

    return '<div class="mensaje ' . $clase . '"><span class="marca-aviso">' . $marca . '</span>'
        . '<div>' . h($flash['mensaje']) . '</div></div>';
}

/**
 * Guarda los errores y lo que se había escrito, para repoblar el formulario.
 *
 * @param array<string, string> $errores  campo => mensaje
 * @param array<string, mixed>  $valores
 */
function recordar_formulario(array $errores, array $valores): void
{
    iniciar_sesion_php();
    $_SESSION['errores'] = $errores;
    // La contraseña nunca vuelve al formulario.
    unset($valores['password'], $valores['csrf']);
    $_SESSION['valores'] = $valores;
}

/** Errores del último intento. Se consumen al leerlos. */
function errores_formulario(): array
{
    iniciar_sesion_php();
    $errores = $_SESSION['errores'] ?? [];
    unset($_SESSION['errores']);
    return $errores;
}

/** Valor que se había escrito en un campo, o el que se pase por defecto. */
function valor_previo(string $campo, string $defecto = ''): string
{
    iniciar_sesion_php();
    $valores = $_SESSION['valores'] ?? [];
    return isset($valores[$campo]) ? (string) $valores[$campo] : $defecto;
}

/** Limpia los valores recordados: se llama al terminar de dibujar el formulario. */
function olvidar_formulario(): void
{
    iniciar_sesion_php();
    unset($_SESSION['valores']);
}

/**
 * Verifica el token y el permiso antes de procesar un POST.
 * Corta la ejecución con un mensaje si algo no cuadra.
 */
function exigir_post_valido(string $permiso, string $volver_a): void
{
    if (!verificar_csrf(isset($_POST['csrf']) ? (string) $_POST['csrf'] : null)) {
        flash('error', 'La página estuvo abierta demasiado tiempo. Volvé a intentarlo.');
        redirigir($volver_a);
    }
    // Se verifica acá aunque el formulario ni se dibuje para quien no tiene el
    // permiso: esconder un botón no impide enviar la petición igual.
    if (!puede($permiso)) {
        flash('error', 'Tu rol no permite registrar esto.');
        redirigir($volver_a);
    }
}

/**
 * Vuelve al formulario con los errores de una operación compartida.
 *
 * Si el dato es raro pero posible (un peso que bajó), el formulario vuelve con
 * la casilla de confirmación a la vista: se guarda recién cuando alguien la marca.
 */
function volver_con_errores(ErrorOperacion $error, string $destino): never
{
    $valores = $_POST;
    if ($error->requiere_confirmacion) {
        $valores['requiere_confirmacion'] = '1';
    }
    recordar_formulario($error->errores, $valores);
    flash(
        'error',
        ($error->requiere_confirmacion ? 'Confirmá el dato antes de guardarlo: ' : 'Revisá los campos marcados: ')
        . implode(' ', $error->errores),
    );
    redirigir($destino);
}

/**
 * Botón para anular un registro ya cargado.
 *
 * El motivo lo pide el navegador antes de enviar (app.js). Si alguien manda el
 * formulario sin motivo —sin JavaScript, o a mano—, la operación lo rechaza: un
 * registro anulado sin explicación no sirve para reconstruir nada después.
 */
function boton_anular(string $destino, string $tipo, int $id, string $texto = 'Anular'): string
{
    return '<form method="post" action="' . h($destino) . '" class="anular">'
        . '<input type="hidden" name="csrf" value="' . h(token_csrf()) . '">'
        . '<input type="hidden" name="accion" value="anular">'
        . '<input type="hidden" name="tipo" value="' . h($tipo) . '">'
        . '<input type="hidden" name="registro_id" value="' . $id . '">'
        . '<input type="hidden" name="motivo" value="">'
        . '<button type="submit" class="boton menudo peligro">' . h($texto) . '</button>'
        . '</form>';
}

/** Redirige y termina. Todo guardado exitoso pasa por acá. */
function redirigir(string $destino): never
{
    header('Location: ' . $destino);
    exit;
}

/* ============================================================
   Lectura y validación de los datos que llegan
   ============================================================ */

function entrada(string $campo): string
{
    return trim((string) ($_POST[$campo] ?? ''));
}

function entrada_entera(string $campo): ?int
{
    $valor = entrada($campo);
    return $valor === '' ? null : (int) $valor;
}

function entrada_decimal(string $campo): ?float
{
    $valor = str_replace(',', '.', entrada($campo));
    return $valor === '' ? null : (float) $valor;
}

/**
 * Acumulador de errores de validación.
 *
 * Se devuelven todos juntos y no de a uno: quien carga datos en el campo no
 * debería descubrir un error nuevo en cada intento.
 */
class Validacion
{
    /** @var array<string, string> */
    private array $errores = [];

    public function obligatorio(string $campo, string $etiqueta): self
    {
        if (entrada($campo) === '') {
            $this->errores[$campo] = $etiqueta . ' es obligatorio.';
        }
        return $this;
    }

    public function fecha(string $campo, string $etiqueta, bool $futuro_permitido = false): self
    {
        $valor = entrada($campo);
        if ($valor === '' || isset($this->errores[$campo])) {
            return $this;
        }
        /* El '!' pone en cero la hora y la comparación de vuelta descarta las
           fechas que no existen: sin eso, PHP convierte el 31 de febrero en el 3
           de marzo y se guarda un día que nadie escribió. */
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            $this->errores[$campo] = $etiqueta . ' no es una fecha válida.';
        } elseif (!$futuro_permitido && $fecha->format('Y-m-d') > hoy()) {
            $this->errores[$campo] = $etiqueta . ' no puede ser futura.';
        }
        return $this;
    }

    public function numero(string $campo, string $etiqueta, float $minimo = 0, ?float $maximo = null): self
    {
        $valor = entrada($campo);
        if ($valor === '' || isset($this->errores[$campo])) {
            return $this;
        }
        $numero = entrada_decimal($campo);
        if ($numero === null || !is_numeric(str_replace(',', '.', $valor))) {
            $this->errores[$campo] = $etiqueta . ' tiene que ser un número.';
        } elseif ($numero < $minimo) {
            $this->errores[$campo] = $etiqueta . ' no puede ser menor que ' . numero($minimo, 1) . '.';
        } elseif ($maximo !== null && $numero > $maximo) {
            $this->errores[$campo] = $etiqueta . ' no puede superar ' . numero($maximo, 1) . '.';
        }
        return $this;
    }

    /** Agrega un error que no viene de la forma del dato sino de una regla de negocio. */
    public function agregar(string $campo, string $mensaje): self
    {
        $this->errores[$campo] = $mensaje;
        return $this;
    }

    public function hay_errores(): bool
    {
        return $this->errores !== [];
    }

    /** @return array<string, string> */
    public function errores(): array
    {
        return $this->errores;
    }

    /**
     * Si hubo errores, recuerda todo y vuelve a la pantalla.
     * Si no, sigue de largo y el llamador guarda.
     */
    public function o_volver(string $destino): void
    {
        if (!$this->hay_errores()) {
            return;
        }
        recordar_formulario($this->errores, $_POST);
        flash('error', 'Revisá los campos marcados: ' . implode(' ', $this->errores));
        redirigir($destino);
    }
}

/* ============================================================
   Dibujo de los campos
   ============================================================ */

/** @param array<string, string> $errores */
function campo(
    string $nombre,
    string $etiqueta,
    array $errores = [],
    string $tipo = 'text',
    string $defecto = '',
    array $opciones = [],
): string {
    $error = $errores[$nombre] ?? null;
    $valor = valor_previo($nombre, $defecto);
    $id = 'c_' . $nombre;

    $html = '<div class="campo' . ($error !== null ? ' con-error' : '') . '">'
        . '<label for="' . h($id) . '">' . h($etiqueta)
        . (isset($opciones['obligatorio']) ? ' <span class="requerido">*</span>' : '')
        . '</label>';

    $extra = '';
    foreach (['min', 'max', 'step', 'placeholder', 'maxlength'] as $atributo) {
        if (isset($opciones[$atributo])) {
            $extra .= ' ' . $atributo . '="' . h((string) $opciones[$atributo]) . '"';
        }
    }
    if (isset($opciones['obligatorio'])) {
        $extra .= ' required';
    }

    if ($tipo === 'select') {
        $html .= '<select id="' . h($id) . '" name="' . h($nombre) . '"' . $extra . '>';
        foreach ($opciones['items'] ?? [] as $clave => $texto) {
            $html .= '<option value="' . h((string) $clave) . '"'
                . ((string) $clave === $valor ? ' selected' : '') . '>' . h((string) $texto) . '</option>';
        }
        $html .= '</select>';
    } elseif ($tipo === 'textarea') {
        $html .= '<textarea id="' . h($id) . '" name="' . h($nombre) . '" rows="2"' . $extra . '>'
            . h($valor) . '</textarea>';
    } else {
        $html .= '<input type="' . h($tipo) . '" id="' . h($id) . '" name="' . h($nombre) . '"'
            . ' value="' . h($valor) . '"' . $extra . '>';
    }

    if ($error !== null) {
        $html .= '<div class="error-campo">' . h($error) . '</div>';
    }
    if (isset($opciones['ayuda'])) {
        $html .= '<div class="ayuda-campo">' . h((string) $opciones['ayuda']) . '</div>';
    }

    return $html . '</div>';
}

/**
 * Bloque desplegable con el formulario de alta.
 *
 * Va cerrado salvo que la última vez haya fallado algo, en que se abre solo para
 * que el error quede a la vista.
 */
function abrir_alta(string $titulo, string $descripcion = '', bool $abierto = false): string
{
    return '<details class="alta"' . ($abierto ? ' open' : '') . '>'
        . '<summary><span class="mas">+</span> ' . h($titulo)
        . ($descripcion !== '' ? '<span class="detalle">' . h($descripcion) . '</span>' : '')
        . '</summary><div class="interior">';
}

function formulario(string $accion, string $contenido, string $texto_boton = 'Guardar'): string
{
    return '<form method="post" action="' . h($accion) . '" class="formulario">'
        . '<input type="hidden" name="csrf" value="' . h(token_csrf()) . '">'
        . $contenido
        . '<div class="acciones"><button class="boton" type="submit">' . h($texto_boton) . '</button></div>'
        . '</form>';
}

/**
 * Cierra el bloque. No borra los valores recordados porque una misma pantalla
 * puede tener varios formularios; eso lo hace `cerrar_pagina()` al final.
 */
function cerrar_alta(): string
{
    return '</div></details>';
}
