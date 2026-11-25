# Plan v1.4.0 — Automatizaciones

Fecha: 2026-11-25. Pedido del dueño: bloqueo sanitario por resguardo, filtros de
exportación (PDF / Excel), cálculo automático de raciones, aviso de stock al
entregar, borrador de FMA y planilla para SIPEC Web, y captura del DIIO sin tipeo.

## Lo que ya existía (no se rehace)

- RUP y DIIO normalizados (v1.2.0), validación MVA con huella (v1.1.0).
- Descuento de bodega al confirmar una entrega de alimento o un tratamiento.
- Cálculo del retiro (`dominio/retiros.php`) y columna «Faena» en Hacienda.
- Venta por lote (`paginas/lotes.php`) que ya excluye animales en carencia.

## Decisiones tomadas (llamadas mías, corregibles)

1. **El resguardo corre desde la fecha del tratamiento**, aunque el MVA aún no lo
   haya validado: el residuo empieza con la aplicación, no con la firma. Un
   tratamiento pendiente de validación bloquea igual (es lo más seguro).
2. **El bloqueo se evalúa a la fecha de la operación**, no a la de hoy: una venta
   fechada antes de que venza el retiro se rechaza.
3. «Traslado» en el sentido del SAG es salir del predio (lleva FMA). El traslado
   entre corrales del mismo campo no mueve la carne a ningún lado y no se bloquea.
4. Las raciones se calculan con un modelo transparente de consumo de materia seca
   como % del peso vivo según categoría, ajustado por la ganancia diaria. Es una
   estimación de manejo, no reemplaza la formulación de un nutricionista.
5. El Excel se genera como `.xlsx` real, sin librerías ni la extensión `zip`
   (no siempre está en cPanel): se escribe el ZIP a mano.
6. La planilla SIPEC sigue las columnas del formulario de movimiento. El SAG
   cambia sus plantillas de carga: se deja marcado para verificar contra la vigente.
7. OCR desde el celular usa el detector de texto del navegador cuando existe; el
   código de barras y el bastón RFID funcionan en Chrome para Android.

## Fases (en orden)

### Fase 1 — Salidas de animales con bloqueo sanitario
- Tablas `salidas` y `detalles_salida` (+ migración).
- `op_salida`: venta, faena o traslado a otro predio, por lista de DIIO.
  Rechaza animales con retiro vigente a la fecha de salida (dice cuál, por qué
  producto y desde cuándo puede salir), exige DIIO y RUP de destino.
  Si el RUP de destino es un predio propio, el animal pasa a ese predio.
- Venta por lote: evaluar la carencia a la fecha de la venta.
- Pantalla `/salidas` con el formulario y el historial; operación `SALIDA` en la API.
- Pruebas: `salidas_prueba.php`.

### Fase 2 — Exportación con filtros, PDF y Excel
- `src/xlsx.php`: generador de `.xlsx` sin dependencias.
- Filtros del inventario: categoría, sexo, etapa, corral, raza, nacidos desde /
  hasta, peso mínimo / máximo, con atajos («Terneros nacidos en los últimos 30
  días»). Los demás listados: rango de fechas.
- Vista previa con conteo antes de descargar; formatos CSV, Excel y PDF.
- Pruebas: `exportar_prueba.php` (filtros y xlsx válido).

### Fase 3 — Cálculo automático de raciones + aviso de stock
- `src/dominio/raciones.php`: consumo de MS por animal; ganancia diaria medida
  (últimos dos pesajes) o la esperada por categoría.
- Alimentación: tabla «Ración calculada por corral» (cabezas, peso medio, GDP,
  kg MS/día, kg tal cual/día, por entrega, kg de cada ingrediente y días de
  bodega que quedan).
- `op_entrega`: si no se cargan kilos planificados, usa el cálculo.
- `op_entrega` y `op_tratamiento`: avisan en el mensaje cuando un insumo queda
  en o bajo su stock mínimo.
- Pruebas: `raciones_prueba.php`.

### Fase 4 — FMA y planilla SIPEC
- Borrador de FMA en PDF por cada salida (origen, destino, RUP, transporte,
  lista de DIIO con categoría y sexo).
- Planilla Excel para SIPEC Web por salida.
- Pruebas dentro de `salidas_prueba.php`.

### Fase 5 — Captura del DIIO sin tipeo
- `lector.js` (web y app de terreno): botón de cámara (código de barras con
  `BarcodeDetector`, OCR con `TextDetector` si existe) y botón de bastón RFID
  (Web Bluetooth, servicio UART); también los bastones que escriben como teclado.
- Interpreta el número ISO 11784 de 15 dígitos y lo deja en el campo.
- CSP: sin cambios (todo es del mismo sitio). Service worker: nueva versión.

## Verificación
`php pruebas/correr.php` en verde después de cada fase, y recorrido en el
navegador con los datos de demostración.
