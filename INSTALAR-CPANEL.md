# Subir a cPanel

Probado con Apache + PHP-FPM y MariaDB, instalado en una subcarpeta de
`public_html`. Son ocho pasos: preparar PHP, crear la base MySQL, subir el zip,
completar `config.php`, abrir el sitio, cargar los predios, poner HTTPS y, cuando
se termine de mostrar la demostración, vaciar la base.

## 1. Preparar PHP (una sola vez)

En cPanel, **Select PHP Version** (o **MultiPHP Manager**):

- Versión **8.1 o superior** (desarrollado y probado en 8.5).
- En la pestaña **Extensions**, que estén marcadas **pdo_mysql** y **mbstring**.
  En casi todos los hostings ya vienen activas.

## 2. Crear la base de datos MySQL

En cPanel, **Bases de datos MySQL** (MySQL® Databases):

1. **Crear nueva base de datos**: escribir `ganaderia`. cPanel le antepone tu
   usuario, así que queda algo como `rparra_ganaderia`. Anotá el nombre completo.
2. **Agregar nuevo usuario**: nombre `ganaderia` (queda `rparra_ganaderia`) y una
   contraseña. Usá el **Generador de contraseñas** y anotala.
3. **Agregar usuario a la base de datos**: elegir ese usuario y esa base →
   **Agregar** → marcar **TODOS LOS PRIVILEGIOS** → **Hacer cambios**.

No hace falta crear tablas ni importar nada: el sistema las crea solo la primera
vez que se abre.

## 3. Subir

El zip **no trae carpeta propia**: los archivos quedan directo en la carpeta
donde lo extraigas.

1. **Administrador de archivos** → entrar a la carpeta donde va el sistema,
   por ejemplo `public_html/pt` (crearla antes si no existe).
2. **Cargar** → elegir `ganaderia-pt.zip`.
3. Volver a la carpeta, clic derecho sobre el zip → **Extraer** → dejar la ruta
   que propone, que es la carpeta actual.
4. Borrar el zip.

> Si ya había una versión anterior en la carpeta, extraer encima reemplaza los
> archivos. Revisá después que `config.php` tenga tus datos (paso 4): el zip trae
> uno en blanco. La carpeta `datos/` solo la usa la versión SQLite (desarrollo):
> con MySQL queda vacía y no molesta.

El Administrador de archivos oculta los archivos que empiezan con punto, así
que puede que no veas el `.htaccess`: está ahí. Para verlo, **Configuración** →
**Mostrar archivos ocultos**. No lo borres: es el que protege el código.

## 4. Completar `config.php`

Clic derecho sobre `config.php` → **Edit** y reemplazar los tres datos del paso 2:

```php
'nombre'  => 'rparra_ganaderia',   // nombre completo de la base
'usuario' => 'rparra_ganaderia',   // nombre completo del usuario
'clave'   => 'la contraseña generada',
```

`host` se deja en `localhost`. Guardar.

En el mismo archivo está `gestion_avanzada`. Viene en `false`, que deja el
sistema en el ciclo productivo: nacimientos, encastes, partos, secados, pesajes,
bajas, corrales, alimentación por lote y notas escritas a mano. Si el campo
necesita además sanidad con carencia, bodega de insumos y cierre económico por
lote, ponerlo en `true` y esas tres pantallas aparecen en el menú.

## 5. Abrir

Abrir **https://TU-DOMINIO/pt/**. La primera visita crea las tablas y carga los
datos de demostración; tarda un par de segundos.

Cuentas de prueba, todas con la contraseña `demo1234`:

| Cuenta | Rol | Qué puede hacer |
|---|---|---|
| `admin@ganaderia.cl` | Administrador | Todo: ventas, lotes, bodega, raciones y usuarios. Trabaja en dos campos. |
| `vet@ganaderia.cl` | Médico veterinario | Tratamientos, servicios, diagnósticos, partos y pesajes. Consulta el resto. |
| `operario@ganaderia.cl` | Operario (manga) | Pesajes, partos, alimentación, traslados y bajas. |
| `tractor@ganaderia.cl` | Operario (alimentación) | Igual; es quien figura en las entregas de alimento. |

La carpeta puede llamarse como quieras: los enlaces se adaptan solos.

## 6. Los predios

El sistema entiende que un mismo propietario —identificado por su RUT— puede
tener varios campos. En **Gestión → Predios** se agregan, y el selector de la
barra superior decide en cuál se está trabajando: hacienda, corrales,
alimentación y reproducción muestran solo ese predio. En el teléfono, el predio
se cambia desde el menú lateral.

