/// Modelos de la API de terreno. Siguen al pie de la letra el contrato de
/// `docs/api-terreno.md`: si el servidor cambia un campo, se cambia acá y en
/// las pruebas de contrato del servidor.
library;

int _entero(Object? v) => v is int ? v : int.tryParse('$v') ?? 0;
int? _enteroONulo(Object? v) => v == null ? null : (v is int ? v : int.tryParse('$v'));
double? _decimal(Object? v) => v == null ? null : (v is num ? v.toDouble() : double.tryParse('$v'));
String? _texto(Object? v) => v == null ? null : '$v';
List<Map<String, dynamic>> _lista(Object? v) =>
    v is List ? v.whereType<Map>().map((m) => Map<String, dynamic>.from(m)).toList() : const [];

class Usuario {
  const Usuario({required this.id, required this.nombre, required this.rol, required this.rolNombre, required this.organizacion, this.organizacionId});

  final int id;
  final String nombre;
  final String rol;
  final String rolNombre;
  final String organizacion;
  final int? organizacionId;

  factory Usuario.fromJson(Map<String, dynamic> j) => Usuario(
        id: _entero(j['id']),
        nombre: '${j['nombre'] ?? ''}',
        rol: '${j['rol'] ?? ''}',
        rolNombre: '${j['rol_nombre'] ?? ''}',
        organizacion: '${j['organizacion'] ?? ''}',
        organizacionId: _enteroONulo(j['organizacion_id']),
      );

  Map<String, dynamic> toJson() =>
      {'id': id, 'nombre': nombre, 'rol': rol, 'rol_nombre': rolNombre, 'organizacion': organizacion, 'organizacion_id': ?organizacionId};
}

/// Sesión abierta en el teléfono: el token y a qué servidor pertenece.
class Sesion {
  const Sesion({
    required this.servidor,
    required this.token,
    required this.expiraEn,
    required this.usuario,
    required this.operaciones,
  });

  final String servidor;
  final String token;
  final String expiraEn;
  final Usuario usuario;

  /// Tareas que el rol puede registrar (PESADA, PARTO…). La app muestra solo esas.
  final List<String> operaciones;

  factory Sesion.fromJson(Map<String, dynamic> j, {required String servidor}) => Sesion(
        servidor: servidor,
        token: '${j['token']}',
        expiraEn: '${j['expira_en'] ?? ''}',
        usuario: Usuario.fromJson(Map<String, dynamic>.from(j['usuario'] as Map)),
        operaciones: (j['operaciones'] as List? ?? const []).map((e) => '$e').toList(),
      );

  Map<String, dynamic> toJson() => {
        'servidor': servidor,
        'token': token,
        'expira_en': expiraEn,
        'usuario': usuario.toJson(),
        'operaciones': operaciones,
      };

  static Sesion desdeGuardada(Map<String, dynamic> j) => Sesion.fromJson(j, servidor: '${j['servidor']}');

  Sesion conOperaciones(List<String> nuevas, Usuario usuarioActual) => Sesion(
        servidor: servidor,
        token: token,
        expiraEn: expiraEn,
        usuario: usuarioActual,
        operaciones: nuevas,
      );
}

/// Organización para elegir cuando la persona trabaja en más de una (respuesta 409).
class OrganizacionElegible {
  const OrganizacionElegible({required this.id, required this.nombre, required this.rol});

  final int id;
  final String nombre;
  final String rol;

  factory OrganizacionElegible.fromJson(Map<String, dynamic> j) =>
      OrganizacionElegible(id: _entero(j['id']), nombre: '${j['nombre'] ?? ''}', rol: '${j['rol'] ?? ''}');
}

class Animal {
  const Animal({
    required this.id,
    this.diio,
    this.eid,
    this.interno,
    required this.sexo,
    required this.categoria,
    this.etapa,
    this.raza,
    this.corralId,
    this.peso,
    this.fechaPeso,
    this.carenciaHasta,
    this.partoProbable,
    this.secado,
  });

  final int id;
  final String? diio;
  final String? eid;
  final String? interno;
  final String sexo;
  final String categoria;
  final String? etapa;
  final String? raza;
  final int? corralId;
  final double? peso;
  final String? fechaPeso;

  /// Desde qué día puede salir del predio (resguardo sanitario); null si no tiene.
  final String? carenciaHasta;
  final String? partoProbable;
  final String? secado;

  /// Cómo se nombra al animal en pantalla: DIIO, si no interno, si no EID.
  String get nombre => diio ?? interno ?? eid ?? '#$id';

