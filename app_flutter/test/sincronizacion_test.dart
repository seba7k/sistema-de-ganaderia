import 'dart:convert';
import 'dart:io';

import 'package:drift/native.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:terreno/src/api/cliente_api.dart';
import 'package:terreno/src/datos/almacen_envios.dart';
import 'package:terreno/src/datos/almacenes.dart';
import 'package:terreno/src/datos/base_local.dart';
import 'package:terreno/src/estado/estado.dart';
import 'package:terreno/src/estado/sincronizacion.dart';
import 'package:terreno/src/modelos/modelos.dart';

import 'datos_ejemplo.dart';

Sesion cuenta({int organizacion = 1, String token = tokenEjemplo}) {
  final datos = sesionEjemplo();
  datos['token'] = token;
  datos['usuario']['organizacion_id'] = organizacion;
  return Sesion.fromJson(datos, servidor: 'https://campo.cl');
}

void main() {
  test('migra el catálogo y conserva la cola al reabrir, incluso entre organizaciones homónimas', () async {
    final directorio = await Directory.systemTemp.createTemp('terreno-');
    addTearDown(() => directorio.delete(recursive: true));
    final archivo = File('${directorio.path}/terreno.sqlite');
    final anterior = BaseLocal(NativeDatabase(archivo));
    await anterior.escribir('catalogo', 'guardado');
    await anterior.customStatement('DROP TABLE envios');
    await anterior.customStatement('PRAGMA user_version = 1');
    await anterior.close();

    final base = BaseLocal(NativeDatabase(archivo));
    expect(await base.leer('catalogo'), 'guardado');
    final almacen = AlmacenEnvios(base);
    final sesion = cuenta();
    await almacen.agregar(sesion, 'PESADA', {'peso': 320});
    final uuid = (await almacen.lote(sesion, 0)).single.uuid;
    expect(
      uuid,
      matches(
        RegExp(
          r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
        ),
      ),
    );
    await base.close();

    final reabierta = BaseLocal(NativeDatabase(archivo));
    addTearDown(reabierta.close);
    final cola = AlmacenEnvios(reabierta);
    expect(await cola.lote(cuenta(organizacion: 2), 0), isEmpty);
    expect((await cola.lote(cuenta(token: 'nuevo'), 0)).single.uuid, uuid);
  });

  test('envía lotes de 100, conserva UUID tras 401 y procesa resultados por registro', () async {
    final base = BaseLocal(NativeDatabase.memory());
    addTearDown(base.close);
    final local = AlmacenEnvios(base);
    final sesion = cuenta();
    for (var i = 0; i < 101; i++) {
      await local.agregar(sesion, 'PESADA', {'peso': 320 + i});
    }
    final primera = (await local.lote(sesion, 0)).first;
    final cantidades = <int>[];
    var intento = 0;
    var hayRed = false;
    final cliente = ClienteApi(
      cliente: MockClient((pedido) async {
        if (pedido.url.queryParameters['r'] == '/api/sesion') {
          return json({...sesionEjemplo(), 'token': 'sesion-renovada'});
        }
        expect(pedido.url.queryParameters['r'], '/api/sincronizar');
        if (!hayRed) throw http.ClientException('Sin conexión');
        final lote = (jsonDecode(pedido.body)['operaciones'] as List)
            .cast<Map<String, dynamic>>();
        cantidades.add(lote.length);
        intento++;
        if (intento == 1) return json({'error': 'Sin sesión'}, 401);
        expect(pedido.headers['x-token'], 'sesion-renovada');
        if (intento == 2) expect(lote.first['uuid'], primera.uuid);
        return json({
          'resultados': [
            for (final op in lote)
              {
                'uuid': op['uuid'],
                'estado': switch (op['datos']['peso']) {
                  320 => 'DUPLICADA',
                  321 when op['datos']['confirmar'] != true => 'RECHAZADA',
                  322 when intento == 2 => 'ERROR',
                  _ => 'OK',
                },
                'mensaje': 'Resultado del servidor',
                'requiere_confirmacion': true,
              },
          ],
        });
      }),
    );
    final contenedor = ProviderContainer(
      overrides: [
        almacenSesionProvider.overrideWithValue(
          AlmacenSesionEnMemoria()..sesion = sesion,
        ),
        almacenLocalProvider.overrideWithValue(AlmacenLocalEnMemoria()),
        almacenEnviosProvider.overrideWithValue(local),
        clienteApiProvider.overrideWithValue(cliente),
      ],
    );
    addTearDown(contenedor.dispose);
    await contenedor.read(sesionProvider.future);

    final sincronizacion = contenedor.read(sincronizacionProvider.notifier);
    await sincronizacion.enviar();
    expect(
      contenedor.read(sincronizacionProvider).aviso,
      'Sin conexión con el servidor.',
    );
    expect((await local.lote(sesion, 0)).first.uuid, primera.uuid);
    hayRed = true;
    await sincronizacion.enviar();
    expect(contenedor.read(sesionProvider).value, isNull);
    expect((await local.lote(sesion, 0)).first.uuid, primera.uuid);

    await contenedor
        .read(sesionProvider.notifier)
        .ingresar(
          servidor: sesion.servidor,
          email: 'juan@campo.cl',
          password: 'secreta',
        );
    await sincronizacion.enviar();
    expect(cantidades, [100, 100, 1]);
    final restantes = await local.observar(sesion).first;
    expect(restantes.map((envio) => envio.estado), ['RECHAZADA', 'ERROR']);

    await local.confirmar(sesion, restantes.first);
    expect((await local.lote(sesion, 0)).first.uuid, restantes.first.uuid);
    await sincronizacion.enviar();
    expect(cantidades, [100, 100, 1, 2]);
    expect(await local.observar(sesion).first, isEmpty);
  });
}
