import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/cliente_api.dart';
import '../datos/almacenes.dart';
import '../datos/base_local.dart';
import '../modelos/modelos.dart';

/* ---------- Dependencias (se reemplazan en las pruebas) ---------- */

final clienteApiProvider = Provider<ClienteApi>((ref) => ClienteApi());

final almacenSesionProvider = Provider<AlmacenSesion>((ref) => AlmacenSesionSeguro());

final baseLocalProvider = Provider<BaseLocal>((ref) {
  final base = BaseLocal();
  ref.onDispose(base.close);
  return base;
});

final almacenLocalProvider = Provider<AlmacenLocal>((ref) => AlmacenLocalDrift(ref.watch(baseLocalProvider)));

/* ---------- Sesión ---------- */

/// La sesión abierta, o null si hay que ingresar.
final sesionProvider = AsyncNotifierProvider<SesionNotifier, Sesion?>(SesionNotifier.new);

class SesionNotifier extends AsyncNotifier<Sesion?> {
  Future<void> _escritura = Future.value();

  Future<T> _serializar<T>(Future<T> Function() accion) {
    final resultado = _escritura.then((_) => accion());
    _escritura = resultado.then<void>((_) {}, onError: (Object _, StackTrace _) {});
    return resultado;
  }

  @override
  Future<Sesion?> build() => ref.read(almacenSesionProvider).leer();

  /// Ingresa. Si la persona trabaja en varias organizaciones devuelve
  /// [EligeOrganizacion] y hay que volver a llamar con [organizacionId].
  Future<ResultadoIngreso> ingresar({
    required String servidor,
    required String email,
    required String password,
    int? organizacionId,
  }) async {
    final resultado = await ref.read(clienteApiProvider).ingresar(
          servidor: servidor,
          email: email,
          password: password,
          organizacionId: organizacionId,
        );
    if (resultado is Ingresado) {
      await _serializar(() async {
        final almacen = ref.read(almacenSesionProvider);
        // Datos de otra persona u organización no se mezclan con los nuevos.
        final anterior = state.value;
        if (anterior == null ||
            anterior.servidor != resultado.sesion.servidor ||
            anterior.usuario.id != resultado.sesion.usuario.id ||
            anterior.usuario.organizacionId != resultado.sesion.usuario.organizacionId ||
            anterior.usuario.organizacion != resultado.sesion.usuario.organizacion) {
          await ref.read(almacenLocalProvider).borrarCatalogo();
        }
        await almacen.recordarIngreso(servidor, email.trim());
        await almacen.guardar(resultado.sesion);
        state = AsyncData(resultado.sesion);
      });
    }
    return resultado;
  }

  /// Actualiza las tareas permitidas con lo que dice el catálogo: si el
  /// administrador cambió el rol, rige desde la próxima descarga.
  Future<bool> guardarCatalogo(Catalogo catalogo, String token) => _serializar(() async {
    final actual = state.value;
    if (actual == null || actual.token != token) return false;
    final nueva = actual.conOperaciones(catalogo.operaciones, catalogo.usuario);
    await ref.read(almacenLocalProvider).guardarCatalogo(catalogo);
    await ref.read(almacenSesionProvider).guardar(nueva);
    state = AsyncData(nueva);
    return true;
  });

  /// La sesión venció en el servidor (401): se vuelve al ingreso, pero los datos
  /// del teléfono quedan, incluida la cola de lo no enviado.
  Future<void> vencer(String token) => _serializar(() async {
    if (state.value?.token != token) return;
    await ref.read(almacenSesionProvider).borrar();
    state = const AsyncData(null);
  });

  /// Cierra la sesión y borra el catálogo. Los envíos quedan asociados a su cuenta.
  Future<void> cerrar() async {
    final actual = state.value;
    if (actual != null) {
      await _serializar(() async {
        if (state.value?.token != actual.token && state.value != null) return;
        await ref.read(almacenSesionProvider).borrar();
        await ref.read(almacenLocalProvider).borrarCatalogo();
        state = const AsyncData(null);
      });
      try {
        await ref.read(clienteApiProvider).cerrarSesion(actual);
      } on ErrorApi {
        // Sin señal o token ya vencido: el token caduca solo en el servidor.
      }
    }
  }
}

/* ---------- Catálogo ---------- */

class EstadoCatalogo {
  const EstadoCatalogo({this.catalogo, this.descargando = false, this.aviso});

  final Catalogo? catalogo;
  final bool descargando;

  /// Mensaje de la última descarga fallida (sin señal, por ejemplo).
  final String? aviso;

  EstadoCatalogo copiar({Catalogo? catalogo, bool? descargando, String? aviso, bool sinAviso = false}) => EstadoCatalogo(
        catalogo: catalogo ?? this.catalogo,
        descargando: descargando ?? this.descargando,
        aviso: sinAviso ? null : (aviso ?? this.aviso),
      );
}

final catalogoProvider = AsyncNotifierProvider<CatalogoNotifier, EstadoCatalogo>(CatalogoNotifier.new);

class CatalogoNotifier extends AsyncNotifier<EstadoCatalogo> {
  @override
  Future<EstadoCatalogo> build() async {
    // Solo depende de qué sesión hay (el token), no de sus permisos: actualizar
    // los permisos después de descargar no tiene que volver a leer el catálogo.
    final token = ref.watch(sesionProvider.select((s) => s.value?.token));
    if (token == null) return const EstadoCatalogo();
    final guardado = await ref.read(almacenLocalProvider).leerCatalogo();
    if (guardado == null) {
      // Primera vez en este teléfono: se descarga sin esperar a que lo pidan.
      Future.microtask(actualizar);
    }
    return EstadoCatalogo(catalogo: guardado, descargando: guardado == null);
  }

  /// Descarga el catálogo. Sin señal, se sigue con el guardado y se avisa.
  Future<void> actualizar() async {
    final sesion = ref.read(sesionProvider).value;
    if (sesion == null) return;
    final previo = state.value ?? const EstadoCatalogo();
    state = AsyncData(previo.copiar(descargando: true, sinAviso: true));
    try {
      final catalogo = await ref.read(clienteApiProvider).catalogo(sesion);
      final guardado = await ref.read(sesionProvider.notifier).guardarCatalogo(catalogo, sesion.token);
      if (!guardado || ref.read(sesionProvider).value?.token != sesion.token) return;
      state = AsyncData(EstadoCatalogo(catalogo: catalogo));
    } on ErrorApi catch (e) {
      if (e.sesionVencida) {
        await ref.read(sesionProvider.notifier).vencer(sesion.token);
        return;
      }
      if (ref.read(sesionProvider).value?.token != sesion.token) return;
      state = AsyncData(previo.copiar(descargando: false, aviso: e.mensaje));
    }
  }
}
