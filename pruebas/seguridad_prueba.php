<?php
declare(strict_types=1);

/* Defensas que no dependen de una regla de negocio. */

prueba('la carpeta de pruebas se bloquea por sí misma, sin depender de mod_rewrite', function (): void {
    $archivo = __DIR__ . '/.htaccess';
    afirmar(is_file($archivo), 'falta pruebas/.htaccess');
    afirmar(str_contains((string) file_get_contents($archivo), 'Require all denied'), 'no niega el acceso');
});

prueba('el ejecutor de pruebas se niega a correr desde la web', function (): void {
    afirmar(str_contains((string) file_get_contents(__DIR__ . '/correr.php'), "PHP_SAPI !== 'cli'"));
});

prueba('el arranque avisa si formato_diio no se puede usar en la web ni en la app', function (): void {
    afirmar_igual(null, problema_formato_diio(''), 'vacío');
    afirmar_igual(null, problema_formato_diio('/^\d{9}$/'), 'válido');
    afirmar(problema_formato_diio('/[/') !== null, 'mal escrito');
    afirmar(problema_formato_diio('#^\d{9}$#') !== null, 'otro delimitador: la app no lo entiende');
});
