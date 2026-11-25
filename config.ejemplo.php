<?php
/**
 * Configuración de la instalación.
 *
 * Es el único archivo que hay que tocar al subir el sistema a un servidor.
 *
 * Para desarrollo local se puede crear config.local.php con solo las claves que
 * cambian: se combina con este archivo y nunca se sube al servidor.
 */

return [
    /*
     * Base de datos.
     *
     * En cPanel: «Bases de datos MySQL» → crear la base, crear el usuario,
     * agregar el usuario a la base con TODOS LOS PRIVILEGIOS, y copiar acá los
     * nombres tal como los muestra cPanel (llevan el prefijo de tu cuenta, por
     * ejemplo «rparracl_ganaderia»). Las tablas se crean solas en la primera visita.
     */
    'base_datos' => [
        'motor'   => 'sqlite',
        'host'    => 'localhost',
        'puerto'  => 3306,
        'nombre'  => 'TU_USUARIO_ganaderia',
        'usuario' => 'TU_USUARIO_ganaderia',
        'clave'   => '',

        // Solo si 'motor' es 'sqlite' (desarrollo sin servidor de base de datos).
        'archivo' => __DIR__ . '/datos/ganaderia.sqlite',
    ],

    // Carga datos de demostración la primera vez y muestra las cuentas de prueba
    // en la pantalla de ingreso. Poner en false antes de usarlo con datos reales.
    /*
     * Gestión avanzada.
     *
     * En false el sistema queda en el ciclo productivo: nacimientos, encastes,
     * partos, secados, pesajes, bajas, corrales, alimentación por lote y notas
     * sanitarias escritas a mano. Es lo que el productor usa todos los días.
     *
     * En true se suman los módulos de gestión: sanidad con productos, dosis y
     * carencia (con bloqueo de faena), bodega de insumos con descuento
     * automático, y lotes con su cierre económico. Sirven para un feedlot o
     * para quien lleve costos, y recargan el sistema para quien no.
     */
    'gestion_avanzada' => false,

    'demo' => true,

    /*
     * Formato del DIIO (la caravana oficial del SAG), como expresión regular.
     *
     * Vacío: se acepta cualquier DIIO, que es lo seguro mientras no se sepa
     * exactamente cómo vienen impresas las caravanas del campo. Con un formato,
     * el sistema rechaza los DIIO que no lo cumplan, en la web y en la app.
     * Antes de activarlo, comparalo con caravanas reales: un formato equivocado
     * deja animales sin poder cargarse.
     *
     * Ejemplo, solo números y de 9 dígitos: '/^\d{9}$/'
     */
    'formato_diio' => '',

    /*
     * Clave de las validaciones MVA.
     *
     * Cada tratamiento validado por un Médico Veterinario Autorizado guarda una
     * huella firmada con esta clave. Vacía: la primera validación crea sola
     * datos/clave-huella.php. Respaldala junto con la base: si se pierde o se
     * cambia, los tratamientos ya validados se ven como «Alterado».
     */
    'clave_huella' => '',

    // La plataforma web cierra la sesión tras este tiempo sin actividad.
    'minutos_inactividad' => 60,

    // Cuánto dura la sesión de la app de terreno, que tiene que funcionar días
    // sin señal. Se renueva cada vez que sincroniza.
    'dias_sesion_app' => 30,

    // Los días de carencia y los "últimos 30 días" dependen de qué día es hoy.
    'zona_horaria' => 'America/Santiago',

    // true solo mientras se diagnostica un problema: muestra los errores de PHP
    // en pantalla, con rutas del servidor incluidas.
    'mostrar_errores' => false,

    // false si el hosting no tiene mod_rewrite: las direcciones pasan de
    // /hacienda a index.php?r=/hacienda, y el sistema sigue funcionando igual.
    'url_amigables' => true,

    /*
     * Aviso automático de errores del servidor.
     *
     * Cuando algo falla (un error de PHP, la base que no responde, la app que no
     * logra sincronizar), el sistema lo anota en datos/errores.log y avisa por
     * los canales que tengan datos. Vacíos: solo se anota y se ve en
     * Gestión → Errores del servidor. El mismo error se avisa una vez por hora.
     *
     *  · correo: usa el correo del propio hosting (cPanel), no necesita Google.
     *    Conviene una casilla del mismo dominio para que no caiga en spam.
     *  · telegram: crear un bot con @BotFather (da el token) y mandarle un
     *    mensaje; el chat_id se ve en https://api.telegram.org/bot<TOKEN>/getUpdates
     *  · webhook: una URL https que reciba JSON (Slack, Discord, Make, etc.).
     *
     * Para cuando se cae el servidor entero (ahí nadie puede avisar desde
     * adentro): un monitor externo gratuito, como UptimeRobot, que revise
     * https://TU-DOMINIO/salud cada 5 minutos.
     */
    'alertas' => [
        'correo'         => '',
        'telegram_token' => '',
        'telegram_chat'  => '',
        'webhook'        => '',
        'nombre_sitio'   => 'Gestión ganadera',
    ],

    /*
     * Protección de datos personales (Ley 21.719).
     *
     * El sistema publica la política en /privacidad, pide a cada persona que la
     * lea al ingresar, y deja que cada uno descargue sus datos y haga
     * solicitudes en «Mis datos y privacidad». Ver docs/ley-21719.md.
     */
    'privacidad' => [
        // Correo para ejercer derechos. Vacío: se muestra el del administrador.
        'correo_contacto'  => '',
        // Días corridos para responder una solicitud. Confirmá el plazo vigente con tu asesor legal.
        'dias_respuesta'   => 30,
        // Días que se guarda la IP de cada acción en la bitácora.
        'dias_ip_bitacora' => 180,
    ],
];
