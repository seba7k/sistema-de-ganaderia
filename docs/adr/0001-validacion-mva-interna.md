# ADR-0001: Validación MVA interna con huella HMAC, sin firma electrónica avanzada

- **Estado:** Aceptada
- **Fecha:** 2026-11-25
- **Decisión de:** @seba7k (dueño del repositorio)
- **Confianza:** Media (falta confirmar si algún trámite exige firma con valor legal)

## Decisión

**La validación de tratamientos por un Médico Veterinario Autorizado (MVA) es un
registro interno del sistema, firmado con una huella HMAC-SHA256 cuya clave vive
en el servidor; no se integra firma electrónica avanzada.** Deja constancia de qué
veterinario respondió por cada tratamiento y detecta cambios posteriores, sin
depender de un proveedor externo ni de que haya señal en el campo.

## Contexto

Con la gestión avanzada, los tratamientos tienen producto, dosis y carencia, y la
carencia bloquea la faena. Se pidió que un MVA acreditado ante el SAG valide esos
eventos. El dueño del proyecto definió como límite de esta etapa: *no integrar
firma electrónica avanzada ni servicios externos*. Además, la app de terreno
trabaja sin señal, así que la validación tiene que poder quedar en la cola del
teléfono y confirmarse al sincronizar.

## Qué se guarda al validar

- Quién validó (`validado_por`) y cuándo (`validado_en`, hora del servidor al recibir).
- Una **copia** del RUT y del número MVA de ese momento, para que cambiar después
  el registro del veterinario no cambie validaciones ya hechas.
- La huella: HMAC-SHA256 de producto, animales, dosis, fecha, carencia y los datos
  de la validación (`huella_tratamiento()` en `src/dominio/mva.php`). La clave sale
  de `clave_huella` en `config.php` o de `datos/clave-huella.php`.
- Reglas: solo valida quien tiene un registro MVA vigente en esa organización;
  nadie se carga su propio registro; un tratamiento validado solo lo anula otro
  MVA vigente (`ANULABLES['TRATAMIENTO']['firma']` en `src/operaciones.php`).

## Candidatos

### A. Huella SHA-256 sin clave
- Fue la primera implementación de esta rama.
- Descartada: la revisión de seguridad mostró que quien puede escribir en la base
  también puede recalcular la huella con los mismos datos de la fila.

### B. Huella HMAC-SHA256 con clave fuera de la base (elegida)
- Sin la clave no se puede rehacer una huella válida desde la base.
- Costo: la clave hay que respaldarla. Si se pierde, todo lo validado se ve como
  «Alterado» (no se pierde ningún dato, pero se pierde la verificación).

### C. Firma electrónica avanzada (proveedor acreditado)
- Daría valor legal a la firma de cada veterinario.
- No se evaluó técnicamente: quedó fuera por decisión del dueño en esta etapa.
  Requiere un proveedor externo, credenciales por veterinario y conexión al firmar,
  lo que choca con el uso sin señal de la app.

## Consecuencias

- La validación funciona en la web y en la app, también sin señal.
- Se detecta un cambio hecho directo en la base por quien no tiene la clave.
- **No** protege contra quien tenga a la vez la base y la clave (por ejemplo, un
  acceso completo al servidor), ni reemplaza trámites ante el SAG.
- Un administrador puede reiniciar la contraseña de otra cuenta y actuar como
  ella. Eso queda en la bitácora, pero la regla «nadie se carga su propio MVA» es
  de auditoría, no de prevención. Queda como decisión de producto pendiente.
- La clave pasa a ser parte del respaldo (ver `INSTALAR-CPANEL.md`, paso 4).

## Cuándo revisar esta decisión

- Si algún trámite del SAG o de un comprador exige la firma del MVA con valor legal.
- Si el sistema pasa a tener veterinarios externos que no confían en el
  administrador del servidor.
- Si se pierde la clave en producción, o se necesita rotarla (hoy no hay rotación).