## 7. La app de terreno en el teléfono

La app para la manga y el corral está en **https://TU-DOMINIO/pt/app/** (también
desde el menú **Gestión → App de terreno**). Se instala como una aplicación y
funciona sin señal:

- **Android (Chrome):** abrir la dirección → menú ⋮ → **Instalar aplicación** (o
  «Agregar a la pantalla principal»).
- **iPhone (Safari):** abrir la dirección → botón **Compartir** →
  **Agregar a inicio**.

Para ingresar la primera vez se necesita señal. Después se puede pesar, registrar
partos, secados, notas, entregas de alimento y traslados sin conexión: todo queda
guardado en el teléfono y se envía solo cuando vuelve la señal. El botón de la
barra superior muestra cuántos registros faltan por enviar.

> La app necesita **HTTPS**. cPanel lo activa gratis con **SSL/TLS Status** →
> **Run AutoSSL**. Sin HTTPS el teléfono no la deja instalar ni trabajar offline.

> **Mientras esté en demostración, el sitio queda con cuentas conocidas.**
> `config.php` trae `'demo' => true`, que siembra los datos de ejemplo y muestra
> las cuentas en la pantalla de ingreso; la barra superior lleva un sello «Demo»
> para que no se confunda con un sistema en uso. Antes de cargar animales reales,
> hay que ponerlo en `false` y cambiar las contraseñas.

## 8. Antes de presentar

Los estados de carencia («faltan 3 días», «apto») y los próximos partos se
calculan contra la fecha de hoy. Si subiste el sistema varios días antes,
**refrescá los datos de demostración**:

1. cPanel → **phpMyAdmin** → elegir la base `…_ganaderia` en la izquierda.
2. Abajo de la lista de tablas: **Seleccionar todo** → en el desplegable,
   **Eliminar** → confirmar.
3. Recargar el sistema: se vuelve a crear con fechas de hoy.

Si el hosting tiene **Terminal**, es lo mismo que correr `php seed.php` dentro de
la carpeta.

Los teléfonos que ya tenían la app instalada guardan datos viejos: en la app,
**Cerrar sesión** y volver a ingresar.

## Si algo falla

| Síntoma | Qué hacer |
|---|---|
| «Falta configurar el servidor» | Dice exactamente qué falta: versión de PHP, una extensión (paso 1) o los datos de `config.php` (paso 4). |
| «Sin conexión con la base de datos» | Nombre, usuario o clave mal copiados en `config.php`, o el usuario no quedó agregado a la base con todos los privilegios (paso 2.3). Recordá el prefijo `usuario_` en los dos nombres. |
| La portada abre, pero cualquier otro link da 404 | El hosting no tiene `mod_rewrite`. En `config.php` poner `'url_amigables' => false`. La app de terreno funciona igual. |
| Error 500 en todo el sitio | Revisar **Errors** en cPanel. Si menciona `.htaccess`, avisar: hay hostings que restringen directivas. |
| La app no ofrece «Instalar» | Falta HTTPS (paso 7), o se abrió en modo incógnito. |
| La app dice «La sesión venció» | El token dura 30 días (`dias_sesion_app` en `config.php`). Volver a ingresar: lo pendiente no se pierde. |
| Un registro de la app aparece «rechazado» | Se abre en **Envíos** con el motivo (por ejemplo, un peso que bajó y hay que confirmar, o falta stock en bodega). Se puede confirmar, reintentar o descartar. |
| Cambié `config.php` y no se nota | PHP guarda los archivos en caché unos segundos (en algunos hostings, hasta un minuto). Esperar y recargar. |
| «Algo salió mal» | El detalle queda en **Errors** de cPanel. Para verlo en pantalla mientras se diagnostica: `'mostrar_errores' => true` en `config.php`, y volverlo a `false` después. |

## Importante: esto es una demostración pública

Con `'demo' => true`, **cualquiera que tenga el link puede entrar**: las cuentas
de prueba y su contraseña se muestran en la pantalla de ingreso. Es lo que se
quiere para presentar, pero no conviene dejarlo publicado indefinidamente ni
cargarle datos reales.

Para usarlo con datos reales:

1. Vaciar la base (paso 8, puntos 1 y 2).
2. En `config.php`, poner `'demo' => false`.
3. Abrir el sitio: aparece **Primera configuración**, que crea el campo y la
   cuenta de administrador. Desde **Usuarios** se da acceso al resto del equipo.
