<?php
declare(strict_types=1);

/* Aviso automático de errores del servidor: registro, agrupación, límite y privacidad. */

prueba('un error queda en el registro y se avisa', function (): void {
    $GLOBALS['alertas_enviadas'] = [];
    $r = registrar_error_servidor('ERROR', 'RuntimeException', 'Algo falló', RAIZ_APP . '/src/salidas.php', 42);
    afirmar($r['avisado'], 'no se avisó');
    afirmar_igual(1, count($GLOBALS['alertas_enviadas']), 'avisos');
    afirmar(str_contains($GLOBALS['alertas_enviadas'][0], 'src/salidas.php:42'), 'la ruta del archivo no es relativa');
    $grupos = errores_recientes();
    afirmar_igual('Algo falló', $grupos[0]['mensaje'], 'registro');
});

prueba('el mismo error repetido se avisa una vez por hora y se cuenta', function (): void {
    $GLOBALS['alertas_enviadas'] = [];
    for ($i = 0; $i < 5; $i++) {
        registrar_error_servidor('ERROR', 'PDOException', 'Fila ' . $i . ' no encontrada', RAIZ_APP . '/src/db.php', 10);
    }
    afirmar_igual(1, count($GLOBALS['alertas_enviadas']), 'avisos del mismo error');
    $grupo = array_values(array_filter(errores_recientes(), static fn (array $g): bool => $g['archivo'] === 'src/db.php'))[0];
    afirmar_igual(5, $grupo['veces'], 'veces');
});

prueba('errores distintos se avisan por separado, con tope por hora', function (): void {
    $GLOBALS['alertas_enviadas'] = [];
    for ($i = 0; $i < ALERTAS_MAXIMO_POR_HORA + 10; $i++) {
        registrar_error_servidor('ERROR', 'Error', 'distinto', RAIZ_APP . '/src/x.php', 100 + $i);
    }
    // Ya se mandaron 2 en las pruebas anteriores dentro de la misma hora.
    afirmar_igual(ALERTAS_MAXIMO_POR_HORA - 2, count($GLOBALS['alertas_enviadas']), 'tope por hora');
});

prueba('el aviso no lleva correos, RUT ni números largos', function (): void {
    $texto = enmascarar_datos("Duplicate entry 'juan.perez@campo.cl' para RUT 12.345.678-9 y tel 912345678, DIIO A-0143");
    afirmar(!str_contains($texto, 'juan.perez'), $texto);
    afirmar(!str_contains($texto, '12.345.678-9'), $texto);
    afirmar(!str_contains($texto, '912345678'), $texto);
    afirmar(str_contains($texto, 'A-0143'), 'enmascaró de más: ' . $texto);
});

prueba('marcar revisado lo saca de los pendientes hasta que vuelva a ocurrir', function (): void {
    $r = registrar_error_servidor('ERROR', 'Error', 'para revisar', RAIZ_APP . '/src/y.php', 1);
    $antes = errores_sin_revisar();
    marcar_error_revisado($r['huella']);
    afirmar_igual($antes - 1, errores_sin_revisar(), 'pendientes');
});

prueba('una excepción no controlada pasa por el registro', function (): void {
    $antes = count(errores_recientes());
    registrar_error_servidor('ERROR', LogicException::class, 'excepcion nueva', __FILE__, __LINE__);
    afirmar_igual($antes + 1, count(errores_recientes()), 'grupos');
});

prueba('solo el administrador ve los errores', function (): void {
    afirmar(rol_puede('ADMINISTRADOR', 'ver_errores'), 'admin');
    afirmar(!rol_puede('VETERINARIO', 'ver_errores') && !rol_puede('OPERARIO', 'ver_errores'), 'otros roles');
});
