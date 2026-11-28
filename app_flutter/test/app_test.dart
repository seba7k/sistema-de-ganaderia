import 'package:drift/native.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:terreno/main.dart';
import 'package:terreno/src/api/cliente_api.dart';
import 'package:terreno/src/datos/almacenes.dart';
import 'package:terreno/src/datos/almacen_envios.dart';
import 'package:terreno/src/datos/base_local.dart';
import 'package:terreno/src/estado/estado.dart';
import 'package:terreno/src/estado/sincronizacion.dart';
import 'package:terreno/src/modelos/modelos.dart';
import 'package:terreno/src/modelos/tareas_animal.dart';

import 'datos_ejemplo.dart';

class ColaDePrueba extends AlmacenEnvios {
  ColaDePrueba(super.base);

  final registros = <(String, Map<String, dynamic>)>[];

  @override
  Future<void> agregar(Sesion sesion, String tipo, Map<String, dynamic> datos) async {
    registros.add((tipo, datos));
  }
}

void main() {
  late ServidorFalso servidor;
  late AlmacenSesionEnMemoria almacenSesion;
  late AlmacenLocalEnMemoria almacenLocal;

  test('el aviso de peso toma el último pendiente anterior y omite el rechazado', () {
    final animal = Catalogo.fromJson(catalogoEjemplo()).animales.first;
    const pendiente = Envio(
      orden: 1,
      uuid: 'prueba-pesaje',
      servidor: 'https://campo.cl',
      usuarioId: 3,
      organizacionId: 1,
      tipo: 'PESADA',
      datos: '{"animal_id":10,"fecha":"2026-11-03","peso_kg":500}',
      registradoEn: '2026-11-03 12:00:00',
      estado: 'PENDIENTE',
      requiereConfirmacion: false,
    );
    expect(avisoVariacionPeso(animal, [pendiente], '2026-11-03', 480, 2.5), contains('mismo día'));
    expect(avisoVariacionPeso(animal, [pendiente], '2026-10-31', 480, 2.5), isNull);
    expect(avisoVariacionPeso(
      animal, [pendiente.copyWith(estado: 'RECHAZADA')], '2026-11-03', 452.5, 2.5,
    ), isNull);
  });

  setUp(() {
    servidor = ServidorFalso()
      ..sesion = ((_) => json(sesionEjemplo()))
      ..catalogo = ((_) => json(catalogoEjemplo()))
      ..cerrar = ((_) => json({'ok': true}));
    almacenSesion = AlmacenSesionEnMemoria();
    almacenLocal = AlmacenLocalEnMemoria();
  });

  Future<void> abrirApp(WidgetTester tester, {AlmacenEnvios? cola}) async {
    await tester.pumpWidget(ProviderScope(
      overrides: [
        clienteApiProvider.overrideWithValue(ClienteApi(cliente: servidor.cliente)),
        almacenSesionProvider.overrideWithValue(almacenSesion),
        almacenLocalProvider.overrideWithValue(almacenLocal),
        enviosProvider.overrideWith((ref) => Stream.value([])),
        if (cola != null) almacenEnviosProvider.overrideWithValue(cola),
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

  Future<void> abrirTarea(WidgetTester tester, String tipo) async {
    final boton = find.byKey(Key('tarea-$tipo'));
    await tester.scrollUntilVisible(boton.hitTestable(), 200, scrollable: find.byType(Scrollable).first);
    await tester.pumpAndSettle();
    await tester.tap(boton);
    await tester.pumpAndSettle();
  }

  Future<void> buscar(WidgetTester tester, String texto) async {
    await tester.enterText(find.byKey(const Key('buscar-animal')), texto);
    await tester.pumpAndSettle();
  }

  Future<void> elegirAnimal(WidgetTester tester, int id) async {
    await tester.tap(find.byKey(Key('animal-$id')));
    await tester.pumpAndSettle();
  }

  Future<void> guardar(WidgetTester tester) async {
    final boton = find.byKey(const Key('guardar-animal'));
    await tester.scrollUntilVisible(boton.hitTestable(), 200, scrollable: find.byType(Scrollable).first);
    await tester.pumpAndSettle();
    await tester.tap(boton);
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

  testWidgets('pesaje encuentra por EID y encola el cambio anormal solo tras confirmarlo', (tester) async {
    final db = BaseLocal(NativeDatabase.memory());
    addTearDown(db.close);
    final cola = ColaDePrueba(db);
    await abrirApp(tester, cola: cola);
    await ingresar(tester);
    await abrirTarea(tester, 'PESADA');
    await buscar(tester, '982 000123456789');
    await elegirAnimal(tester, 10);

    await tester.enterText(find.byKey(const Key('fecha-animal')), '01/11/2026');
    await tester.enterText(find.byKey(const Key('peso-animal')), '500,5');
    await guardar(tester);
    expect(find.text('¿Confirmás el peso?'), findsOneWidget);
    expect(cola.registros, isEmpty);
    await tester.tap(find.text('Revisar'));
    await tester.pumpAndSettle();
    expect(cola.registros, isEmpty);
    await guardar(tester);
    await tester.tap(find.text('Confirmar peso'));
    await tester.pumpAndSettle();

    expect(cola.registros.single.$1, 'PESADA');
    expect(cola.registros.single.$2, {
      'animal_id': 10, 'fecha': '2026-11-01', 'peso_kg': 500.5,
      'motivo': 'CONTROL', 'confirmar': true,
    });
    expect(find.byKey(const Key('buscar-animal')), findsOneWidget);
  });

  testWidgets('nota, secado y parto usan el identificador y los campos del contrato', (tester) async {
    final db = BaseLocal(NativeDatabase.memory());
    addTearDown(db.close);
    final cola = ColaDePrueba(db);
    await abrirApp(tester, cola: cola);
    await ingresar(tester);
    await abrirTarea(tester, 'NOTA');
    await buscar(tester, ' t-12 ');
    await elegirAnimal(tester, 11);
    await tester.enterText(find.byKey(const Key('texto-nota')), ' Se observó cojera. ');
    await guardar(tester);
    expect(cola.registros.last.$1, 'NOTA');
    expect(cola.registros.last.$2, containsPair('animal_id', 11));
    expect(cola.registros.last.$2, containsPair('texto', 'Se observó cojera.'));

    await tester.tap(find.byType(BackButton));
    await tester.pumpAndSettle();
    await abrirTarea(tester, 'SECADO');
    await buscar(tester, 'T-12');
    expect(find.byKey(const Key('animal-11')), findsNothing);
    await buscar(tester, 'a-0143');
    await elegirAnimal(tester, 10);
    await guardar(tester);
    expect(cola.registros.last.$1, 'SECADO');
    expect(cola.registros.last.$2, containsPair('animal_id', 10));

    await tester.tap(find.byType(BackButton));
    await tester.pumpAndSettle();
    await abrirTarea(tester, 'PARTO');
    await buscar(tester, '0143');
    await elegirAnimal(tester, 10);
    await tester.tap(find.byKey(const ValueKey('Resultado')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Aborto').last);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('peso-animal')), findsNothing);
    await guardar(tester);
    expect(cola.registros.last.$1, 'PARTO');
    expect(cola.registros.last.$2, containsPair('vaca_id', 10));
    expect(cola.registros.last.$2, containsPair('resultado', 'ABORTO'));
    expect(cola.registros.last.$2, isNot(contains('peso_nacimiento')));

    await buscar(tester, 'A-0143');
    await elegirAnimal(tester, 10);
    await tester.tap(find.byKey(const ValueKey('Resultado')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Nacimiento vivo').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('Sexo de la cría')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Macho').last);
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('peso-animal')), '35,5');
    await tester.tap(find.text('Cría débil'));
    await tester.pumpAndSettle();
    await guardar(tester);
    expect(cola.registros.last.$2, containsPair('resultado', 'VIVO'));
    expect(cola.registros.last.$2, containsPair('sexo', 'MACHO'));
    expect(cola.registros.last.$2, containsPair('peso_nacimiento', 35.5));
    expect(cola.registros.last.$2, containsPair('ternero_debil', true));
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
