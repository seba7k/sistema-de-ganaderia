import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:terreno/src/api/cliente_api.dart';
import 'package:terreno/src/modelos/modelos.dart';

import 'datos_ejemplo.dart';

void main() {
  group('dirección del servidor', () {
    test('se normaliza con esquema y sin index.php ni barra final', () {
      expect(ClienteApi.normalizarServidor('tucampo.cl/ganaderia/'), 'https://tucampo.cl/ganaderia');
      expect(ClienteApi.normalizarServidor(' https://tucampo.cl/index.php?r=/ '), 'https://tucampo.cl');
      expect(ClienteApi.normalizarServidor('http://10.0.2.2:8000'), 'http://10.0.2.2:8000');
      expect(ClienteApi.normalizarServidor(''), isNull);
      expect(ClienteApi.normalizarServidor('https://'), isNull);
    });

    test('las rutas van por index.php?r=/api/…, que funciona en cualquier hosting', () {
      expect(ClienteApi.urlDe('https://tucampo.cl/ganaderia', 'sesion/cerrar').toString(),
          'https://tucampo.cl/ganaderia/index.php?r=%2Fapi%2Fsesion%2Fcerrar');
    });
  });

  group('ingreso', () {
    test('200: devuelve la sesión con el servidor, el token y las tareas', () async {
      final servidor = ServidorFalso()..sesion = (_) => json(sesionEjemplo());
      final r = await ClienteApi(cliente: servidor.cliente)
          .ingresar(servidor: 'https://tucampo.cl', email: ' juan@campo.cl ', password: 'x');
      final sesion = (r as Ingresado).sesion;
      expect(sesion.servidor, 'https://tucampo.cl');
      expect(sesion.token, tokenEjemplo);
      expect(sesion.usuario.rolNombre, 'Operario');
      expect(sesion.operaciones, contains('PESADA'));
      final enviado = jsonDecode(servidor.pedidos.single.body) as Map;
      expect(enviado['email'], 'juan@campo.cl');
      expect(enviado.containsKey('organizacion_id'), isFalse);
    });

    test('409: pide elegir organización y después la manda', () async {
      final servidor = ServidorFalso()
        ..sesion = (p) => (jsonDecode(p.body) as Map).containsKey('organizacion_id')
            ? json(sesionEjemplo())
            : json({
                'error': 'Trabajás en más de una organización: elegí con cuál vas a trabajar.',
                'organizaciones': [
                  {'id': 1, 'nombre': 'Ganadera Los Robles', 'rol': 'Administrador'},
                  {'id': 2, 'nombre': 'Organización Demo', 'rol': 'Administrador'},
                ],
              }, 409);
      final api = ClienteApi(cliente: servidor.cliente);
      final primera = await api.ingresar(servidor: 'https://x.cl', email: 'a@b.cl', password: 'x');
      expect(primera, isA<EligeOrganizacion>());
      expect((primera as EligeOrganizacion).organizaciones.map((o) => o.nombre), ['Ganadera Los Robles', 'Organización Demo']);
      final segunda = await api.ingresar(servidor: 'https://x.cl', email: 'a@b.cl', password: 'x', organizacionId: 2);
      expect(segunda, isA<Ingresado>());
      expect((jsonDecode(servidor.pedidos.last.body) as Map)['organizacion_id'], 2);
    });

    test('401: el mensaje del servidor llega tal cual', () async {
      final servidor = ServidorFalso()..sesion = (_) => json({'error': 'Correo o contraseña incorrectos.'}, 401);
      expect(
        () => ClienteApi(cliente: servidor.cliente).ingresar(servidor: 'https://x.cl', email: 'a@b.cl', password: 'x'),
        throwsA(isA<ErrorApi>().having((e) => e.mensaje, 'mensaje', 'Correo o contraseña incorrectos.').having((e) => e.sesionVencida, 'vencida', isTrue)),
      );
    });
  });

  group('catálogo', () {
    final sesion = Sesion.fromJson(sesionEjemplo(), servidor: 'https://tucampo.cl');

    test('manda el token en Authorization y en X-Token', () async {
      final servidor = ServidorFalso()..catalogo = (_) => json(catalogoEjemplo());
      final catalogo = await ClienteApi(cliente: servidor.cliente).catalogo(sesion);
      final pedido = servidor.pedidos.single;
      expect(pedido.method, 'GET');
      expect(pedido.headers['Authorization'], 'Bearer $tokenEjemplo');
      expect(pedido.headers['X-Token'], tokenEjemplo);
      expect(catalogo.animales, hasLength(2));
    });

    test('lee todos los campos del contrato', () async {
      final c = Catalogo.fromJson(catalogoEjemplo());
      final vaca = c.animales.first;
      expect(vaca.nombre, 'A-0143');
      expect(vaca.peso, 452.5);
      expect(vaca.corralId, 2);
      expect(vaca.carenciaHasta, '2026-12-05');
      expect(c.animales.last.nombre, 'T-12', reason: 'sin DIIO se nombra por el número interno');
      expect(c.corrales.single.capacidad, 60);
      expect(c.raciones.single.materiaSeca, 72.5);
      expect(c.productos.single.carencia, 35);
      expect(c.gdpMaxima, 2.5);
      expect(c.mva, isNull);
      expect(c.crudo['hoy'], '2026-11-27', reason: 'se guarda el JSON original para no perder campos');
    });

    test('quien valida recibe el registro MVA', () {
      final c = Catalogo.fromJson({
        ...catalogoEjemplo(),
        'mva': {'numero': 'MVA-08-1234', 'rut': '12.345.678-5', 'vigente_hasta': '2027-01-01', 'vigente': true},
        'por_validar': [{'id': 5}],
        'por_validar_total': 1,
      });
      expect(c.mva!.numero, 'MVA-08-1234');
      expect(c.mva!.vigente, isTrue);
      expect(c.porValidarTotal, 1);
    });
  });

  group('errores de red', () {
    test('sin conexión se marca como sinRed', () async {
      final cliente = MockClient((_) async => throw http.ClientException('sin red'));
      expect(
        () => ClienteApi(cliente: cliente).ingresar(servidor: 'https://x.cl', email: 'a@b.cl', password: 'x'),
        throwsA(isA<ErrorApi>().having((e) => e.sinRed, 'sinRed', isTrue)),
      );
    });

    test('una página que no es la API (404 en HTML) da un mensaje útil', () async {
      final cliente = MockClient((_) async => http.Response('<html>Not found</html>', 404));
      expect(
        () => ClienteApi(cliente: cliente).ingresar(servidor: 'https://x.cl', email: 'a@b.cl', password: 'x'),
        throwsA(isA<ErrorApi>().having((e) => e.mensaje, 'mensaje', contains('dirección del servidor'))),
      );
    });

    test('un 200 que no es JSON tampoco se toma como válido', () async {
      final cliente = MockClient((_) async => http.Response('hola', 200));
      expect(
        () => ClienteApi(cliente: cliente).ingresar(servidor: 'https://x.cl', email: 'a@b.cl', password: 'x'),
        throwsA(isA<ErrorApi>()),
      );
    });
  });
}
