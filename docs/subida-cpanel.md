# Lista de subida a cPanel: versión 3.2 (salidas, FMA, privacidad y alertas)

Para subir la rama `main` sobre una instalación que ya funciona, o para la primera
puesta en producción. Los pasos de instalación desde cero están en
[INSTALAR-CPANEL.md](../INSTALAR-CPANEL.md).

Esta lista cubre v1.1 a v1.5 del repositorio (en pantalla, versión 3.2): MVA, RUP y
DIIO; salidas con bloqueo sanitario, FMA y SIPEC; raciones calculadas; filtros y
Excel; lector de DIIO; Ley 21.719; avisos de errores.

**Fecha:** ____ · **Quién sube:** ____

## Antes de subir

- [ ] `php pruebas/correr.php` termina en «0 fallas» en tu computador.
- [ ] **Respaldo de la base:** cPanel → phpMyAdmin → la base `…_ganaderia` →
      **Exportar** → rápido, SQL. Guardar el archivo fuera del servidor.
- [ ] **Respaldo de archivos:** descargar `config.php` y la carpeta `datos/`
      completa (incluye `clave-huella.php` si ya existe).
- [ ] Avisar al equipo que el sistema va a estar en mantención unos minutos y que
      la app de terreno pedirá una actualización.

## Prueba en una copia con MySQL (obligatoria la primera vez)

La base se actualiza sola en la primera visita. Las pruebas automáticas corren con
SQLite: hay que verlo una vez con MySQL antes de tocar producción.

- [ ] Crear una base de prueba en cPanel e importarle el respaldo de producción
      (o dejarla vacía si es la primera instalación).
- [ ] Subir la versión nueva a una carpeta aparte (por ejemplo `public_html/prueba`)
      con un `config.php` que apunte a la base de prueba y `'demo' => false`.
- [ ] Abrir el sitio una vez. En phpMyAdmin, sobre la base de prueba, confirmar:
  - [ ] La tabla `migraciones` tiene 8 filas: `fecha-secado`, `anulacion`,
        `tablas-nuevas`, `mva-columnas`, `rup-normalizado`, `diio-normalizado`,
        `salidas`, `privacidad`.
  - [ ] Existen las tablas `salidas`, `detalles_salida`, `solicitudes_datos` y
        `aceptaciones_privacidad`.
  - [ ] `tratamientos` tiene `validado_por`, `validado_en`, `validado_mva_rut`,
        `validado_mva_numero` y `huella`; `membresias` tiene `mva_rut`,
        `mva_numero` y `mva_vigente_hasta`.
  - [ ] Los RUP de `establecimientos` quedaron como `09.1.01.0001` y los DIIO sin
        espacios y en mayúsculas.
- [ ] Recorrer en la copia:
  - [ ] Al ingresar aparece la **política de privacidad**; aceptarla.
  - [ ] **Salidas y FMA**: registrar una venta de prueba, descargar el FMA (PDF) y
        la planilla SIPEC (Excel) y abrir los dos.
  - [ ] **Exportar** → Inventario con un filtro → Excel, PDF y CSV.
  - [ ] **Alimentación**: aparece «Ración calculada por corral».
  - [ ] **Mis datos y privacidad**: descargar mis datos y hacer una solicitud;
        responderla en **Solicitudes de datos**.
  - [ ] **Errores del servidor** abre sin problemas.
- [ ] Borrar la carpeta y la base de prueba.

## Subida

- [ ] Subir el zip a la carpeta del sistema y extraerlo encima.
- [ ] **No** reemplazar `config.php` ni borrar `datos/`.
- [ ] En `config.php` (ver `config.ejemplo.php` para el detalle de cada clave):
  - [ ] `'demo' => false` y la base `'motor' => 'mysql'` con sus datos.
  - [ ] `'gestion_avanzada' => true` si se usan sanidad, bodega, MVA y resguardo.
  - [ ] `'formato_diio' => ''`: dejarlo **vacío** hasta comparar con caravanas reales.
  - [ ] `'clave_huella' => ''`: vacío está bien; se crea `datos/clave-huella.php`.
  - [ ] `'alertas'`: al menos un canal (correo del dominio o Telegram).
  - [ ] `'privacidad'`: el `correo_contacto` para que las personas ejerzan sus derechos.
- [ ] Abrir el sitio: la base se actualiza sola en esa primera visita.
- [ ] Si aparece «Falta configurar el servidor», leer qué dice y corregir `config.php`.

## Después de subir

- [ ] **HTTPS activo** (cPanel → SSL/TLS Status → Run AutoSSL). Sin HTTPS no
      funcionan la app de terreno, la cámara ni el bastón RFID.
- [ ] Ingresar como administrador y aceptar la política de privacidad.
- [ ] **Errores del servidor → Enviar un aviso de prueba**: confirmar que llega.
- [ ] Crear un monitor en **UptimeRobot** (gratis) hacia `https://TU-DOMINIO/salud`
      cada 5 minutos, con aviso al mismo correo o Telegram.
- [ ] **Respaldo diario automático:** cPanel → **Cron Jobs**, una vez por día:
      `mysqldump --single-transaction -u USUARIO -p'CLAVE' BASE | gzip > ~/respaldos/ganaderia-$(date +\%F).sql.gz`
      (crear antes la carpeta `~/respaldos`, fuera de `public_html`), y bajar una
      copia fuera del servidor cada semana.
- [ ] En **Usuarios → Registro MVA**, cargar el RUT, el número y la vigencia de
      cada veterinario real. Lo carga otro administrador, nunca la misma persona.
- [ ] En **Predios**, confirmar que cada predio tiene su RUP (sin RUP no se
      pueden registrar salidas).
- [ ] En cada teléfono: abrir la app con señal para que tome la versión nueva
      (`terreno-2.7.0`). Si muestra datos viejos, **Cerrar sesión** y volver a ingresar.
- [ ] Revisar **Errores del servidor** y **Errors** de cPanel durante la primera hora.

## Volver atrás si algo sale mal

Las tablas y columnas nuevas no molestan a la versión anterior, así que basta con
volver al código anterior. Volver atrás si pasa cualquiera de estas cosas:

- El sitio muestra «Algo salió mal» en el Panel, Salidas, Exportar o Usuarios.
- La app de terreno no logra sincronizar.
- Los tratamientos validados aparecen como «Alterado» sin que nadie los haya cambiado.

Pasos:

1. Subir el zip de la versión anterior encima. No borrar `datos/`.
2. Si la base quedó dañada, importar el respaldo que se hizo al principio.
3. Anotar qué pasó y avisar antes de reintentar.

Dos cosas no se deshacen solas al volver atrás: los RUP y los DIIO ya
normalizados. Son el mismo dato escrito de otra forma; la versión anterior los
muestra igual.
