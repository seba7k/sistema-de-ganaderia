<?php
declare(strict_types=1);

/**
 * Rehace la base con datos de demostración. Solo por consola: `php seed.php`
 *
 * Borra todas las tablas. Por eso se niega a correr desde el navegador: si
 * alguien abriera esta dirección en el servidor, se perderían todos los datos.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('FORZAR_DATOS_DEMO', true);
require __DIR__ . '/src/db.php';

vaciar_base();
db();

printf(
    "Base %s recreada con datos de demostración:\n  %d animales\n  %d corrales\n  %d lotes\n  %d entregas de alimento\n  %d tratamientos\n  %d usuarios\n\nCuentas: admin@ganaderia.cl / demo1234\n",
    strtoupper(motor_bd()),
    (int) valor('SELECT COUNT(*) FROM animales'),
    (int) valor('SELECT COUNT(*) FROM corrales'),
    (int) valor('SELECT COUNT(*) FROM lotes'),
    (int) valor('SELECT COUNT(*) FROM entregas_alimento'),
    (int) valor('SELECT COUNT(*) FROM tratamientos'),
    (int) valor('SELECT COUNT(*) FROM usuarios'),
);
