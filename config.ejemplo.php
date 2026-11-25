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
];