  factory Animal.fromJson(Map<String, dynamic> j) => Animal(
        id: _entero(j['id']),
        diio: _texto(j['diio']),
        eid: _texto(j['eid']),
        interno: _texto(j['interno']),
        sexo: '${j['sexo'] ?? ''}',
        categoria: '${j['categoria'] ?? ''}',
        etapa: _texto(j['etapa']),
        raza: _texto(j['raza']),
        corralId: _enteroONulo(j['corral_id']),
        peso: _decimal(j['peso']),
        fechaPeso: _texto(j['fecha_peso']),
        carenciaHasta: _texto(j['carencia_hasta']),
        partoProbable: _texto(j['parto_probable']),
        secado: _texto(j['secado']),
      );
}

class Corral {
  const Corral({required this.id, required this.nombre, required this.tipo, required this.capacidad, required this.ocupacion});

  final int id;
  final String nombre;
  final String tipo;
  final int capacidad;
  final int ocupacion;

  factory Corral.fromJson(Map<String, dynamic> j) => Corral(
        id: _entero(j['id']),
        nombre: '${j['nombre'] ?? ''}',
        tipo: '${j['tipo'] ?? ''}',
        capacidad: _entero(j['capacidad']),
        ocupacion: _entero(j['ocupacion']),
      );
}

class Racion {
  const Racion({required this.id, required this.nombre, required this.etapa, required this.materiaSeca});

  final int id;
  final String nombre;
  final String etapa;
  final double materiaSeca;

  factory Racion.fromJson(Map<String, dynamic> j) => Racion(
        id: _entero(j['id']),
        nombre: '${j['nombre'] ?? ''}',
        etapa: '${j['etapa'] ?? ''}',
        materiaSeca: _decimal(j['materia_seca']) ?? 0,
      );
}

class Producto {
  const Producto({required this.id, required this.nombre, required this.unidad, required this.carencia, required this.stock});

  final int id;
  final String nombre;
  final String unidad;
  final int carencia;
  final double stock;

  factory Producto.fromJson(Map<String, dynamic> j) => Producto(
        id: _entero(j['id']),
        nombre: '${j['nombre'] ?? ''}',
        unidad: '${j['unidad'] ?? ''}',
        carencia: _entero(j['carencia']),
        stock: _decimal(j['stock']) ?? 0,
      );
}

class RegistroMva {
  const RegistroMva({required this.numero, required this.rut, required this.vigenteHasta, required this.vigente});

  final String numero;
  final String rut;
  final String vigenteHasta;
  final bool vigente;

  factory RegistroMva.fromJson(Map<String, dynamic> j) => RegistroMva(
        numero: '${j['numero'] ?? ''}',
        rut: '${j['rut'] ?? ''}',
        vigenteHasta: '${j['vigente_hasta'] ?? ''}',
        vigente: j['vigente'] == true,
      );
}

/// Todo lo que el teléfono necesita para trabajar sin señal.
///
/// Se guarda el JSON tal como llegó (ver [crudo]) y se vuelve a leer con
/// [Catalogo.fromJson]: así un campo nuevo del servidor no se pierde al guardar.
class Catalogo {
  const Catalogo({
    required this.crudo,
    required this.generadoEn,
    required this.hoy,
    required this.gdpMaxima,
    required this.formatoDiio,
    required this.animales,
    required this.corrales,
    required this.raciones,
    required this.productos,
    required this.usuario,
    required this.operaciones,
    this.mva,
    this.porValidar = const [],
    this.porValidarTotal = 0,
  });

  final Map<String, dynamic> crudo;
  final String generadoEn;
  final String hoy;
  final double gdpMaxima;
  final String formatoDiio;
  final List<Animal> animales;
  final List<Corral> corrales;
  final List<Racion> raciones;
  final List<Producto> productos;
  final Usuario usuario;
  final List<String> operaciones;
  final RegistroMva? mva;
  final List<Map<String, dynamic>> porValidar;
  final int porValidarTotal;

  factory Catalogo.fromJson(Map<String, dynamic> j) => Catalogo(
        crudo: j,
        generadoEn: '${j['generado_en'] ?? ''}',
        hoy: '${j['hoy'] ?? ''}',
        gdpMaxima: _decimal(j['gdp_maxima']) ?? 2.5,
        formatoDiio: '${j['formato_diio'] ?? ''}',
        animales: _lista(j['animales']).map(Animal.fromJson).toList(),
        corrales: _lista(j['corrales']).map(Corral.fromJson).toList(),
        raciones: _lista(j['raciones']).map(Racion.fromJson).toList(),
        productos: _lista(j['productos']).map(Producto.fromJson).toList(),
        usuario: Usuario.fromJson(Map<String, dynamic>.from(j['usuario'] as Map? ?? const {})),
        operaciones: (j['operaciones'] as List? ?? const []).map((e) => '$e').toList(),
        mva: j['mva'] is Map ? RegistroMva.fromJson(Map<String, dynamic>.from(j['mva'] as Map)) : null,
        porValidar: _lista(j['por_validar']),
        porValidarTotal: _entero(j['por_validar_total']),
      );
}
