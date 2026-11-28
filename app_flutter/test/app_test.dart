import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:terreno/main.dart';
import 'package:terreno/src/api/cliente_api.dart';
import 'package:terreno/src/datos/almacenes.dart';
import 'package:terreno/src/estado/estado.dart';
import 'package:terreno/src/estado/sincronizacion.dart';
import 'package:terreno/src/modelos/modelos.dart';

import 'datos_ejemplo.dart';

void main() {
  late ServidorFalso servidor;
  late AlmacenSesionEnMemoria almacenSesion;
  late AlmacenLocalEnMemoria almacenLocal;

  setUp(() {
    servidor = ServidorFalso()
      ..sesion = ((_) => json(sesionEjemplo()))
      ..catalogo = ((_) => json(catalogoEjemplo()))
      ..cerrar = ((_) => json({'ok': true}));
    almacenSesion = AlmacenSesionEnMemoria();
    almacenLocal = AlmacenLocalEnMemoria();
  });

  Future<void> abrirApp(WidgetTester tester) async {
    await tester.pumpWidget(ProviderScope(
      overrides: [
        clienteApiProvider.overrideWithValue(ClienteApi(cliente: servidor.cliente)),
        almacenSesionProvider.overrideWithValue(almacenSesion),
        almacenLocalProvider.overrideWithValue(almacenLocal),
        enviosProvider.overrideWith((ref) => Stream.value([])),
      ],
      child: const AppTerreno(),
    ));
    await tester.pumpAndSettle();
  }

  Future<void> ingresar(WidgetTester tester) async {
    await tester.enterText(find.byKey(const Key('campo-servidor')), 'tucampo.cl/ganaderia');
    await tester.enterText(find.byKey(const Key('campo-email')), 'juan@campo.cl');
    await tester.enterText(find.byKey(const Key('campo-password')), 'secreta');
    await tester.tap(find.byKey(const Key('boton-ingresar')));
    await tester.pumpAndSettle();
  }

  testWidgets('sin sesión muestra el ingreso y valida los campos', (tester) async {
    await abrirApp(tester);
    expect(find.text('Ingresar'), findsOneWidget);
    await tester.tap(find.byKey(const Key('boton-ingresar')));
    await tester.pumpAndSettle();
    expect(find.text('Escribí tu correo.'), findsOneWidget);
    expect(servidor.pedidos, isEmpty);
  });

  testWidgets('ingresa, descarga el catálogo y muestra solo las tareas del rol', (tester) async {
    await abrirApp(tester);
    await ingresar(tester);

    expect(find.text('Hola, Juan'), findsOneWidget);
    expect(find.text('Ganadera Los Robles'), findsOneWidget);
    expect(find.byKey(const Key('tarea-PESADA')), findsOneWidget);
    expect(find.byKey(const Key('tarea-BAJA')), findsOneWidget);
    expect(find.byKey(const Key('tarea-SALIDA')), findsNothing, reason: 'el operario no vende');
    expect(find.byKey(const Key('tarea-VALIDACION')), findsNothing);
    expect(find.text('2'), findsOneWidget, reason: 'animales del catálogo');

    expect(almacenSesion.sesion!.servidor, 'https://tucampo.cl/ganaderia');
    expect(almacenSesion.email, 'juan@campo.cl');
    expect(almacenLocal.catalogo, isNotNull, reason: 'queda guardado para trabajar sin señal');
  });

  testWidgets('con varias organizaciones pide elegir y entra con la elegida', (tester) async {
    servidor.sesion = (p) => p.body.contains('organizacion_id')
        ? json(sesionEjemplo())
        : json({
            'error': 'Trabajás en más de una organización: elegí con cuál vas a trabajar.',
            'organizaciones': [
              {'id': 1, 'nombre': 'Ganadera Los Robles', 'rol': 'Operario'},
              {'id': 2, 'nombre': 'Organización Demo', 'rol': 'Operario'},
            ],
          }, 409);
    await abrirApp(tester);
    await ingresar(tester);

    expect(find.textContaining('más de una organización'), findsOneWidget);
    await tester.tap(find.byKey(const Key('organizacion-2')));
    await tester.pumpAndSettle();
    expect(find.text('Hola, Juan'), findsOneWidget);
    expect(servidor.pedidos.where((p) => p.body.contains('"organizacion_id":2')), hasLength(1));
  });

  testWidgets('contraseña equivocada: muestra el mensaje del servidor', (tester) async {
    servidor.sesion = (_) => json({'error': 'Correo o contraseña incorrectos.'}, 401);
    await abrirApp(tester);
    await ingresar(tester);
    expect(find.text('Correo o contraseña incorrectos.'), findsOneWidget);
  });

  testWidgets('permite abrir Envíos desde inicio y volver', (tester) async {
    await abrirApp(tester);
    await ingresar(tester);

    await tester.tap(find.byTooltip('Envíos'));
    await tester.pumpAndSettle();
    expect(find.text('No hay registros pendientes.'), findsOneWidget);
    expect(tester.widget<FilledButton>(find.byWidgetPredicate((widget) => widget is FilledButton)).onPressed, isNull);

    await tester.tap(find.byType(BackButton));
    await tester.pumpAndSettle();
    expect(find.text('Hola, Juan'), findsOneWidget);
  });

  testWidgets('sin señal sigue con el catálogo guardado y lo avisa', (tester) async {
    almacenSesion.sesion = Sesion.fromJson(sesionEjemplo(), servidor: 'https://tucampo.cl');
    almacenLocal.catalogo = Catalogo.fromJson(catalogoEjemplo());
    servidor.catalogo = (_) => throw http.ClientException('sin red');
    await abrirApp(tester);

    expect(find.text('Hola, Juan'), findsOneWidget, reason: 'abre sin pedir nada al servidor');
    expect(servidor.pedidos, isEmpty);
    await tester.tap(find.byKey(const Key('boton-actualizar')));
    await tester.pumpAndSettle();
    expect(find.textContaining('Seguís trabajando con los datos guardados'), findsOneWidget);
    expect(almacenLocal.catalogo, isNotNull);
  });

  testWidgets('si la sesión venció (401) vuelve al ingreso sin borrar los datos', (tester) async {
    almacenSesion.sesion = Sesion.fromJson(sesionEjemplo(), servidor: 'https://tucampo.cl');
    almacenLocal.catalogo = Catalogo.fromJson(catalogoEjemplo());
    servidor.catalogo = (_) => json({'error': 'La sesión de la app venció.'}, 401);
    await abrirApp(tester);
    await tester.tap(find.byKey(const Key('boton-actualizar')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('boton-ingresar')), findsOneWidget);
    expect(almacenSesion.sesion, isNull);
    expect(almacenLocal.catalogo, isNotNull, reason: 'lo del teléfono no se pierde por una sesión vencida');
  });

  testWidgets('si el rol cambió en el servidor, las tareas se actualizan al descargar', (tester) async {
    almacenSesion.sesion = Sesion.fromJson(sesionEjemplo(), servidor: 'https://tucampo.cl');
    servidor.catalogo = (_) => json(catalogoEjemplo(operaciones: ['NOTA']));
    await abrirApp(tester);
    expect(find.byKey(const Key('tarea-NOTA')), findsOneWidget);
    expect(find.byKey(const Key('tarea-PESADA')), findsNothing);
  });

  testWidgets('cerrar sesión avisa al servidor y borra los datos del teléfono', (tester) async {
    await abrirApp(tester);
    await ingresar(tester);
    await tester.tap(find.byKey(const Key('menu-inicio')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Cerrar sesión').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('confirmar-salida')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('boton-ingresar')), findsOneWidget);
    expect(servidor.pedidos.any((p) => p.url.queryParameters['r'] == '/api/sesion/cerrar'), isTrue);
    expect(almacenSesion.sesion, isNull);
    expect(almacenLocal.catalogo, isNull);
  });
}
