<?php
declare(strict_types=1);

require_once __DIR__ . '/arranque.php';

/** Escapa para HTML. Todo dato que llega a una vista pasa por acá. */
function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pesos(float|int|null $monto): string
{
    if ($monto === null) {
        return '—';
    }
    return '$' . number_format((float) $monto, 0, ',', '.');
}

function numero(float|int|null $valor, int $decimales = 0): string
{
    if ($valor === null) {
        return '—';
    }
    return number_format((float) $valor, $decimales, ',', '.');
}

function kg(float|int|null $valor, int $decimales = 0): string
{
    return $valor === null ? '—' : numero($valor, $decimales) . ' kg';
}

function fecha_corta(?string $fecha): string
{
    if ($fecha === null || $fecha === '') {
        return '—';
    }
    return (new DateTimeImmutable($fecha))->format('d/m/Y');
}

/** Nombre legible de un valor de enum (NOVILLO_CASTRADO → Novillo castrado). */
function legible(?string $valor): string
{
    if ($valor === null) {
        return '—';
    }
    $texto = ucfirst(mb_strtolower(str_replace('_', ' ', $valor)));

    /* Los valores se guardan sin tildes porque van en columnas de la base, pero
       en pantalla y sobre todo impresos tienen que estar bien escritos. */
    static $acentuadas = [
        'Cria' => 'Cría', 'Recria' => 'Recría', 'Reproduccion' => 'Reproducción',
        'Prenada' => 'Preñada', 'Inseminacion' => 'Inseminación', 'Vacunacion' => 'Vacunación',
        'Desparasitacion' => 'Desparasitación', 'Vitaminico' => 'Vitamínico',
        'Subcutanea' => 'Subcutánea', 'Topica' => 'Tópica', 'Seleccion' => 'Selección',
        'Produccion' => 'Producción', 'Sanitario' => 'Sanitario', 'Cesarea' => 'Cesárea',
        'Distocico' => 'Distócico', 'Correccion' => 'Corrección', 'Anulacion' => 'Anulación',
        'Nacio muerto' => 'Nació muerto', 'Consumo sanitario' => 'Consumo sanitario',
    ];

    return $acentuadas[$texto] ?? $texto;
}

function iniciales(string $nombre, string $apellido): string
{
    return mb_strtoupper(mb_substr($nombre, 0, 1) . mb_substr($apellido, 0, 1));
}

/**
 * Dirección de una ruta interna, respetando la carpeta donde esté instalado el
 * sistema. Ningún enlace de la aplicación se escribe a mano: con '/hacienda'
 * literal, instalarlo en rparra.cl/ganaderia rompería todos los links.
 */
function url(string $ruta = '/'): string
{
    if (!URL_AMIGABLES) {
        return BASE_URL . '/index.php' . ($ruta === '/' ? '' : '?r=' . $ruta);
    }
    return BASE_URL . $ruta;
}

/**
 * Dirección con parámetros, respetando el formato de URL configurado.
 *
 * Concatenar '?corral=3' a mano rompería cuando `url_amigables` está en false,
 * porque ahí la dirección ya trae una query string (index.php?r=/hacienda).
 */
function url_con(string $ruta, array $parametros = []): string
{
    $base = url($ruta);
    if ($parametros === []) {
        return $base;
    }
    return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($parametros);
}

/** Archivo estático con marca de versión: al subir un CSS nuevo, el navegador no usa la copia vieja. */
function recurso(string $archivo): string
{
    $ruta = RAIZ_APP . '/' . $archivo;
    return BASE_URL . '/' . $archivo . (is_file($ruta) ? '?v=' . filemtime($ruta) : '');
}

/**
 * Campo oculto para formularios GET cuando no hay URLs amigables: el navegador
 * descarta la query string del action al enviar, y con ella se perdería la ruta.
 */
function campo_ruta(string $ruta): string
{
    return URL_AMIGABLES ? '' : '<input type="hidden" name="r" value="' . h($ruta) . '">';
}

/**
 * Deja cada dato como se lee, no como está guardado.
 *
 * Lo que sale de la base viene en el formato de la base: las fechas en
 * año-mes-día y los estados en mayúsculas con guión bajo. Quien abre la planilla
 * o el PDF espera «12/05/2026» y «Macho castrado», así que la traducción se hace
 * acá, una sola vez, para los dos formatos.
 */
function valor_exportado(mixed $valor): string
{
    if ($valor === null) {
        return '';
    }
    $texto = (string) $valor;
    if ($texto === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) === 1) {
        return fecha_corta($texto);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $texto) === 1) {
        return fecha_corta(substr($texto, 0, 10)) . ' ' . substr($texto, 11, 5);
    }
    // Un estado o una categoría: dos letras o más, todo en mayúsculas.
    if (preg_match('/^[A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ_]+$/u', $texto) === 1) {
        return legible($texto);
    }
    return $texto;
}
