# Gestión ganadera — PHP + MySQL + app de terreno

Sistema para la crianza de animales, de un propietario con uno o varios predios,
con dos formas de uso:

- **Plataforma web** para administrar: hacienda, corrales, alimentación, sanidad,
  reproducción, bodega, lotes y usuarios.
- **App de terreno** instalable en el teléfono, para la manga y el corral, que
  trabaja sin señal y sincroniza sola.

PHP plano, sin frameworks ni dependencias. Para subirlo a un hosting, ver
**[INSTALAR-CPANEL.md](INSTALAR-CPANEL.md)**.

## Arquitectura

```
 Teléfono (app de terreno, /app/)                Servidor cPanel
 ┌────────────────────────────────┐              ┌──────────────────────────────────┐
 │ Interfaz de alto contraste     │              │ index.php (front controller)     │
 │ IndexedDB: catálogo + cola     │  HTTPS/JSON  │  ├─ páginas web (sesión)          │
 │ Service worker: abre sin señal │ ───────────► │  └─ API REST (token)  src/api.php │
 └────────────────────────────────┘ ◄─────────── │ src/operaciones.php  ← reglas     │
                                     catálogo    │ src/dominio/         ← cálculos   │
 Navegador (plataforma web) ───── sesión ──────► │ MySQL / MariaDB (InnoDB)          │
                                                 └──────────────────────────────────┘
```

**Dos modos, un solo sistema.** `config.php` tiene `gestion_avanzada`:

- **En false (por defecto): ciclo productivo.** Lo que el productor usa todos los
  días: nacimientos, encastes, partos, secados, pesajes, bajas, corrales,
  alimentación por lote y notas sanitarias escritas a mano.
- **En true: gestión avanzada.** Se suman sanidad con productos, dosis y carencia
  (con bloqueo de faena), bodega de insumos con descuento automático, y lotes con
  su cierre económico. Sirven para un feedlot o para quien lleve costos.

El código es el mismo: en modo simple esas pantallas no aparecen en el menú y sus
rutas no existen, así que el sistema queda liviano sin perder lo construido.

**Un propietario, varios predios.** La organización es el propietario,
identificado por su RUT como en el registro del SAG, y de él cuelgan sus campos.
El sistema muestra un predio a la vez —se elige en la barra superior— porque
sumar la ocupación de corrales o la hacienda de campos distintos daría números
que no significan nada.

**Una sola capa de reglas.** Pesaje, parto, tratamiento, entrega de alimento,
traslado y baja viven en `src/operaciones.php`. La web y la API llaman a las
mismas funciones: el peso anormal, el descuento de bodega o la capacidad del
corral se validan igual venga el dato del formulario o del teléfono.

**Sincronización bidireccional.**

- *Del teléfono al servidor:* cada registro se guarda primero en la cola local
  con un identificador único (UUID). Al haber señal se envía a
  `POST /api/sincronizar`. El servidor guarda cada operación en su propia
  transacción junto con su UUID (`sincronizaciones`, clave única): si la señal se
  corta y el teléfono reintenta, lo ya guardado vuelve como `DUPLICADA` y no se
  duplica. Lo rechazado vuelve con el motivo, para confirmarlo o descartarlo.
- *Del servidor al teléfono:* `GET /api/catalogo` baja animales, corrales,
  raciones, productos, carencias y partos probables, que la app usa para
  identificar y validar sin conexión.

**API REST** (`index.php?r=/api/…`, o `/api/…` con URL amigables):

| Método | Ruta | Qué hace |
|---|---|---|
| POST | `/api/sesion` | Correo y contraseña → token (30 días). `409` si la persona trabaja en varias organizaciones. |
| POST | `/api/sesion/cerrar` | Revoca el token. |
| GET | `/api/catalogo` | Datos del campo para trabajar sin señal. |
| POST | `/api/sincronizar` | Lista de operaciones → resultado de cada una (`OK`, `DUPLICADA`, `RECHAZADA`, `ERROR`). |

