# Lista de subida a cPanel: versión 3.1 (MVA, RUP y DIIO)

Para subir la rama `ccr-144deddf-vtc7cn` sobre una instalación que ya funciona.
Los pasos de instalación desde cero están en [INSTALAR-CPANEL.md](../INSTALAR-CPANEL.md).

**Fecha:** ____ · **Quién sube:** ____

## Antes de subir

- [ ] `php pruebas/correr.php` termina en «0 fallas» en tu computador.
- [ ] El pull request está revisado y aprobado.
- [ ] **Respaldo de la base:** cPanel → phpMyAdmin → la base `…_ganaderia` →
      **Exportar** → rápido, SQL. Guardar el archivo fuera del servidor.
- [ ] **Respaldo de archivos:** descargar `config.php` y la carpeta `datos/`
      completa (incluye `clave-huella.php` si ya existe).
- [ ] Avisar al equipo que el sistema va a estar en mantención unos minutos y que
      la app de terreno pedirá una actualización.

## Prueba en una copia con MySQL (obligatoria la primera vez)

Esta versión cambia la base sola en la primera visita. Hasta ahora eso **solo se
probó con SQLite**: hay que verlo una vez con MySQL antes de tocar producción.

- [ ] Crear una base de prueba en cPanel e importarle el respaldo de producción.
- [ ] Subir la versión nueva a una carpeta aparte (por ejemplo `public_html/prueba`)
      con un `config.php` que apunte a la base de prueba y `'demo' => false`.
- [ ] Abrir el sitio una vez. En phpMyAdmin, sobre la base de prueba, confirmar:
  - [ ] Existe la tabla `migraciones`, con 6 filas: `fecha-secado`, `anulacion`,
        `tablas-nuevas`, `mva-columnas`, `rup-normalizado`, `diio-normalizado`.
  - [ ] `tratamientos` tiene `validado_por`, `validado_en`, `validado_mva_rut`,
        `validado_mva_numero` y `huella`.
  - [ ] `membresias` tiene `mva_rut`, `mva_numero` y `mva_vigente_hasta`.
  - [ ] En **Estructura → Vista de relaciones** de `tratamientos` aparece
        `fk_trat_validador` hacia `usuarios`.
  - [ ] Los RUP de `establecimientos` quedaron como `09.1.01.0001`. Los que no
        tienen 9 dígitos quedan como estaban: revisalos a mano.
  - [ ] Los DIIO de `animales.caravana_visual` quedaron sin espacios y en
        mayúsculas. Si alguno quedó con espacios es porque chocaba con otro
        animal: revisalo.
- [ ] Recorrer en la copia: Predios (editar uno), Usuarios (cargar un registro
      MVA a un veterinario de prueba), Sanidad (validar un tratamiento) y la app
      de terreno (tarea «Validar tratamientos»).
- [ ] En phpMyAdmin, correr `EXPLAIN` sobre la consulta de pendientes de la app
      (`validaciones_app` en `src/operaciones.php`) y confirmar que usa
      `idx_trat_fecha`.
- [ ] Borrar la carpeta y la base de prueba.

## Subida

- [ ] Subir el zip a la carpeta del sistema y extraerlo encima.
- [ ] **No** reemplazar `config.php` ni borrar `datos/`.
- [ ] En `config.php` agregar, si no están:
  - [ ] `'formato_diio' => ''`: dejarlo **vacío** hasta comparar con caravanas reales.
  - [ ] `'clave_huella' => ''`: vacío está bien; se crea `datos/clave-huella.php`.
- [ ] Abrir el sitio: la base se actualiza sola en esa primera visita.
- [ ] Si aparece «Falta configurar el servidor», leer qué dice y corregir `config.php`.

## Después de subir

- [ ] Ingresar como administrador y abrir Predios, Usuarios y Sanidad.
- [ ] En **Usuarios → Registro MVA**, cargar el RUT, el número y la vigencia de
      cada veterinario real. Lo carga otro administrador, nunca la misma persona.
- [ ] Validar un tratamiento con un veterinario y confirmar que aparece «Validado».
- [ ] Confirmar que existe `datos/clave-huella.php` (aparece con la primera
      validación) y **agregarlo a los respaldos** junto con la base.
- [ ] En cada teléfono: abrir la app con señal para que tome la versión nueva
      (`terreno-2.5.0`). Si muestra datos viejos, **Cerrar sesión** y volver a ingresar.
- [ ] Revisar **Errors** de cPanel durante la primera hora.

## Volver atrás si algo sale mal

Las columnas nuevas aceptan vacío y la versión anterior las ignora, así que basta
con volver al código anterior. Volver atrás si pasa cualquiera de estas cosas:

- El sitio muestra «Algo salió mal» en Predios, Usuarios o Sanidad.
- La app de terreno no logra sincronizar.
- Los tratamientos validados aparecen como «Alterado» sin que nadie los haya cambiado.

Pasos:

1. Subir el zip de la versión anterior encima. No borrar `datos/`.
2. Si la base quedó dañada, importar el respaldo que se hizo al principio.
3. Anotar qué pasó y avisar antes de reintentar.

Dos cosas no se deshacen solas al volver atrás: los RUP y los DIIO ya
normalizados. Son el mismo dato escrito de otra forma; la versión anterior los
muestra igual.
