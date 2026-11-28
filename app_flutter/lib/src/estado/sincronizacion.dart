import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/cliente_api.dart';
import '../datos/almacen_envios.dart';
import '../datos/base_local.dart';
import 'estado.dart';

final almacenEnviosProvider = Provider<AlmacenEnvios>(
  (ref) => AlmacenEnvios(ref.watch(baseLocalProvider)),
);

final enviosProvider = StreamProvider.autoDispose<List<Envio>>((ref) {
  final cuenta = ref.watch(
    sesionProvider.select(
      (s) => (s.value?.token, s.value?.usuario.organizacionId),
    ),
  );
  if (cuenta.$1 == null || cuenta.$2 == null) return Stream.value([]);
  final sesion = ref.read(sesionProvider).value!;
  return ref.watch(almacenEnviosProvider).observar(sesion);
});

typedef EstadoSincronizacion = ({bool enviando, String? aviso, String? token});

final sincronizacionProvider =
    NotifierProvider<Sincronizacion, EstadoSincronizacion>(Sincronizacion.new);

class Sincronizacion extends Notifier<EstadoSincronizacion> {
  @override
  EstadoSincronizacion build() => (enviando: false, aviso: null, token: null);

  Future<void> enviar() async {
    final sesion = ref.read(sesionProvider).value;
    if (sesion == null ||
        sesion.usuario.organizacionId == null ||
        state.enviando) {
      return;
    }
    final almacen = ref.read(almacenEnviosProvider);
    final api = ref.read(clienteApiProvider);
    final alertas = <String>{};
    String? error;
    var despuesDe = 0;
    state = (enviando: true, aviso: null, token: sesion.token);
    try {
      while (ref.mounted &&
          ref.read(sesionProvider).value?.token == sesion.token) {
        final lote = await almacen.lote(sesion, despuesDe);
        if (lote.isEmpty ||
            !ref.mounted ||
            ref.read(sesionProvider).value?.token != sesion.token) {
          break;
        }
        final resultados = await api.sincronizar(sesion, [
          for (final envio in lote)
            {
              'uuid': envio.uuid,
              'tipo': envio.tipo,
              'datos': jsonDecode(envio.datos),
              'registrado_en': envio.registradoEn,
            },
        ]);
        if (!ref.mounted) return;
        await almacen.aplicarResultados(lote, resultados);
        for (final resultado in resultados) {
          final alerta = resultado['alerta'];
          if (resultado['estado'] == 'OK' &&
              alerta is String &&
              alerta.isNotEmpty) {
            alertas.add(alerta);
          }
        }
        despuesDe = lote.last.orden;
        if (lote.length < 100) break;
      }
    } on ErrorApi catch (e) {
      if (!ref.mounted) return;
      if (e.sesionVencida) {
        await ref.read(sesionProvider.notifier).vencer(sesion.token);
      } else {
        error = e.mensaje;
      }
    } catch (_) {
      error = 'No se pudo acceder a los envíos del teléfono.';
    } finally {
      if (ref.mounted) {
        final avisos = [?error, ...alertas];
        state = (
          enviando: false,
          token: sesion.token,
          aviso:
              ref.read(sesionProvider).value?.token == sesion.token &&
                  avisos.isNotEmpty
              ? avisos.join('\n')
              : null,
        );
      }
    }
  }
}