El token viaja en `Authorization: Bearer …` (y en `X-Token`, para hostings que
descartan esa cabecera). En la base se guarda solo su hash SHA-256.

## Levantarlo en local

```bash
php seed.php                    # crea las tablas y carga la demostración
php -S localhost:8000 index.php
```

Web en <http://localhost:8000>, app en <http://localhost:8000/app/>. Cuentas con
contraseña `demo1234`: `admin@`, `vet@`, `operario@` y `tractor@ganaderia.cl`.

Al subir una versión nueva no hay que correr nada: en la primera visita la base
se actualiza sola, y cada cambio queda anotado en la tabla `migraciones` para no
repetirse (`migraciones()` en `src/db.php`).

La base se configura en `config.php`. Para desarrollar sin servidor MySQL, un
`config.local.php` (no se sube al hosting) puede cambiar el motor:

```php
<?php
return ['base_datos' => ['motor' => 'sqlite']];
```

## Pruebas

```
php pruebas/correr.php                   # todas
php pruebas/correr.php mva_prueba.php    # un archivo
```

Corre cada archivo `pruebas/*_prueba.php` en un proceso propio, con una base
SQLite de demostración recién creada en la carpeta temporal: no usa `config.php`
ni toca la base de la instalación. Los `*_formato_prueba.php` corren con un
`formato_diio` de ejemplo. Termina con código 1 si algo falla. Solo funciona por
consola, y el `.htaccess` bloquea la carpeta desde la web.

## Roles: permisos por función

No es una escalera: cada rol tiene lo que su trabajo necesita. Se declara en un
solo lugar, `PERMISOS_POR_ROL` en `src/auth.php`, y se verifica dos veces: el
formulario no se dibuja si el rol no puede usarlo, y el envío se rechaza igual
aunque lo fuercen.

| | Administrador | Médico veterinario | Operario de manga |
|---|:-:|:-:|:-:|
| Pesajes y bajas | ✔ | ✔ | ✔ |
| Secados y notas en la ficha | ✔ | ✔ | ✔ |
| Partos | ✔ | ✔ | ✔ |
| Tratamientos, servicios y diagnósticos | ✔ | ✔ | — |
| Alimentación y traslados | ✔ | — | ✔ |
| Consultar insumos | ✔ | ✔ | — |
| Anular un registro mal cargado | ✔ | ✔ | — |
| Ver la bitácora | ✔ | ✔ | — |
| Alta de animales y corrección de fichas | ✔ | — | — |
| Ventas, lotes, compras, raciones, predios y usuarios | ✔ | — | — |
| Alertas de descarte | ✔ | ✔ | — |
| App de terreno | ✔ | ✔ | ✔ |
| Validar tratamientos (requiere registro MVA vigente) | ✔ | ✔ | — |

**Identificadores del SAG.** El **RUP** de cada predio se guarda siempre como
`09.1.01.0001` (se acepta con puntos, guiones o todo junto), no puede repetirse
entre los predios del propietario y aparece en los PDF exportados. Cada predio se
edita desde **Predios**, y el cambio queda en la bitácora. El **DIIO** se guarda
sin espacios y en mayúsculas, y la búsqueda lo encuentra aunque se tipee con
espacios. Su formato se puede exigir con `formato_diio` en `config.php` (una
expresión regular, vacía por defecto): con formato configurado, la web rechaza
un DIIO que no lo cumpla, y la app de terreno pregunta si lo escrito es un
número interno del campo antes de guardarlo así. Tiene que ir entre barras
(`'/^\d{9}$/'`): la app no entiende otros delimitadores. El EID del chip sigue exigiendo 15 dígitos.

