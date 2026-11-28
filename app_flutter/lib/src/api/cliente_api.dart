import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../modelos/modelos.dart';

/// Error de la API, con el mensaje para mostrar tal como lo manda el servidor.
class ErrorApi implements Exception {
  const ErrorApi(this.mensaje, {this.codigo, this.sinRed = false, this.datos = const {}});

  final String mensaje;

  /// Código HTTP; null si no hubo respuesta.
  final int? codigo;

  /// No se pudo hablar con el servidor (sin señal, servidor caído, dirección mal escrita).
  final bool sinRed;
  final Map<String, dynamic> datos;

  /// La sesión venció o el token fue revocado: hay que volver a ingresar.
  bool get sesionVencida => codigo == 401;

  @override
  String toString() => mensaje;
}

/// Resultado del ingreso: o entra, o tiene que elegir organización (409).
sealed class ResultadoIngreso {
  const ResultadoIngreso();
}

class Ingresado extends ResultadoIngreso {
  const Ingresado(this.sesion);
  final Sesion sesion;
}

class EligeOrganizacion extends ResultadoIngreso {
  const EligeOrganizacion(this.mensaje, this.organizaciones);
  final String mensaje;
  final List<OrganizacionElegible> organizaciones;
}

/// Cliente de la API de terreno (`docs/api-terreno.md`).
///
/// Usa la forma `index.php?r=/api/<ruta>`, que funciona en cualquier hosting, y
/// manda el token en `Authorization` y en `X-Token`, porque hay hostings que
/// descartan la primera.
class ClienteApi {
  ClienteApi({http.Client? cliente, this.tiempoMaximo = const Duration(seconds: 30)})
      : _http = cliente ?? http.Client();

  final http.Client _http;
  final Duration tiempoMaximo;

  /// Deja la dirección del servidor en una forma única: con esquema y sin
  /// `index.php` ni barra final. Null si no parece una dirección.
  static String? normalizarServidor(String escrito) {
    var s = escrito.trim();
    if (s.isEmpty) return null;
    if (!s.startsWith(RegExp(r'https?://', caseSensitive: false))) s = 'https://$s';
    s = s.replaceFirst(RegExp(r'/index\.php.*$', caseSensitive: false), '');
    s = s.replaceFirst(RegExp(r'/+$'), '');
    final uri = Uri.tryParse(s);
    if (uri == null || uri.host.isEmpty || !uri.hasScheme) return null;
    return s;
  }

  static Uri urlDe(String servidor, String ruta) =>
      Uri.parse('$servidor/index.php').replace(queryParameters: {'r': '/api/$ruta'});

  Future<ResultadoIngreso> ingresar({
    required String servidor,
    required String email,
    required String password,
    int? organizacionId,
  }) async {
    try {
      final datos = await _llamar('POST', servidor, 'sesion', cuerpo: {
        'email': email.trim(),
        'password': password,
        'organizacion_id': ?organizacionId,
      });
      return Ingresado(Sesion.fromJson(datos, servidor: servidor));
    } on ErrorApi catch (e) {
      if (e.codigo == 409) {
        final lista = (e.datos['organizaciones'] as List? ?? const [])
            .whereType<Map>()
            .map((m) => OrganizacionElegible.fromJson(Map<String, dynamic>.from(m)))
            .toList();
        return EligeOrganizacion(e.mensaje, lista);
      }
      rethrow;
    }
  }

  Future<Catalogo> catalogo(Sesion sesion) async =>
      Catalogo.fromJson(await _llamar('GET', sesion.servidor, 'catalogo', token: sesion.token));

  Future<void> cerrarSesion(Sesion sesion) async {
    await _llamar('POST', sesion.servidor, 'sesion/cerrar', token: sesion.token);
  }

  Future<Map<String, dynamic>> _llamar(
    String metodo,
    String servidor,
    String ruta, {
    Map<String, dynamic>? cuerpo,
    String? token,
  }) async {
    final cabeceras = <String, String>{
      'Accept': 'application/json',
      if (cuerpo != null) 'Content-Type': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
      'X-Token': ?token,
    };
    final url = urlDe(servidor, ruta);

    http.Response respuesta;
    try {
      final pedido = metodo == 'GET'
          ? _http.get(url, headers: cabeceras)
          : _http.post(url, headers: cabeceras, body: cuerpo == null ? null : jsonEncode(cuerpo));
      respuesta = await pedido.timeout(tiempoMaximo);
    } on TimeoutException {
      throw const ErrorApi('El servidor no respondió a tiempo.', sinRed: true);
    } on http.ClientException {
      throw const ErrorApi('Sin conexión con el servidor.', sinRed: true);
    } catch (_) {
      // SocketException, HandshakeException y similares: no hubo conversación.
      throw const ErrorApi('Sin conexión con el servidor.', sinRed: true);
    }

    Map<String, dynamic> datos = const {};
    try {
      final decodificado = jsonDecode(utf8.decode(respuesta.bodyBytes));
      if (decodificado is Map) datos = Map<String, dynamic>.from(decodificado);
    } catch (_) {
      // Respuesta vacía o no JSON (página de error del hosting, por ejemplo).
    }

    if (respuesta.statusCode != 200) {
      final mensaje = datos['error'] is String
          ? datos['error'] as String
          : (respuesta.statusCode == 404
              ? 'No se encontró la API en esa dirección. Revisá la dirección del servidor.'
              : 'El servidor respondió ${respuesta.statusCode}.');
      throw ErrorApi(mensaje, codigo: respuesta.statusCode, datos: datos);
    }
    if (datos.isEmpty) {
      throw ErrorApi('La respuesta del servidor no es válida. Revisá la dirección del servidor.', codigo: respuesta.statusCode);
    }
    return datos;
  }
}
