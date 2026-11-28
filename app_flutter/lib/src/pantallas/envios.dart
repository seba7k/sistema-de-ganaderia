import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../datos/base_local.dart';
import '../estado/estado.dart';
import '../estado/sincronizacion.dart';
import '../tareas.dart';
import 'inicio.dart';

class TerrenoConSesion extends ConsumerStatefulWidget {
  const TerrenoConSesion({super.key});

  @override
  ConsumerState<TerrenoConSesion> createState() => _TerrenoConSesionState();
}

class _TerrenoConSesionState extends ConsumerState<TerrenoConSesion> {
  late final AppLifecycleListener _ciclo;
  Timer? _reintento;
  bool _enEnvios = false;

  @override
  void initState() {
    super.initState();
    _ciclo = AppLifecycleListener(onResume: _enviarSiPendiente);
  }

  void _enviarSiPendiente() {
    if (_reintento != null &&
        WidgetsBinding.instance.lifecycleState == AppLifecycleState.resumed) {
      unawaited(ref.read(sincronizacionProvider.notifier).enviar());
    }
  }

  @override
  void dispose() {
    _ciclo.dispose();
    _reintento?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(enviosProvider, (anterior, actual) {
      final enviables = (actual.value ?? const <Envio>[])
          .where((envio) => envio.estado != 'RECHAZADA')
          .toList();
      if (enviables.isEmpty) {
        _reintento?.cancel();
        _reintento = null;
        return;
      }
      _reintento ??= Timer.periodic(
        const Duration(seconds: 30),
        (_) => _enviarSiPendiente(),
      );
      final anteriores = {
        for (final envio in anterior?.value ?? const <Envio>[])
          envio.uuid: envio.estado,
      };
      if (anterior?.value == null ||
          enviables.any(
            (envio) =>
                envio.estado == 'PENDIENTE' &&
                anteriores[envio.uuid] != 'PENDIENTE',
          )) {
        _enviarSiPendiente();
      }
    });
    final cantidad = ref.watch(enviosProvider).value?.length ?? 0;
    return PopScope(
      canPop: !_enEnvios,
      onPopInvokedWithResult: (salio, _) {
        if (!salio && _enEnvios) setState(() => _enEnvios = false);
      },
      child: _enEnvios
          ? PantallaEnvios(volver: () => setState(() => _enEnvios = false))
          : PantallaInicio(
              abrirEnvios: () => setState(() => _enEnvios = true),
              cantidadEnvios: cantidad,
            ),
    );
  }
}

class PantallaEnvios extends ConsumerWidget {
  const PantallaEnvios({super.key, required this.volver});

  final VoidCallback volver;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final envios = ref.watch(enviosProvider);
    final sincronizacion = ref.watch(sincronizacionProvider);
    final sesion = ref.watch(sesionProvider).value;
    final registros = envios.value ?? const <Envio>[];
    final enviable = registros.any((envio) => envio.estado != 'RECHAZADA');
    return Scaffold(
      appBar: AppBar(
        leading: BackButton(onPressed: volver),
        title: const Text('Envíos'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          FilledButton.icon(
            onPressed: enviable && !sincronizacion.enviando
                ? ref.read(sincronizacionProvider.notifier).enviar
                : null,
            icon: const Icon(Icons.sync),
            label: Text(
              sincronizacion.enviando ? 'Enviando…' : 'Sincronizar ahora',
            ),
          ),
          if (sincronizacion.token == sesion?.token)
            if (sincronizacion.aviso case final aviso?) Text(aviso),
          if (sesion?.usuario.organizacionId == null)
            const Text(
              'Actualizá el servidor y descargá los datos del campo para habilitar los envíos.',
            )
          else if (envios.hasError)
            const Text('No se pudieron leer los envíos del teléfono.')
          else if (envios.isLoading)
            const Center(child: CircularProgressIndicator())
          else if (registros.isEmpty)
            const Text('No hay registros pendientes.'),
          for (final envio in registros)
            Card(
              child: ListTile(
                title: Text(
                  tareasPermitidas([envio.tipo]).firstOrNull?.titulo ??
                      envio.tipo,
                ),
                subtitle: Text(
                  [
                    fechaHoraCorta(envio.registradoEn),
                    switch (envio.estado) {
                      'ERROR' => 'Se volverá a intentar',
                      'RECHAZADA' => 'Rechazado',
                      _ => 'Pendiente',
                    },
                    ?envio.mensaje,
                  ].join('\n'),
                ),
                trailing: envio.estado == 'RECHAZADA'
                    ? PopupMenuButton<bool>(
                        onSelected: (confirmar) =>
                            _resolver(context, ref, envio, confirmar),
                        itemBuilder: (_) => [
                          if (envio.requiereConfirmacion)
                            const PopupMenuItem(
                              value: true,
                              child: Text('Confirmar y reenviar'),
                            ),
                          const PopupMenuItem(
                            value: false,
                            child: Text('Descartar'),
                          ),
                        ],
                      )
                    : null,
              ),
            ),
        ],
      ),
    );
  }

  Future<void> _resolver(
    BuildContext context,
    WidgetRef ref,
    Envio envio,
    bool confirmar,
  ) async {
    final sesion = ref.read(sesionProvider).value;
    if (sesion == null) return;
    final accion = confirmar ? 'Confirmar y reenviar' : 'Descartar';
    final aceptar = await showDialog<bool>(
      context: context,
      builder: (contexto) => AlertDialog(
        title: Text('$accion registro'),
        content: Text(
          confirmar
              ? (envio.mensaje ??
                    'El servidor solicitó confirmación para guardar el registro.')
              : 'Se eliminará este registro del teléfono.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(contexto),
            child: const Text('Cancelar'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(contexto, true),
            child: Text(accion),
          ),
        ],
      ),
    );
    if (aceptar != true ||
        !context.mounted ||
        ref.read(sesionProvider).value?.token != sesion.token) {
      return;
    }
    final almacen = ref.read(almacenEnviosProvider);
    try {
      if (confirmar) {
        await almacen.confirmar(sesion, envio);
      } else {
        await almacen.descartar(sesion, envio);
      }
    } catch (_) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('No se pudo actualizar el envío en el teléfono.'),
          ),
        );
      }
    }
  }
}
