// Prueba contra un servidor PHP real, con los datos de demostración.
//
// No corre en las pruebas normales. Para correrla, con el servidor local
// levantado (INICIAR-GANADERIA.bat, en modo demo):
//
//   flutter test test/integracion_servidor_test.dart --dart-define=SERVIDOR=http://localhost:8000
@TestOn('vm')
library;

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:terreno/src/api/cliente_api.dart';

const servidor = String.fromEnvironment('SERVIDOR');

void main() {
  final omitir = servidor.isEmpty ? 'Falta --dart-define=SERVIDOR=http://…' : null;
  // flutter_test bloquea la red por defecto; acá se usa un cliente real.
  final api = ClienteApi(cliente: http.Client());

  test('ingresa, descarga el catálogo y cierra sesión contra el servidor real', () async {
    var r = await api.ingresar(servidor: servidor, email: 'admin@ganaderia.cl', password: 'demo1234');
    if (r is EligeOrganizacion) {
      expect(r.organizaciones, isNotEmpty);
      r = await api.ingresar(servidor: servidor, email: 'admin@ganaderia.cl', password: 'demo1234', organizacionId: r.organizaciones.first.id);
    }
    final sesion = (r as Ingresado).sesion;
    expect(sesion.token, hasLength(64));
    expect(sesion.operaciones, contains('SALIDA'));

    final catalogo = await api.catalogo(sesion);
    expect(catalogo.animales, isNotEmpty);
    expect(catalogo.corrales, isNotEmpty);
    expect(catalogo.usuario.id, sesion.usuario.id);

    await api.cerrarSesion(sesion);
    expect(() => api.catalogo(sesion), throwsA(isA<ErrorApi>().having((e) => e.sesionVencida, 'vencida', isTrue)));
  }, skip: omitir);

  test('una contraseña equivocada da el mensaje del servidor', () async {
    expect(
      () => api.ingresar(servidor: servidor, email: 'admin@ganaderia.cl', password: 'mala'),
      throwsA(isA<ErrorApi>().having((e) => e.codigo, 'código', 401)),
    );
  }, skip: omitir);
}
