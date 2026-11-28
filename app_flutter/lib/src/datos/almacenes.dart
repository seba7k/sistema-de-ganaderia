import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../modelos/modelos.dart';
import 'base_local.dart';

/// Dónde vive la sesión (con el token). Va cifrada: en Android, el almacén
/// seguro usa el Keystore del sistema.
abstract class AlmacenSesion {
  Future<Sesion?> leer();
  Future<void> guardar(Sesion sesion);
  Future<void> borrar();

  /// Último servidor y correo usados, para no volver a escribirlos al ingresar.
  Future<({String? servidor, String? email})> ultimoIngreso();
  Future<void> recordarIngreso(String servidor, String email);
}

class AlmacenSesionSeguro implements AlmacenSesion {
  AlmacenSesionSeguro([FlutterSecureStorage? almacen]) : _almacen = almacen ?? const FlutterSecureStorage();

  final FlutterSecureStorage _almacen;
  static const _clave = 'sesion';

  @override
  Future<Sesion?> leer() async {
    final guardada = await _almacen.read(key: _clave);
    if (guardada == null) return null;
    try {
      return Sesion.desdeGuardada(Map<String, dynamic>.from(jsonDecode(guardada) as Map));
    } catch (_) {
      await borrar();
      return null;
    }
  }

  @override
  Future<void> guardar(Sesion sesion) => _almacen.write(key: _clave, value: jsonEncode(sesion.toJson()));

  @override
  Future<void> borrar() => _almacen.delete(key: _clave);

  @override
  Future<({String? servidor, String? email})> ultimoIngreso() async =>
      (servidor: await _almacen.read(key: 'ultimo_servidor'), email: await _almacen.read(key: 'ultimo_email'));

  @override
  Future<void> recordarIngreso(String servidor, String email) async {
    await _almacen.write(key: 'ultimo_servidor', value: servidor);
    await _almacen.write(key: 'ultimo_email', value: email);
  }
}

/// Datos del campo guardados en el teléfono para trabajar sin señal.
abstract class AlmacenLocal {
  Future<Catalogo?> leerCatalogo();
  Future<void> guardarCatalogo(Catalogo catalogo);
  Future<void> borrarCatalogo();
}

class AlmacenLocalDrift implements AlmacenLocal {
  AlmacenLocalDrift(this._base);

  final BaseLocal _base;
  static const _claveCatalogo = 'catalogo';

  @override
  Future<Catalogo?> leerCatalogo() async {
    final texto = await _base.leer(_claveCatalogo);
    if (texto == null) return null;
    try {
      return Catalogo.fromJson(Map<String, dynamic>.from(jsonDecode(texto) as Map));
    } catch (_) {
      return null;
    }
  }

  @override
  Future<void> guardarCatalogo(Catalogo catalogo) => _base.escribir(_claveCatalogo, jsonEncode(catalogo.crudo));

  @override
  Future<void> borrarCatalogo() => _base.borrar(_claveCatalogo);
}

/// Versiones en memoria, para las pruebas.
class AlmacenSesionEnMemoria implements AlmacenSesion {
  Sesion? sesion;
  String? servidor;
  String? email;

  @override
  Future<Sesion?> leer() async => sesion;
  @override
  Future<void> guardar(Sesion s) async => sesion = s;
  @override
  Future<void> borrar() async => sesion = null;
  @override
  Future<({String? servidor, String? email})> ultimoIngreso() async => (servidor: servidor, email: email);
  @override
  Future<void> recordarIngreso(String s, String e) async {
    servidor = s;
    email = e;
  }
}

class AlmacenLocalEnMemoria implements AlmacenLocal {
  Catalogo? catalogo;

  @override
  Future<Catalogo?> leerCatalogo() async => catalogo;
  @override
  Future<void> guardarCatalogo(Catalogo c) async => catalogo = c;
  @override
  Future<void> borrarCatalogo() async => catalogo = null;
}
