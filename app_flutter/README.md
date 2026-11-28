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
| 2 | Cola sin señal y sincronización | Pendiente |
| 3 a 6 | Tareas, validación MVA, salida, lectura con cámara y bastón RFID | Pendiente |

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
