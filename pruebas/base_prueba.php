<?php
declare(strict_types=1);

/* Migraciones de la base y consultas de la validación MVA. */

$org = 1;

prueba('las migraciones quedan registradas una sola vez', function (): void {
    afirmar(migracion_aplicada(db(), 'mva-columnas'), 'mva-columnas');
    afirmar(migracion_aplicada(db(), 'rup-normalizado'), 'rup-normalizado');
    migrar_base(db());
    afirmar_igual(1, (int) valor("SELECT COUNT(*) FROM migraciones WHERE nombre = 'rup-normalizado'"), 'sin duplicados');
});

prueba('una migración interrumpida se puede repetir sin error', function (): void {
    // Simula una base actualizada a medias: la columna existe pero la marca no.
    consultar("DELETE FROM migraciones WHERE nombre = 'mva-columnas'");
    migrar_base(db());
    migrar_base(db());
    afirmar(migracion_aplicada(db(), 'mva-columnas'));
});

prueba('los RUP ya cargados se normalizan una vez, sin perder los que no se entienden', function () use ($org): void {
    $ids = [];
    foreach (['091010003', '9.1.1.1', 'sin rup', '09.1.01.0004', '09-1-01-0004'] as $i => $rup) {
        consultar(
            "INSERT INTO establecimientos (organizacion_id, nombre, tipo, rup) VALUES (?, ?, 'CRIANZA', ?)",
            [$org, 'Predio de prueba ' . $i, $rup],
        );
        $ids[] = ultimo_id();
    }
    consultar("DELETE FROM migraciones WHERE nombre = 'rup-normalizado'");
    migrar_base(db());
    migrar_base(db());

    $rup = static fn (int $id): ?string => valor('SELECT rup FROM establecimientos WHERE id = ?', [$id]);
    afirmar_igual('09.1.01.0003', $rup($ids[0]), 'nueve dígitos juntos');
    afirmar_igual('9.1.1.1', $rup($ids[1]), 'irreconocible: queda igual');
    afirmar_igual('sin rup', $rup($ids[2]), 'texto libre: queda igual');
    afirmar_igual('09.1.01.0004', $rup($ids[3]), 'ya normalizado');
    // Normalizado chocaría con el anterior: se deja como estaba para que alguien lo revise.
    afirmar_igual('09-1-01-0004', $rup($ids[4]), 'repetido: no se pisa');
});

prueba('los animales de varios tratamientos salen en una sola consulta', function () use ($org): void {
    $ids = array_map('intval', array_column(filas('SELECT id FROM tratamientos WHERE organizacion_id = ? ORDER BY id', [$org]), 'id'));
    $mapa = animales_por_tratamiento($org, $ids);
    foreach ($ids as $id) {
        $esperado = animales_del_tratamiento($org, $id);
        sort($esperado);
        $obtenido = $mapa[$id] ?? [];
        sort($obtenido);
        afirmar_igual($esperado, $obtenido, 'animales del tratamiento ' . $id);
    }
    afirmar_igual([], animales_por_tratamiento($org, []), 'sin ids');
});

prueba('el estado con los animales ya leídos coincide con el que los lee solo', function () use ($org): void {
    $tratamientos = filas('SELECT * FROM tratamientos WHERE organizacion_id = ?', [$org]);
    $mapa = animales_por_tratamiento($org, array_map('intval', array_column($tratamientos, 'id')));
    foreach ($tratamientos as $t) {
        afirmar_igual(
            estado_validacion($org, $t),
            estado_validacion($org, $t, $mapa[(int) $t['id']] ?? []),
            'tratamiento ' . $t['id'],
        );
    }
});

prueba('los DIIO ya cargados se normalizan una vez, sin crear duplicados', function () use ($org): void {
    $predio = (int) valor('SELECT id FROM establecimientos WHERE organizacion_id = ? ORDER BY id LIMIT 1', [$org]);
    $ids = [];
    foreach (['ab 123', 'cd456', 'EF 789', 'EF789'] as $diio) {
        consultar(
            "INSERT INTO animales (organizacion_id, establecimiento_id, caravana_visual, sexo, categoria, fecha_ingreso, peso_ingreso_kg)
             VALUES (?, ?, ?, 'MACHO', 'NOVILLO', ?, 250)",
            [$org, $predio, $diio, hoy()],
        );
        $ids[] = ultimo_id();
    }
    consultar("DELETE FROM migraciones WHERE nombre = 'diio-normalizado'");
    migrar_base(db());
    $diio = static fn (int $id): ?string => valor('SELECT caravana_visual FROM animales WHERE id = ?', [$id]);
    afirmar_igual('AB123', $diio($ids[0]), 'espacios y minúsculas');
    afirmar_igual('CD456', $diio($ids[1]), 'minúsculas');
    // Normalizado chocaría con EF789, que ya existe: queda como estaba para revisarlo.
    afirmar_igual('EF 789', $diio($ids[2]), 'repetido: no se pisa');
    afirmar(migracion_aplicada(db(), 'diio-normalizado'), 'marca');
});

prueba('los estados de validación de varios tratamientos salen juntos', function () use ($org): void {
    $tratamientos = filas('SELECT * FROM tratamientos WHERE organizacion_id = ?', [$org]);
    $estados = estados_validacion($org, $tratamientos);
    foreach ($tratamientos as $t) {
        afirmar_igual(estado_validacion($org, $t), $estados[(int) $t['id']] ?? null, 'tratamiento ' . $t['id']);
    }
});
