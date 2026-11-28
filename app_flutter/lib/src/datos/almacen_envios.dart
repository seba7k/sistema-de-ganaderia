import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:uuid/uuid.dart';

import '../modelos/modelos.dart';
import 'base_local.dart';

class AlmacenEnvios {
  AlmacenEnvios(this._base);

  final BaseLocal _base;

  Expression<bool> _pertenece(Envios tabla, Sesion sesion) =>
      tabla.servidor.equals(sesion.servidor) &
      tabla.usuarioId.equals(sesion.usuario.id) &
      tabla.organizacionId.equals(sesion.usuario.organizacionId ?? -1);

  Stream<List<Envio>> observar(Sesion sesion) =>
      (_base.select(_base.envios)
            ..where((tabla) => _pertenece(tabla, sesion))
            ..orderBy([(tabla) => OrderingTerm.asc(tabla.orden)]))
          .watch();

  Future<List<Envio>> lote(Sesion sesion, int despuesDe) =>
      (_base.select(_base.envios)
            ..where(
              (tabla) =>
                  _pertenece(tabla, sesion) &
                  tabla.orden.isBiggerThanValue(despuesDe) &
                  tabla.estado.isNotValue('RECHAZADA'),
            )
            ..orderBy([(tabla) => OrderingTerm.asc(tabla.orden)])
            ..limit(100))
          .get();

  Future<void> agregar(
    Sesion sesion,
    String tipo,
    Map<String, dynamic> datos,
  ) async {
    final organizacion = sesion.usuario.organizacionId;
    if (organizacion == null || organizacion <= 0) {
      throw StateError(
        'El servidor debe identificar la organización para guardar envíos.',
      );
    }
    final fecha = DateTime.now()
        .toIso8601String()
        .substring(0, 19)
        .replaceFirst('T', ' ');
    await _base
        .into(_base.envios)
        .insert(
          EnviosCompanion.insert(
            uuid: const Uuid().v4(),
            servidor: sesion.servidor,
            usuarioId: sesion.usuario.id,
            organizacionId: organizacion,
            tipo: tipo,
            datos: jsonEncode(datos),
            registradoEn: fecha,
          ),
        );
  }

  Future<void> aplicarResultados(
    List<Envio> lote,
    List<Map<String, dynamic>> resultados,
  ) async {
    final porUuid = {
      for (final resultado in resultados) resultado['uuid']: resultado,
    };
    await _base.transaction(() async {
      for (final envio in lote) {
        final resultado = porUuid[envio.uuid];
        final estado = resultado?['estado'];
        final fila = _base.envios.uuid.equals(envio.uuid);
        if (estado == 'OK' || estado == 'DUPLICADA') {
          await (_base.delete(_base.envios)..where((_) => fila)).go();
          continue;
        }
        final mensaje = resultado?['mensaje'];
        final errores = resultado?['errores'];
        final detalles = [
          if (mensaje is String && mensaje.isNotEmpty) mensaje,
          if (errores is Map) ...errores.values.whereType<String>(),
        ];
        await (_base.update(_base.envios)..where((_) => fila)).write(
          EnviosCompanion(
            estado: Value(estado == 'RECHAZADA' ? 'RECHAZADA' : 'ERROR'),
            mensaje: Value(
              detalles.isEmpty
                  ? 'El servidor no confirmó el envío.'
                  : detalles.join('\n'),
            ),
            requiereConfirmacion: Value(
              estado == 'RECHAZADA' &&
                  resultado?['requiere_confirmacion'] == true,
            ),
          ),
        );
      }
    });
  }

  Future<void> confirmar(Sesion sesion, Envio envio) async {
    final datos = jsonDecode(envio.datos) as Map<String, dynamic>;
    await (_base.update(_base.envios)..where(
          (tabla) =>
              _pertenece(tabla, sesion) &
              tabla.uuid.equals(envio.uuid) &
              tabla.estado.equals('RECHAZADA') &
              tabla.requiereConfirmacion.equals(true),
        ))
        .write(
          EnviosCompanion(
            datos: Value(jsonEncode({...datos, 'confirmar': true})),
            estado: const Value('PENDIENTE'),
            mensaje: const Value(null),
            requiereConfirmacion: const Value(false),
          ),
        );
  }

  Future<void> descartar(Sesion sesion, Envio envio) async {
    await (_base.delete(_base.envios)..where(
          (tabla) =>
              _pertenece(tabla, sesion) &
              tabla.uuid.equals(envio.uuid) &
              tabla.estado.equals('RECHAZADA'),
        ))
        .go();
  }
}
