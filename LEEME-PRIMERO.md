# Gestión ganadera — leeme primero

Sistema para llevar el registro de animales de un campo: inventario individual,
reproducción, alimentación, pesajes y notas, con una app para usar en el corral
**sin señal** que después sincroniza sola.

Está hecho en PHP y anda en cualquier hosting con cPanel. No necesita Composer,
ni Node, ni nada instalado: se sube un zip, se descomprime y funciona.

---

## Lo más rápido: verlo funcionando

Si lo subís tal cual viene, arranca con **datos de demostración** (un campo de
ejemplo con animales, partos y entregas cargadas) y estas cuentas:

| Cuenta | Contraseña | Qué puede hacer |
|---|---|---|
| `admin@ganaderia.cl` | `demo1234` | Todo: alta de animales, ventas, usuarios, predios |
| `vet@ganaderia.cl` | `demo1234` | Reproducción, sanidad, pesajes, notas |
| `operario@ganaderia.cl` | `demo1234` | Pesajes, partos, alimentación, traslados |

Con eso podés recorrer el sistema sin cargar nada. **Ojo: mientras esté así,
cualquiera que conozca la dirección puede entrar con esas cuentas.** Más abajo
está cómo apagarlo.

---

## Instalarlo (unos 15 minutos)

Los pasos con capturas y detalle están en **INSTALAR-CPANEL.md**. Resumido:

1. **PHP 8.1 o más nuevo**, en cPanel → *Select PHP Version*. Que estén
   marcadas las extensiones `pdo_mysql` y `mbstring`.
2. **Crear la base MySQL**, en cPanel → *Bases de datos MySQL*: creá la base,
   creá el usuario, y agregá el usuario a la base **con todos los privilegios**.
   Anotá los tres datos (nombre de la base, usuario y contraseña): cPanel les
   pone adelante el prefijo de tu cuenta.
3. **Subir el zip** al *Administrador de archivos*, dentro de `public_html` o de
   una subcarpeta (por ejemplo `public_html/ganaderia`), y extraerlo ahí mismo.
   El zip no trae carpeta adentro: se descomprime directo donde estés parado.
4. **Completar `config.php`** con los tres datos del paso 2. Es el único archivo
   que se toca.
5. **Abrir el sitio** (`tudominio.cl/ganaderia`) y entrar con `admin@ganaderia.cl`.
   La primera vez el sistema crea las tablas solo.

---

## Antes de usarlo con animales de verdad

1. En `config.php`, poner `'demo' => false`.
2. Entrar al sitio: como no hay usuarios, aparece la pantalla **Primera
   configuración** para crear el dueño del campo con su propia contraseña.
3. Cargar los predios en **Predios** y los corrales en **Corrales**.
4. Dar de alta a la gente en **Usuarios** (el sistema entrega una contraseña
   temporal para cada uno).
5. Poner **HTTPS** (en cPanel, *SSL/TLS Status* → AutoSSL). Sin HTTPS la app del
   teléfono no se puede instalar.

Si ya habías jugado con los datos de demostración, en `INSTALAR-CPANEL.md` está
cómo vaciar la base para empezar limpio.

---

## Los dos modos

En `config.php` hay un interruptor: `'gestion_avanzada'`.

- **`false` (como viene)** — crianza: hacienda, corrales, reproducción,
  alimentación, pesajes y notas. Es lo que necesita un campo normal.
- **`true`** — suma sanidad con períodos de carencia, bodega de insumos, y
  lotes con cierre económico. Para engorda a corral.

Se puede cambiar cuando sea: no se pierde nada, los módulos se esconden y
vuelven a aparecer.

---

## La app del corral

Desde el menú, **App de terreno**. Se abre en el teléfono y el navegador ofrece
*Agregar a pantalla de inicio*: queda como una app más.

Sirve **sin señal**. Lo que se carga en el corral queda guardado en el teléfono
y se manda solo cuando vuelve la conexión — abajo se ve cuántos registros hay
pendientes. Registra pesajes, partos, secados, notas, alimentación, traslados,
bajas y animales nuevos.

---

## Llevarse los datos

- **Exportar** (en el menú): cada listado sale en **planilla** para abrir en
  Excel, o en **PDF** para imprimir o entregar.
- En la ficha de cualquier animal, **Descargar la ficha en PDF**: toda su
  historia en una hoja.

---

## Si algo no anda

| Pasa esto | Es por esto |
|---|---|
| Pantalla en blanco o error 500 | Los datos de `config.php` no coinciden con los de cPanel, o falta agregar el usuario a la base con todos los privilegios. |
| «Not Found» en todas las páginas menos la primera | El hosting no tiene `mod_rewrite`. En `config.php` poner `'url_amigables' => false`. |
| La app no ofrece instalarse | Falta HTTPS, o se abrió en una ventana de incógnito. |
| Entra pero no se ve nada cargado | Está en otro predio: el selector está arriba, al lado del nombre del campo. |
| «Demasiados intentos fallidos» | Ocho contraseñas erradas seguidas bloquean esa cuenta 15 minutos. Se espera y listo. |

---

## Qué hay adentro

```
index.php        Punto de entrada
config.php       Lo único que se edita
src/             Código: operaciones, API, páginas, generador de PDF
app/             La app de terreno (PWA)
esquema.*.sql    Las tablas (MySQL y SQLite)
README.md        Cómo está hecho y por qué
INSTALAR-CPANEL.md  La instalación con todo el detalle
```

Proyecto de título. Cualquier duda, el `README.md` explica las decisiones de
diseño y lo que cubre cada módulo.