**Médico Veterinario Autorizado (MVA).** Con la gestión avanzada encendida, cada
tratamiento queda *pendiente* hasta que lo valida un veterinario con registro
MVA vigente (RUT, número y fecha de vigencia). El administrador carga ese
registro en **Usuarios**, por organización, y nunca el suyo propio. Al validar se
copian el RUT y el número del MVA y se guarda una huella (HMAC-SHA256) de lo
firmado: qué producto, a qué animales, dosis, fecha y carencia. La huella se
calcula con una clave que vive en el servidor y no en la base (`clave_huella` en
`config.php` o, si no está, `datos/clave-huella.php`, que se crea sola): si
alguien cambia esos datos directo en la base, no puede rehacerla, y **Sanidad**
muestra el tratamiento como *Alterado*. Esa clave va en los respaldos; ver
[INSTALAR-CPANEL.md](INSTALAR-CPANEL.md#4-completar-configphp). El porqué de este
diseño está en [docs/adr/0001-validacion-mva-interna.md](docs/adr/0001-validacion-mva-interna.md). Un tratamiento validado solo lo puede anular otro MVA vigente.
También se valida desde la app de terreno (tarea «Validar tratamientos»), aun sin
señal: la validación queda en la cola del teléfono y el servidor vuelve a revisar
la vigencia del registro al recibirla. Esto
deja constancia dentro del sistema; no reemplaza la firma electrónica avanzada
ni los trámites ante el SAG.

## Qué cubre de la especificación

| Requerimiento | Dónde |
|---|---|
| Funcionamiento sin conexión, con sincronización automática al volver la señal | `app/`: lo registrado se guarda en el teléfono (IndexedDB) y se envía solo. Cada operación lleva un identificador único, así que un reintento no duplica nada. |
| Varios predios bajo el RUT del propietario | Pantalla **Predios** y selector en la barra. Hacienda, corrales, alimentación y reproducción muestran el predio elegido. |
| Uso en terreno o digitación posterior desde la libreta | La app es para el que anota en el momento; la web sirve para que otra persona digite después. Los roles separan quién puede cargar qué. |
| Usuario y contraseña para quien ingresa o modifica datos | Sesión con bcrypt, cierre a los 60 minutos sin actividad, y token propio de larga duración para la app. |
| Fechas de nacimiento, encaste, parto y secado | Hacienda (hito de nacimiento), Reproducción (servicio, parto y secado) y la app de terreno. |
| Bajas con el animal y la fecha, sin causa | Baja por muerte en Hacienda. La causa solo se pide con la gestión avanzada encendida. |
| Historial clínico en texto libre, con fecha | Bloque **Notas** en la ficha del animal, y tarea «Nota» en la app, con frases frecuentes para escribir menos. |
| Alimentación por lote o grupo, no por animal | Alimentación: la entrega va a un corral (el grupo) y, si hace falta, a un animal puntual. |
| Sin calendarios de vacunación ni registro de fármacos | Esos módulos quedan apagados con `gestion_avanzada` en false. |
| Sin inventario de bodega ni rentabilidad por animal | Ídem: insumos y lotes con cierre económico solo aparecen con la gestión avanzada. |

Con `gestion_avanzada` en true se suman, además: carencia con bloqueo de faena,
recetas con descuento de bodega, «Por reponer», y cierre de lote con margen neto.

### v1.4.0 — Automatizaciones

| Automatización | Dónde |
|---|---|
| **Bloqueo sanitario por resguardo** | Pantalla **Salidas y FMA** (`src/salidas.php`): venta, faena o traslado a otro predio por lista de DIIO. Un animal con un medicamento en período de resguardo a la fecha de la salida no sale, esté o no validado el tratamiento por el MVA; el mensaje dice el producto y desde qué día puede salir. La venta por lote también mira la fecha de la venta. |
| **Borrador de FMA y planilla para SIPEC Web** | Cada salida deja un PDF con los datos del Formulario de Movimiento Animal (origen y destino con RUP, transporte, DIIO) y un Excel con un DIIO por fila. El FMA oficial lo emite el SAG; la planilla hay que compararla con la plantilla vigente de SIPEC antes de subirla. |
| **Cálculo automático de raciones** | Alimentación → «Ración calculada por corral» (`src/dominio/raciones.php`): consumo de materia seca según peso, categoría y ganancia diaria (medida con los pesajes o la esperada por categoría), en kilos tal cual por día y por entrega, y kilos de cada ingrediente con los días de bodega que quedan. Si una entrega no trae kilos planificados, usa este cálculo. |
| **Descuento de bodega y alerta de stock** | Al confirmar una entrega o un tratamiento se descuenta la bodega, y el mensaje avisa en el momento si algún insumo quedó en su stock mínimo o por debajo. |
| **Exportación con filtros** | **Exportar**: inventario filtrable por categoría, sexo, etapa, corral, raza, fecha de nacimiento, peso, estado y resguardo, con atajos («Terneros nacidos en los últimos 30 días», «Aptos para venta»…); los demás listados por rango de fechas. Vista previa con el conteo, y descarga en **Excel (.xlsx)**, PDF o CSV. El Excel se arma sin librerías (`src/xlsx.php`). |
| **Lectura del DIIO sin tipeo** | `app/lector.js`, en la web y en la app de terreno: botón **Cámara** (código de barras/QR y, donde el navegador lo trae, OCR del número impreso con confirmación) y botón **Bastón RFID** por Bluetooth (puerto serie BLE). Los bastones en modo teclado escriben solos en el campo. Requiere Chrome o Edge y HTTPS; en iPhone, solo el bastón en modo teclado. |

## Estructura

```
php/
├── index.php              Front controller: web, API y archivos de la app
├── .htaccess              Ruteo y bloqueo de lo interno (Apache/cPanel)
├── config.ejemplo.php     Plantilla: copiarla como config.php (no se sube a git)
├── config.php             Único archivo a tocar al instalar
├── esquema.mysql.sql      Tablas para MySQL/MariaDB (producción)
├── esquema.sqlite.sql     Las mismas tablas para SQLite (desarrollo)
├── seed.php               Rehace la base con la demostración — solo por consola
├── estilos.css, app.js    Interfaz web
├── app/                   App de terreno (PWA): index.html, app.js, lector.js, app.css, sw.js, manifest
├── pruebas/               Pruebas de punta a punta (bloqueada por .htaccess)
└── src/                   Código (bloqueado por .htaccess)
    ├── arranque.php       Configuración, dirección base y chequeo de requisitos
    ├── db.php             Conexión MySQL/SQLite, creación de tablas, helpers
    ├── auth.php           Sesión, inactividad, roles y permisos
    ├── api.php            API REST de la app de terreno
    ├── operaciones.php    Reglas de las operaciones de terreno, compartidas web/API
    ├── formulario.php     CSRF, validación y armado de formularios
    ├── formato.php        Escapado, números y enlaces
    ├── demo.php           Datos de demostración
    ├── dominio/           Cálculos puros: carencia, GDP/FCR, cierre, reproducción
    ├── vistas/            Layout y páginas de error
    ├── pdf.php            Generador de PDF sin librerías: listados y fichas
    ├── xlsx.php           Generador de Excel (.xlsx) sin librerías
    ├── exportacion.php    Listados exportables y sus filtros
    ├── salidas.php        Venta, faena y traslado con bloqueo sanitario
    ├── documentos_sag.php Borrador de FMA y planilla para SIPEC Web
    └── paginas/           Una por sección del menú, la ficha del animal, los
                           predios, la bitácora y las exportaciones
```

## Decisiones

**MySQL en producción, SQLite para desarrollar.** Los dos esquemas tienen las
mismas tablas. Para que el SQL sirva en ambos, las fechas se calculan en PHP con
la zona horaria de Chile y se pasan como parámetro, y no se usan funciones
propias de un motor (`sql_no_negativo()` en vez de `MAX(a, b)`).

**App web instalable en vez de app nativa.** Se instala desde el navegador, sin
tiendas, y comparte el servidor con la plataforma. Guarda los datos en IndexedDB
(el SQLite del navegador) y un service worker la abre sin señal.

**La sesión web y la de la app son distintas.** La web se cierra a los 60
minutos sin actividad; la app no puede, porque trabaja días sin conexión: usa un
token de 30 días que el administrador puede invalidar quitándole el acceso a la
persona (el rol se relee de la membresía en cada sincronización).

**Datos con consecuencia legal se congelan.** Los días de carencia se copian al
tratamiento y el costo se copia a la entrega: corregir la ficha de un producto
mañana no cambia la habilitación de faena ni el cierre de un lote ya tratado.

**Toda consulta filtra por `organizacion_id`**, que sale de la sesión o del
token, nunca de lo que manda el cliente.

## Seguridad

- Contraseñas con **bcrypt**; consultas siempre con parámetros ligados; todo dato
  que llega a una vista pasa por `h()`.
- Login contra un hash de descarte si el correo no existe (no se pueden enumerar
  cuentas), token anti-CSRF y regeneración de la sesión al entrar.
- Cierre de sesión tras 60 minutos sin actividad, controlado en el servidor.
- Tokens de la app guardados como hash, con vencimiento y revocación.
- `.htaccess` bloquea `src/`, `pruebas/`, `config.php`, `seed.php`, los esquemas
  y los `.md`.

## Pruebas

`pruebas/punta-a-punta.sh` ejercita la web y la API contra un servidor en marcha:
ingreso, las trece pantallas, permisos por rol, peso anormal, descuento de
bodega, bloqueo de faena, partos, traslados, duplicados, idempotencia de la
sincronización, cierre por inactividad y, además, lo que se agregó después:
anulación con motivo y reversa de sus efectos, corrección de fichas, alta de
animales, gestión de accesos, bloqueo por intentos fallidos, bitácora,
exportación a planilla y a PDF —los cinco listados y la ficha de cada
animal—, y cambio de predio activo.

```bash
php seed.php && php -S localhost:8000 index.php &
bash pruebas/punta-a-punta.sh http://localhost:8000 .
```

Pasa completo con MariaDB y con SQLite, y en los dos modos: la suite detecta si
la gestión avanzada está encendida y, si no lo está, comprueba que sanidad,
insumos y lotes realmente no existan. Son 130 comprobaciones en modo avanzado y
115 en modo simple. También se corrió sobre Apache + PHP-FPM con el `.htaccess`
puesto, que es como queda en cPanel: ahí además se verifica que `config.php`,
`config.local.php`, el código de `src/`, los esquemas, la base SQLite, el
sembrado y las propias pruebas respondan 403.

## Seguridad

- Contraseñas con bcrypt de costo 12, y rehash automático al entrar si estaban
  guardadas con un costo menor.
- Ocho intentos fallidos bloquean ese correo por quince minutos; el intento y el
  bloqueo quedan en la bitácora.
- Token anti-CSRF en todo POST, incluidos el cambio de predio activo y la
  renovación de sesión, que por eso no funcionan por GET.
- Sesión de 60 minutos de inactividad, cerrada en el servidor y avisada en
  pantalla cinco minutos antes; al salir se borra la cookie.
- La app de terreno guarda el token hasheado, lo renueva mientras sincroniza y
  admite hasta tres teléfonos por persona: los anteriores se revocan solos.
- Cabeceras CSP, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy` y, cuando
  el sitio va por HTTPS, HSTS de un año.
- Bitácora de lo que se anuló, se corrigió, se exportó y de los accesos.

## Lo que falta

- Recuperar la contraseña por correo: hoy la reinicia un administrador y entrega
  una temporal en mano.
- Firma o sello de tiempo en las exportaciones, si alguna vez se usan como
  respaldo formal ante el SAG.
- Envío de los listados por correo desde el sistema.
