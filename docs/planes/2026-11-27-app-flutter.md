# Plan — App de terreno en Flutter

Fecha: 2026-11-27. Pasar la app de terreno (hoy una PWA en `app/`) a Flutter,
usando las rutas y operaciones de la API existente.

## Objetivo

Una app Android nativa para la manga y el corral que haga lo mismo que la PWA
—trabajar días sin señal y sincronizar sola— con mejor acceso al hardware: bastón
RFID por Bluetooth, cámara con lectura de códigos y del número impreso, y una base
local más robusta.

## Decisiones

| Tema | Decisión | Por qué |
|---|---|---|
| API | `/api/sesion`, `/api/catalogo`, `/api/sincronizar`, `/api/sesion/cerrar` | El servidor resuelve reglas, permisos e idempotencia. La etapa 2 agregó `usuario.organizacion_id` en sesión y catálogo para aislar la cola por organización. |
| Plataforma | Android primero | Se compila en Windows. iPhone necesita un Mac; queda para después. |
| Repositorio | Mismo repo, carpeta `app_flutter/` | API, pruebas de contrato y app quedan juntas. La carpeta no se sube al hosting. |
| Convivencia | La PWA sigue funcionando hasta que la app Flutter esté probada en terreno | No se pierde nada mientras se construye. |
| Estado | Riverpod | Estándar actual, fácil de probar. |
| Base local | Drift (SQLite) | Catálogo y cola sin señal, con consultas y migraciones. |
| Token | flutter_secure_storage | No queda en texto plano en el teléfono. |
| Bluetooth | flutter_blue_plus (UART BLE) + bastones en modo teclado | Los mismos servicios que ya reconoce `lector.js`. |
| Cámara | mobile_scanner + ML Kit (texto) | Código de barras/QR exacto; número impreso con confirmación. |
| Servidor | Dirección configurable en el ingreso | La app ya no vive dentro del sitio. |

## Etapas

Cada etapa termina con pruebas en verde, commit con el formato de `commit.md` y push.

| # | Etapa | Resultado |
|---|---|---|
| 0 | Contrato de la API | `docs/api-terreno.md` con cada ruta, cabecera, respuesta y operación; pruebas de contrato por HTTP en `pruebas/contrato_api_prueba.php`. |
| 1 | Base | Proyecto Flutter, configuración del servidor, ingreso (con elección de organización), token seguro, descarga del catálogo, inicio con las tareas del rol, cerrar sesión. |
| 2 | Sin señal | Cola local con uuid, sincronización automática y manual (lotes de 100), estados OK/DUPLICADA/RECHAZADA/ERROR, pantalla de envíos, sesión vencida (401) sin perder lo pendiente. |
| 3 | Tareas por animal | Búsqueda por DIIO/EID/interno; pesaje (con aviso de variación anormal y confirmación), nota, secado, parto. |
| 4 | Tareas de manejo | Alimentación, tratamiento (animal o corral, con stock y carencia), traslado, alta de animal (formato DIIO) y baja por muerte (la API ya la acepta; la PWA no la tenía). |
| 5 | Firma y salida | Validación MVA; salida del predio con bloqueo por resguardo. |
| 6 | Lectura sin tipeo | Cámara (código y OCR) y bastón RFID (BLE y modo teclado). |
| 7 | Entrega | Pruebas de reglas y flujos, APK firmado, guía de instalación, documentación actualizada. |

## Criterio para retirar la PWA

Cuando la app Flutter cubra las 11 tareas, haya pasado un período de uso real en
terreno sin pérdida de datos, y todos los teléfonos del equipo la tengan instalada.
