# Terreno — app Android de Gestión ganadera

App de terreno en Flutter: la manga y el corral, sin señal, con sincronización
automática. Habla con el servidor PHP por la **misma API** que la app web
(`../app/`); el contrato está en [`../docs/api-terreno.md`](../docs/api-terreno.md)
y el plan de construcción en [`../docs/planes/2026-11-27-app-flutter.md`](../docs/planes/2026-11-27-app-flutter.md).

Esta carpeta **no se sube al hosting**: el `.htaccess` la bloquea igual.

## Estado

| Etapa | Contenido | Estado |
|---|---|---|
| 1 | Ingreso (con elección de organización), token seguro, catálogo guardado, inicio con las tareas del rol, cerrar sesión | Hecha |
| 2 | Cola sin señal y sincronización | Hecha |
| 3 | Búsqueda por DIIO, EID o número interno, pesaje, nota, secado y parto | Hecha |
| 4 a 6 | Tareas de manejo, validación MVA, salida, cámara y bastón RFID | Pendiente |

Para habilitar los envíos, el servidor debe incluir `usuario.organizacion_id`
en las respuestas de sesión y catálogo. Las sesiones guardadas por la etapa 1
obtienen el identificador al actualizar los datos del campo. La app intenta
sincronizar al abrir o volver a la pantalla y cada 30 segundos mientras está
activa y quedan registros por enviar. También se puede sincronizar desde **Envíos**.

Desde **Inicio**, elegí una tarea, buscá el animal en el catálogo guardado y
registrá los datos. El pesaje pide confirmar un cambio anormal según el
catálogo y los pesajes pendientes. Si al sincronizar el servidor compara con
un pesaje más reciente, puede solicitar la confirmación en **Envíos**.

## Estructura

```
lib/
├── main.dart                 Arranque: ingreso o inicio según la sesión
└── src/
    ├── api/cliente_api.dart  Cliente de la API (index.php?r=/api/…, token en Authorization y X-Token)
    ├── modelos/modelos.dart  Sesión, catálogo, animales, corrales… (según el contrato)
    ├── datos/                Sesión cifrada (flutter_secure_storage) y base local (Drift)
    ├── estado/estado.dart    Sesión y catálogo (Riverpod)
    ├── pantallas/            Ingreso e inicio
    └── tareas.dart           Las 11 tareas de terreno
```

## Desarrollo

```bash
flutter pub get
dart run build_runner build      # regenera la base local (Drift) si cambian sus tablas
flutter analyze
flutter test
```

Prueba contra el servidor PHP real (con `INICIAR-GANADERIA.bat` corriendo, en modo demo):

```bash
flutter test test/integracion_servidor_test.dart --dart-define=SERVIDOR=http://localhost:8000
```

Correr en un teléfono o emulador:

```bash
flutter run
```

Desde el emulador de Android, la dirección del servidor local es
`http://10.0.2.2:8000`. Solo la versión de desarrollo acepta direcciones sin
HTTPS; la de producción exige HTTPS.
