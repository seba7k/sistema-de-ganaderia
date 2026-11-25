<?php
declare(strict_types=1);

/**
 * Identificadores oficiales del SAG: el RUP del predio y el DIIO del animal.
 *
 * Cálculos puros, sin base de datos. La web y la app pasan por acá, así que un
 * RUP o un DIIO se guardan siempre escritos de la misma manera, y la búsqueda
 * por identificación encuentra el animal aunque se haya tipeado con espacios.
 */

/**
 * RUP normalizado como 12.3.45.0001, o null si no tiene la forma de un RUP.
 *
 * El Rol Único Pecuario tiene nueve dígitos: región (2), provincia (1),
 * comuna (2) y correlativo del predio (4). Se aceptan con puntos, guiones,
 * espacios o todo junto, y se guardan siempre con puntos.
 */
function normalizar_rup(string $rup): ?string
{
    $digitos = preg_replace('/[\s.\-]/', '', $rup) ?? '';
    if (preg_match('/^(\d{2})(\d)(\d{2})(\d{4})$/', $digitos, $partes) !== 1) {
        return null;
    }
    return $partes[1] . '.' . $partes[2] . '.' . $partes[3] . '.' . $partes[4];
}

/**
 * DIIO tal como se guarda: sin espacios y en mayúsculas.
 *
 * No cambia los guiones ni los ceros: el número impreso en la caravana es el
 * que se lee en la manga, y tiene que coincidir carácter por carácter.
 */
function normalizar_diio(string $diio): string
{
    return mb_strtoupper(preg_replace('/\s+/u', '', $diio) ?? '');
}

/**
 * ¿El DIIO cumple el formato configurado? Sin formato configurado, todo vale.
 *
 * El formato es una expresión regular en config.php (`formato_diio`). Queda en
 * la configuración y no en el código porque depende de las caravanas que use
 * cada campo, y validar contra un formato equivocado dejaría animales reales
 * sin poder cargarse.
 */
function diio_con_formato(string $diio, string $formato): bool
{
    // Un formato mal escrito no valida nada y no ensucia la página con avisos:
    // el arranque ya lo informa como problema de configuración.
    return $formato === '' || @preg_match($formato, $diio) === 1;
}
