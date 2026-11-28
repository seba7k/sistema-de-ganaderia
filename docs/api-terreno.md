# API de la app de terreno — contrato

Es la API que usan la app de terreno web (`app/`) y la app en Flutter
(`app_flutter/`). **Este contrato no se cambia sin actualizar las dos apps**: lo
verifican las pruebas de `pruebas/contrato_api_prueba.php`, que le hablan al
servidor por HTTP igual que un teléfono.

## Generalidades

| Tema | Regla |
|---|---|
| Dirección | `https://DOMINIO/CARPETA/index.php?r=/api/<ruta>`. Con URL amigables también sirve `/api/<ruta>`, pero la forma con `?r=` funciona en cualquier hosting y es la que usan las apps. |
| Formato | JSON UTF-8 en el cuerpo y en la respuesta. `Content-Type: application/json`. |
| Autenticación | `Authorization: Bearer <token>` **y** `X-Token: <token>` (algunos hostings descartan `Authorization`; el servidor acepta cualquiera de las dos). El token son 64 caracteres hexadecimales. |
| Errores | Código HTTP distinto de 200 y cuerpo `{"error": "mensaje para mostrar"}`. |
| Vigencia del token | 30 días (`dias_sesion_app` en config.php). Cada llamada con token lo renueva. |
| Fechas | `AAAA-MM-DD`; fecha y hora `AAAA-MM-DD HH:MM:SS`, hora de Chile. |

Códigos de error comunes:

| Código | Cuándo | Qué hace la app |
|---|---|---|
| 400 | El cuerpo no es JSON válido o falta un dato obligatorio | Mostrar el error. |
| 401 | Credenciales incorrectas, falta el token o la sesión venció | Volver al ingreso **sin borrar la cola pendiente**. |
| 403 | El rol no tiene acceso a la app | Mostrar el error. |
| 404 | Ruta desconocida | — |
| 405 | Método equivocado (cabecera `Allow` indica el correcto) | — |
| 409 | La persona trabaja en más de una organización | Pedir que elija y reintentar con `organizacion_id`. |
| 413 | Más de 100 operaciones en un envío, o cuerpo de más de 2 MB | Partir el envío. |
| 503 | El servidor no tiene base de datos | Reintentar más tarde. |

## `POST /api/sesion` — ingresar

Cuerpo:

```json
{ "email": "operario@campo.cl", "password": "••••", "organizacion_id": 2 }
```

`organizacion_id` es opcional: solo se manda después de un 409.

Respuesta 200:

```json
{
  "token": "64 hex",
  "expira_en": "2026-12-27 10:00:00",
  "usuario": { "id": 3, "nombre": "Juan Pérez", "rol": "OPERARIO", "rol_nombre": "Operario", "organizacion": "Agrícola Los Robles", "organizacion_id": 2 },
  "operaciones": ["PESADA", "PARTO", "SECADO", "NOTA", "ENTREGA", "TRASLADO"]
}
```

`operaciones` son las tareas que el rol puede registrar: la app muestra solo esas.

Respuesta 409:

```json
{ "error": "Trabajás en más de una organización…", "organizaciones": [ { "id": 1, "nombre": "…", "rol": "Veterinario" } ] }
```

## `GET /api/catalogo` — datos para trabajar sin señal

Requiere token. Respuesta 200:

| Clave | Tipo | Contenido |
|---|---|---|
| `generado_en` | fecha y hora | Cuándo se armó. |
| `hoy` | fecha | Fecha del servidor. |
| `gdp_maxima` | número | Ganancia diaria (kg/día) sobre la cual un pesaje se considera anormal. |
| `formato_diio` | texto | Expresión regular del DIIO oficial (formato PHP, `/^…$/`); vacía si no hay formato. |
| `animales` | lista | Activos: `id`, `diio`, `eid`, `interno`, `sexo`, `categoria`, `etapa`, `raza`, `corral_id`, `peso`, `fecha_peso`, `carencia_hasta` (fecha desde la que puede salir, o null), `parto_probable`, `secado`. |
| `corrales` | lista | `id`, `nombre`, `tipo`, `capacidad`, `ocupacion`. |
| `raciones` | lista | `id`, `nombre`, `etapa`, `materia_seca` (%). |
| `productos` | lista | Veterinarios (vacía sin gestión avanzada): `id`, `nombre`, `unidad`, `carencia` (días), `stock`. |
| `usuario` | objeto | Igual que en `/sesion`. Flutter usa `organizacion_id` para identificar al dueño de los envíos pendientes. |
| `operaciones` | lista | Igual que en `/sesion`, con el rol vigente. |
| `mva` | objeto o null | Solo si el rol valida: `numero`, `rut`, `vigente_hasta`, `vigente`. |
| `por_validar` | lista | Solo si el rol valida: `id`, `fecha`, `producto`, `unidad`, `tipo`, `via`, `dosis`, `cabezas`, `carencia`, `diagnostico`, `donde`, `cargo`. Hasta 200. |
| `por_validar_total` | número | Solo si el rol valida: total de pendientes. |

