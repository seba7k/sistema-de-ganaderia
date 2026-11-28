import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/cliente_api.dart';
import '../datos/almacenes.dart';
import '../datos/base_local.dart';
import '../modelos/modelos.dart';

/* ---------- Dependencias (se reemplazan en las pruebas) ---------- */

final clienteApiProvider = Provider<ClienteApi>((ref) => ClienteApi());

final almacenSesionProvider = Provider<AlmacenSesion>((ref) => AlmacenSesionSeguro());

final almacenLocalProvider = Provider<AlmacenLocal>((ref) {
  final base = BaseLocal();
  ref.onDispose(base.close);
  return AlmacenLocalDrift(base);
});

/* ---------- Sesión ---------- */

/// La sesión abierta, o null si hay que ingresar.
final sesionProvider = AsyncNotifierProvider<SesionNotifier, Sesion?>(SesionNotifier.new);

class SesionNotifier extends AsyncNotifier<Sesion?> {
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
      final almacen = ref.read(almacenSesionProvider);
      await almacen.guardar(resultado.sesion);
      await almacen.recordarIngreso(servidor, email.trim());
      // Datos de otra persona u organización no se mezclan con los nuevos.
      final anterior = state.value;
      if (anterior == null ||
          anterior.servidor != resultado.sesion.servidor ||
          anterior.usuario.id != resultado.sesion.usuario.id ||
          anterior.usuario.organizacion != resultado.sesion.usuario.organizacion) {
        await ref.read(almacenLocalProvider).borrarTodo();
      }
      state = AsyncData(resultado.sesion);
    }
    return resultado;
  }

  /// Actualiza las tareas permitidas con lo que dice el catálogo: si el
  /// administrador cambió el rol, rige desde la próxima descarga.
  Future<void> actualizarPermisos(Catalogo catalogo) async {
    final actual = state.value;
    if (actual == null) return;
    final nueva = actual.conOperaciones(catalogo.operaciones, catalogo.usuario);
    await ref.read(almacenSesionProvider).guardar(nueva);
    state = AsyncData(nueva);
  }

  /// La sesión venció en el servidor (401): se vuelve al ingreso, pero los datos
  /// del teléfono quedan (en la etapa 2, también la cola de lo no enviado).
  Future<void> vencer() async {
    await ref.read(almacenSesionProvider).borrar();
    state = const AsyncData(null);
  }

  /// Cierra la sesión: avisa al servidor si hay señal y borra los datos locales.
  Future<void> cerrar() async {
    final actual = state.value;
    if (actual != null) {
      try {
        await ref.read(clienteApiProvider).cerrarSesion(actual);
      } on ErrorApi {
        // Sin señal o token ya vencido: el token caduca solo en el servidor.
      }
    }
    await ref.read(almacenSesionProvider).borrar();
    await ref.read(almacenLocalProvider).borrarTodo();
    state = const AsyncData(null);
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
      await ref.read(almacenLocalProvider).guardarCatalogo(catalogo);
      state = AsyncData(EstadoCatalogo(catalogo: catalogo));
      await ref.read(sesionProvider.notifier).actualizarPermisos(catalogo);
    } on ErrorApi catch (e) {
      if (e.sesionVencida) {
        await ref.read(sesionProvider.notifier).vencer();
        return;
      }
      state = AsyncData(previo.copiar(descargando: false, aviso: e.mensaje));
    }
  }
}
