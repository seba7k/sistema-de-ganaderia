import 'package:flutter/material.dart';

/// Las tareas de terreno, una por tipo de operación de la API. El orden es el
/// de la pantalla de inicio; cada persona ve solo las que su rol permite.
class Tarea {
  const Tarea(this.tipo, this.titulo, this.detalle, this.icono, this.color);

  final String tipo;
  final String titulo;
  final String detalle;
  final IconData icono;
  final Color color;
}

const tareas = <Tarea>[
  Tarea('PESADA', 'Pesaje', 'Peso e hito de crecimiento', Icons.scale_outlined, Color(0xFF15703A)),
  Tarea('PARTO', 'Parto', 'Exitoso, aborto o ternero débil', Icons.favorite_outline, Color(0xFFA3386B)),
  Tarea('TRATAMIENTO', 'Tratamiento', 'Producto, dosis y carencia', Icons.vaccines_outlined, Color(0xFF1F5F8B)),
  Tarea('ENTREGA', 'Alimentación', 'Ración a un corral o a un animal', Icons.grass, Color(0xFF95650F)),
  Tarea('TRASLADO', 'Traslado', 'Mover animales de corral', Icons.swap_horiz, Color(0xFF0E7470)),
  Tarea('SECADO', 'Secado', 'Fin de la lactancia', Icons.water_drop_outlined, Color(0xFF5B4B9A)),
  Tarea('NOTA', 'Nota', 'Anotar lo que le pasó al animal', Icons.edit_note, Color(0xFF7A5C1E)),
  Tarea('ANIMAL', 'Alta de animal', 'Uno comprado o que faltaba cargar', Icons.add_circle_outline, Color(0xFF15703A)),
  Tarea('BAJA', 'Baja por muerte', 'El animal murió', Icons.remove_circle_outline, Color(0xFF8A1C14)),
  Tarea('VALIDACION', 'Validar tratamientos', 'Firma del médico veterinario autorizado', Icons.verified_outlined, Color(0xFF1F5F8B)),
  Tarea('SALIDA', 'Salida del predio', 'Venta, faena o traslado, con bloqueo sanitario', Icons.local_shipping_outlined, Color(0xFF9A3412)),
];

/// Las tareas que puede hacer quien tiene estas operaciones permitidas.
List<Tarea> tareasPermitidas(List<String> operaciones) =>
    tareas.where((t) => operaciones.contains(t.tipo)).toList();
