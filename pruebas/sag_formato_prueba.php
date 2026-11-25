<?php
declare(strict_types=1);

/* DIIO con formato configurado (nueve dígitos, ver correr.php). */

$org = 1;
$admin = id_usuario('admin@ganaderia.cl');
$alta = static fn (array $extra): array => $extra + [
    'sexo' => 'MACHO', 'categoria' => 'NOVILLO', 'etapa' => 'ENGORDA', 'origen' => 'COMPRADO',
    'fecha_ingreso' => hoy(), 'peso_ingreso_kg' => 250,
];

prueba('con formato, un DIIO que no lo cumple se rechaza', function () use ($org, $admin, $alta): void {
    afirmar_rechazo(fn () => op_alta_animal($org, $admin, $alta(['caravana_visual' => 'A-9999'])), 'formato');
});

prueba('con formato, un DIIO que lo cumple se acepta aunque traiga espacios', function () use ($org, $admin, $alta): void {
    afirmar_igual(null, error_de(fn () => op_alta_animal($org, $admin, $alta(['caravana_visual' => '123 456 789']))));
});

prueba('el número interno no tiene que cumplir el formato del DIIO', function () use ($org, $admin, $alta): void {
    afirmar_igual(null, error_de(fn () => op_alta_animal($org, $admin, $alta(['numero_interno' => 'P-77']))));
});

prueba('un animal antiguo se edita sin tocar su DIIO, aunque esté escrito distinto', function () use ($org, $admin): void {
    $animal = fila("SELECT * FROM animales WHERE caravana_visual = 'A-0001'");
    // Como quedó guardado antes de normalizar: con espacio y en minúsculas.
    consultar("UPDATE animales SET caravana_visual = 'a 0001' WHERE id = ?", [$animal['id']]);
    $datos = [
        'animal_id' => $animal['id'], 'caravana_visual' => 'a 0001', 'numero_interno' => '',
        'eid' => (string) $animal['eid'], 'sexo' => $animal['sexo'], 'categoria' => $animal['categoria'],
        'raza' => 'Hereford', 'etapa' => $animal['etapa'], 'origen' => $animal['origen'],
        'corral_actual_id' => $animal['corral_actual_id'],
    ];
    afirmar_igual(null, error_de(fn () => op_editar_animal($org, $admin, $datos)), 'edición');
});

prueba('cambiar el DIIO de un animal antiguo por uno fuera de formato se rechaza', function () use ($org, $admin): void {
    $animal = fila("SELECT * FROM animales WHERE caravana_visual = 'A-0002'");
    $datos = [
        'animal_id' => $animal['id'], 'caravana_visual' => 'B-1', 'numero_interno' => '',
        'eid' => (string) $animal['eid'], 'sexo' => $animal['sexo'], 'categoria' => $animal['categoria'],
        'raza' => (string) $animal['raza'], 'etapa' => $animal['etapa'], 'origen' => $animal['origen'],
        'corral_actual_id' => $animal['corral_actual_id'],
    ];
    afirmar_rechazo(fn () => op_editar_animal($org, $admin, $datos), 'formato');
});
