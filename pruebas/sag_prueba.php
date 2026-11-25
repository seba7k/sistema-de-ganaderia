<?php
declare(strict_types=1);

/* RUP del predio y DIIO del animal, sin formato de DIIO configurado. */

$org = 1;
$admin = id_usuario('admin@ganaderia.cl');
$alta = static fn (array $extra): array => $extra + [
    'sexo' => 'MACHO', 'categoria' => 'NOVILLO', 'etapa' => 'ENGORDA', 'origen' => 'COMPRADO',
    'fecha_ingreso' => hoy(), 'peso_ingreso_kg' => 250,
];

prueba('el RUP se acepta con puntos, guiones, espacios o junto', function (): void {
    afirmar_igual('09.1.01.0001', normalizar_rup('09.1.01.0001'), 'con puntos');
    afirmar_igual('09.1.01.0001', normalizar_rup('091010001'), 'junto');
    afirmar_igual('09.1.01.0001', normalizar_rup(' 09-1-01-0001 '), 'con guiones y espacios');
});

prueba('lo que no tiene nueve dígitos no es un RUP', function (): void {
    foreach (['9.1.1.1', '09.1.0A.0001', '0910100011', '', 'sin rup'] as $malo) {
        afirmar_igual(null, normalizar_rup($malo), '«' . $malo . '»');
    }
});

prueba('el DIIO se guarda sin espacios y en mayúsculas', function (): void {
    afirmar_igual('123456789', normalizar_diio(' 123 456 789 '), 'con espacios');
    afirmar_igual('A-0143', normalizar_diio('a-0143'), 'minúsculas');
});

prueba('sin formato configurado, cualquier DIIO vale', function () use ($org, $admin, $alta): void {
    afirmar_igual('', FORMATO_DIIO, 'formato_diio vacío por defecto');
    afirmar_igual(null, error_de(fn () => op_alta_animal($org, $admin, $alta(['caravana_visual' => 'x-77']))), 'alta');
    afirmar_igual(1, (int) valor("SELECT COUNT(*) FROM animales WHERE caravana_visual = 'X-77'"), 'guardado normalizado');
});

prueba('la búsqueda encuentra el DIIO aunque se tipee con espacios o en minúsculas', function () use ($org): void {
    $errores = [];
    afirmar(buscar_animal($org, ['identificacion' => ' a-0001 '], $errores) !== null, 'minúsculas y espacios');
    afirmar(buscar_animal($org, ['identificacion' => 'A-0001'], $errores) !== null, 'exacto');
});

prueba('un formato de DIIO mal escrito no genera avisos ni errores', function (): void {
    $avisos = [];
    // Igual que PHP por defecto: lo silenciado con @ no cuenta como aviso.
    set_error_handler(static function (int $nivel, string $mensaje) use (&$avisos): bool {
        if ((error_reporting() & $nivel) !== 0) {
            $avisos[] = $mensaje;
        }
        return true;
    });
    try {
        $resultado = diio_con_formato('123', '/[/');
    } finally {
        restore_error_handler();
    }
    afirmar_igual(false, $resultado, 'resultado');
    afirmar_igual([], $avisos, 'avisos de PHP');
});