## `POST /api/sincronizar` — enviar lo registrado

Requiere token. Cuerpo:

```json
{ "operaciones": [ { "uuid": "uuid v4", "tipo": "PESADA", "datos": { … }, "registrado_en": "2026-11-27 09:15:00" } ] }
```

- Hasta 100 operaciones por envío.
- `uuid` lo genera el teléfono una vez por operación y **no cambia en los reintentos**: el servidor no guarda dos veces el mismo.
- Cada operación se procesa en su propia transacción: una rechazada no frena a las demás.

Respuesta 200:

```json
{ "resultados": [ { "uuid": "…", "estado": "OK", "mensaje": "…", "id": 123, "alerta": null } ], "servidor_hora": "2026-11-27 09:16:02" }
```

| `estado` | Significado | Qué hace la app |
|---|---|---|
| `OK` | Guardada. Trae `id` y puede traer `alerta` (texto para mostrar). | Sacarla de la cola. |
| `DUPLICADA` | Ya estaba guardada (reintento). | Sacarla de la cola. |
| `RECHAZADA` | No cumple una regla. Trae `mensaje`, `errores` (campo → mensaje) y `requiere_confirmacion`. | Dejarla visible para corregir o descartar. Si `requiere_confirmacion` es true, se puede reenviar con `"confirmar": true`. No se reintenta sola. |
| `ERROR` | Falla del servidor. | Reintentar en el próximo envío. |

### Operaciones

Los animales se identifican por `animal_id` (el `id` del catálogo) o por
`identificacion` (DIIO, EID o número interno). Las listas de animales van como
lista de identificaciones.

| `tipo` | Permiso | `datos` |
|---|---|---|
| `PESADA` | registrar_pesada | `animal_id`, `fecha`, `peso_kg`, `motivo` (CONTROL, NACIMIENTO, DESTETE, ENCASTE, SALIDA), `confirmar` (bool) |
| `PARTO` | registrar_parto | `vaca_id`, `fecha`, `resultado` (VIVO, MUERTO, ABORTO); si nace: `sexo`, `peso_nacimiento`, `ternero_debil` (bool). Opcionales: `dificultad`, `observaciones` |
| `SECADO` | registrar_secado | `animal_id`, `fecha` |
| `NOTA` | registrar_nota | `animal_id`, `fecha`, `texto` |
| `ANIMAL` | registrar_animal | `caravana_visual` o `numero_interno` (o `eid`), `sexo`, `categoria`, `etapa`, `origen`, `fecha_ingreso`, `peso_ingreso_kg`; opcionales `corral_id`, `fecha_nacimiento`, `raza` |
| `TRATAMIENTO` | registrar_tratamiento (gestión avanzada) | `insumo_id`, `fecha`, `dosis`, `tipo`, `via`, `diagnostico`, y `animal_id` **o** `corral_id` |
| `ENTREGA` | registrar_entrega | `racion_id`, `fecha`, `cantidad`, `unidad` (KG, T), `base` (TAL_CUAL, MATERIA_SECA), `numero_entrega`, y `animal_id` **o** `corral_id`; opcional `kg_planificados` (si falta, el servidor lo calcula) |
| `TRASLADO` | registrar_traslado | `destino_id` (corral), `fecha`, `identificaciones` (lista) |
| `BAJA` | registrar_baja | `animal_id` o `identificacion`, `fecha`; opcional `causa`. Muerte del animal: sale del inventario y se descuenta de su lote. (La app web no tiene esta tarea; la app Flutter la incluye.) |
| `VALIDACION` | validar_tratamiento (gestión avanzada) | `tratamiento_id` |
| `SALIDA` | registrar_venta | `tipo` (VENTA, FAENA, TRASLADO), `fecha`, `identificaciones` (DIIO), `destino_rup` (9 dígitos), opcionales `destino_nombre`, `comprador`, `transportista`, `patente`. El servidor rechaza animales en resguardo. |

## `POST /api/sesion/cerrar` — cerrar la sesión

Requiere token. Revoca el token. Respuesta 200: `{ "ok": true }`.
