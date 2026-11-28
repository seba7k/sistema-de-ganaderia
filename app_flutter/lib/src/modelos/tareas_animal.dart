import 'dart:convert';

import '../datos/base_local.dart';
import 'modelos.dart';

String _normalizar(String? valor) =>
    (valor ?? '').toUpperCase().replaceAll(RegExp(r'\s+'), '');

List<Animal> buscarAnimales(
  Iterable<Animal> animales,
  String consulta, {
  required bool soloHembras,
}) {
  final busqueda = _normalizar(consulta);
  if (busqueda.isEmpty) return [];
  final exactos = <Animal>[];
  final parciales = <Animal>[];
  for (final animal in animales) {
    if (soloHembras && animal.sexo != 'HEMBRA') continue;
    final ids = [animal.diio, animal.eid, animal.interno].map(_normalizar);
    if (ids.contains(busqueda)) {
      exactos.add(animal);
    } else if (parciales.length < 20 &&
        ids.any((id) => id.contains(busqueda))) {
      parciales.add(animal);
    }
  }
  return exactos.isEmpty ? parciales : exactos;
}

String fechaIso(DateTime fecha) => fecha.toIso8601String().split('T').first;

String fechaVisible(String iso) => iso.split('-').reversed.join('/');

String? leerFecha(String entrada, DateTime hoy) {
  final partes = entrada.trim().split('/');
  if (partes.length != 3 ||
      !RegExp(r'^\d{1,2}/\d{1,2}/\d{4}$').hasMatch(entrada.trim())) {
    return null;
  }
  final iso =
      '${partes[2]}-${partes[1].padLeft(2, '0')}-${partes[0].padLeft(2, '0')}';
  final fecha = DateTime.tryParse(iso);
  if (fecha == null ||
      fechaIso(fecha) != iso ||
      iso.compareTo(fechaIso(hoy)) > 0) {
    return null;
  }
  return iso;
}

String? avisoVariacionPeso(
  Animal animal,
  List<Envio> envios,
  String fecha,
  double peso,
  double gdpMaxima,
) {
  final fechaCatalogo = animal.fechaPeso;
  String? fechaAnterior;
  double? pesoAnterior;
  if (fechaCatalogo != null && fechaCatalogo.compareTo(fecha) <= 0) {
    fechaAnterior = fechaCatalogo;
    pesoAnterior = animal.peso;
  }
  for (final envio in envios) {
    if (envio.tipo != 'PESADA' || envio.estado == 'RECHAZADA') continue;
    final datos = jsonDecode(envio.datos) as Map<String, dynamic>;
    final dia = datos['fecha'];
    final kg = datos['peso_kg'];
    if (datos['animal_id'] == animal.id &&
        dia is String &&
        dia.compareTo(fecha) <= 0 &&
        (fechaAnterior == null || dia.compareTo(fechaAnterior) >= 0) &&
        kg is num) {
      fechaAnterior = dia;
      pesoAnterior = kg.toDouble();
    }
  }
  if (fechaAnterior == null || pesoAnterior == null) return null;
  final anterior = DateTime.tryParse('${fechaAnterior}T00:00:00Z');
  if (anterior == null) return null;
  final dias = DateTime.parse('${fecha}T00:00:00Z').difference(anterior).inDays;
  final diferencia = peso - pesoAnterior;
  String decimal(double valor, int precision) =>
      valor.toStringAsFixed(precision).replaceFirst('.', ',');
  if (dias == 0) {
    return diferencia.abs() > 15
        ? 'Hay otro pesaje el mismo día con ${decimal(diferencia.abs(), 1)} kg de diferencia.'
        : null;
  }
  final gdp = (diferencia / dias * 100).round() / 100;
  if (diferencia < 0) {
    return 'El animal perdió ${decimal(diferencia.abs(), 1)} kg en $dias día(s).';
  }
  if (gdp > gdpMaxima) {
    return 'El animal ganó ${decimal(gdp, 2)} kg/día. El límite es ${decimal(gdpMaxima, 2)} kg/día.';
  }
  return null;
}
