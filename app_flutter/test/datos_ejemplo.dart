import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Respuestas de ejemplo con el formato exacto de docs/api-terreno.md.
const tokenEjemplo = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

Map<String, dynamic> usuarioEjemplo({String rol = 'OPERARIO', String organizacion = 'Ganadera Los Robles'}) => {
      'id': 3,
      'nombre': 'Juan Pérez',
      'rol': rol,
      'rol_nombre': rol == 'OPERARIO' ? 'Operario' : 'Administrador',
      'organizacion': organizacion,
    };

const operacionesOperario = ['PESADA', 'PARTO', 'SECADO', 'NOTA', 'ENTREGA', 'TRASLADO', 'BAJA'];

Map<String, dynamic> sesionEjemplo({List<String> operaciones = operacionesOperario}) => {
      'token': tokenEjemplo,
      'expira_en': '2026-12-27 10:00:00',
      'usuario': usuarioEjemplo(),
      'operaciones': operaciones,
    };

Map<String, dynamic> catalogoEjemplo({List<String> operaciones = operacionesOperario}) => {
      'generado_en': '2026-11-27 09:15:00',
      'hoy': '2026-11-27',
      'gdp_maxima': 2.5,
      'formato_diio': '',
      'animales': [
        {
          'id': 10, 'diio': 'A-0143', 'eid': '982000123456789', 'interno': null, 'sexo': 'HEMBRA',
          'categoria': 'VACA', 'etapa': 'REPRODUCCION', 'raza': 'Angus', 'corral_id': 2, 'peso': 452.5,
          'fecha_peso': '2026-11-01', 'carencia_hasta': '2026-12-05', 'parto_probable': '2027-02-10', 'secado': null,
        },
        {
          'id': 11, 'diio': null, 'eid': null, 'interno': 'T-12', 'sexo': 'MACHO', 'categoria': 'TERNERO',
          'etapa': 'CRIA', 'raza': null, 'corral_id': null, 'peso': null, 'fecha_peso': null,
          'carencia_hasta': null, 'parto_probable': null, 'secado': null,
        },
      ],
      'corrales': [
        {'id': 2, 'nombre': 'Corral 2', 'tipo': 'CORRAL', 'capacidad': 60, 'ocupacion': 55},
      ],
      'raciones': [
        {'id': 1, 'nombre': 'Terminación', 'etapa': 'TERMINACION', 'materia_seca': 72.5},
      ],
      'productos': [
        {'id': 4, 'nombre': 'Ivermectina 1%', 'unidad': 'MILILITRO', 'carencia': 35, 'stock': 480},
      ],
      'usuario': usuarioEjemplo(),
      'operaciones': operaciones,
    };

http.Response json(Object cuerpo, [int codigo = 200]) =>
    http.Response.bytes(utf8.encode(jsonEncode(cuerpo)), codigo, headers: {'content-type': 'application/json; charset=utf-8'});

/// Servidor falso: responde según la ruta `r` y anota lo que recibió.
class ServidorFalso {
  final pedidos = <http.Request>[];
  http.Response Function(http.Request)? sesion;
  http.Response Function(http.Request)? catalogo;
  http.Response Function(http.Request)? cerrar;

  late final cliente = MockClient((pedido) async {
    pedidos.add(pedido);
    final ruta = pedido.url.queryParameters['r'];
    final respuesta = switch (ruta) {
      '/api/sesion' => sesion?.call(pedido),
      '/api/catalogo' => catalogo?.call(pedido),
      '/api/sesion/cerrar' => cerrar?.call(pedido),
      _ => null,
    };
    return respuesta ?? json({'error': 'Ruta de API desconocida.'}, 404);
  });
}
