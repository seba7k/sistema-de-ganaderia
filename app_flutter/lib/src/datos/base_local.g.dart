// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'base_local.dart';

// ignore_for_file: type=lint
class $GuardadosTable extends Guardados
    with TableInfo<$GuardadosTable, Guardado> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $GuardadosTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _claveMeta = const VerificationMeta('clave');
  @override
  late final GeneratedColumn<String> clave = GeneratedColumn<String>(
    'clave',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _valorMeta = const VerificationMeta('valor');
  @override
  late final GeneratedColumn<String> valor = GeneratedColumn<String>(
    'valor',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _actualizadoMeta = const VerificationMeta(
    'actualizado',
  );
  @override
  late final GeneratedColumn<DateTime> actualizado = GeneratedColumn<DateTime>(
    'actualizado',
    aliasedName,
    false,
    type: DriftSqlType.dateTime,
    requiredDuringInsert: false,
    defaultValue: currentDateAndTime,
  );
  @override
  List<GeneratedColumn> get $columns => [clave, valor, actualizado];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'guardados';
  @override
  VerificationContext validateIntegrity(
    Insertable<Guardado> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('clave')) {
      context.handle(
        _claveMeta,
        clave.isAcceptableOrUnknown(data['clave']!, _claveMeta),
      );
    } else if (isInserting) {
      context.missing(_claveMeta);
    }
    if (data.containsKey('valor')) {
      context.handle(
        _valorMeta,
        valor.isAcceptableOrUnknown(data['valor']!, _valorMeta),
      );
    } else if (isInserting) {
      context.missing(_valorMeta);
    }
    if (data.containsKey('actualizado')) {
      context.handle(
        _actualizadoMeta,
        actualizado.isAcceptableOrUnknown(
          data['actualizado']!,
          _actualizadoMeta,
        ),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {clave};
  @override
  Guardado map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return Guardado(
      clave: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}clave'],
      )!,
      valor: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}valor'],
      )!,
      actualizado: attachedDatabase.typeMapping.read(
        DriftSqlType.dateTime,
        data['${effectivePrefix}actualizado'],
      )!,
    );
  }

  @override
  $GuardadosTable createAlias(String alias) {
    return $GuardadosTable(attachedDatabase, alias);
  }
}

class Guardado extends DataClass implements Insertable<Guardado> {
  final String clave;
  final String valor;
  final DateTime actualizado;
  const Guardado({
    required this.clave,
    required this.valor,
    required this.actualizado,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['clave'] = Variable<String>(clave);
    map['valor'] = Variable<String>(valor);
    map['actualizado'] = Variable<DateTime>(actualizado);
    return map;
  }

  GuardadosCompanion toCompanion(bool nullToAbsent) {
    return GuardadosCompanion(
      clave: Value(clave),
      valor: Value(valor),
      actualizado: Value(actualizado),
    );
  }

  factory Guardado.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return Guardado(
      clave: serializer.fromJson<String>(json['clave']),
      valor: serializer.fromJson<String>(json['valor']),
      actualizado: serializer.fromJson<DateTime>(json['actualizado']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'clave': serializer.toJson<String>(clave),
      'valor': serializer.toJson<String>(valor),
      'actualizado': serializer.toJson<DateTime>(actualizado),
    };
  }

  Guardado copyWith({String? clave, String? valor, DateTime? actualizado}) =>
      Guardado(
        clave: clave ?? this.clave,
        valor: valor ?? this.valor,
        actualizado: actualizado ?? this.actualizado,
      );
  Guardado copyWithCompanion(GuardadosCompanion data) {
    return Guardado(
      clave: data.clave.present ? data.clave.value : this.clave,
      valor: data.valor.present ? data.valor.value : this.valor,
      actualizado: data.actualizado.present
          ? data.actualizado.value
          : this.actualizado,
    );
  }

  @override
  String toString() {
    return (StringBuffer('Guardado(')
          ..write('clave: $clave, ')
          ..write('valor: $valor, ')
          ..write('actualizado: $actualizado')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(clave, valor, actualizado);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is Guardado &&
          other.clave == this.clave &&
          other.valor == this.valor &&
          other.actualizado == this.actualizado);
}

class GuardadosCompanion extends UpdateCompanion<Guardado> {
  final Value<String> clave;
  final Value<String> valor;
  final Value<DateTime> actualizado;
  final Value<int> rowid;
  const GuardadosCompanion({
    this.clave = const Value.absent(),
    this.valor = const Value.absent(),
    this.actualizado = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  GuardadosCompanion.insert({
    required String clave,
    required String valor,
    this.actualizado = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : clave = Value(clave),
       valor = Value(valor);
  static Insertable<Guardado> custom({
    Expression<String>? clave,
    Expression<String>? valor,
    Expression<DateTime>? actualizado,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (clave != null) 'clave': clave,
      if (valor != null) 'valor': valor,
      if (actualizado != null) 'actualizado': actualizado,
      if (rowid != null) 'rowid': rowid,
    });
  }

  GuardadosCompanion copyWith({
    Value<String>? clave,
    Value<String>? valor,
    Value<DateTime>? actualizado,
    Value<int>? rowid,
  }) {
    return GuardadosCompanion(
      clave: clave ?? this.clave,
      valor: valor ?? this.valor,
      actualizado: actualizado ?? this.actualizado,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (clave.present) {
      map['clave'] = Variable<String>(clave.value);
    }
    if (valor.present) {
      map['valor'] = Variable<String>(valor.value);
    }
    if (actualizado.present) {
      map['actualizado'] = Variable<DateTime>(actualizado.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('GuardadosCompanion(')
          ..write('clave: $clave, ')
          ..write('valor: $valor, ')
          ..write('actualizado: $actualizado, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

class $EnviosTable extends Envios with TableInfo<$EnviosTable, Envio> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $EnviosTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _ordenMeta = const VerificationMeta('orden');
  @override
  late final GeneratedColumn<int> orden = GeneratedColumn<int>(
    'orden',
    aliasedName,
    false,
    hasAutoIncrement: true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'PRIMARY KEY AUTOINCREMENT',
    ),
  );
  static const VerificationMeta _uuidMeta = const VerificationMeta('uuid');
  @override
  late final GeneratedColumn<String> uuid = GeneratedColumn<String>(
    'uuid',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
    defaultConstraints: GeneratedColumn.constraintIsAlways('UNIQUE'),
  );
  static const VerificationMeta _servidorMeta = const VerificationMeta(
    'servidor',
  );
  @override
  late final GeneratedColumn<String> servidor = GeneratedColumn<String>(
    'servidor',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _usuarioIdMeta = const VerificationMeta(
    'usuarioId',
  );
  @override
  late final GeneratedColumn<int> usuarioId = GeneratedColumn<int>(
    'usuario_id',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _organizacionIdMeta = const VerificationMeta(
    'organizacionId',
  );
  @override
  late final GeneratedColumn<int> organizacionId = GeneratedColumn<int>(
    'organizacion_id',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _tipoMeta = const VerificationMeta('tipo');
  @override
  late final GeneratedColumn<String> tipo = GeneratedColumn<String>(
    'tipo',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _datosMeta = const VerificationMeta('datos');
  @override
  late final GeneratedColumn<String> datos = GeneratedColumn<String>(
    'datos',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _registradoEnMeta = const VerificationMeta(
    'registradoEn',
  );
  @override
  late final GeneratedColumn<String> registradoEn = GeneratedColumn<String>(
    'registrado_en',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _estadoMeta = const VerificationMeta('estado');
  @override
  late final GeneratedColumn<String> estado = GeneratedColumn<String>(
    'estado',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
    defaultValue: const Constant('PENDIENTE'),
  );
  static const VerificationMeta _mensajeMeta = const VerificationMeta(
    'mensaje',
  );
  @override
  late final GeneratedColumn<String> mensaje = GeneratedColumn<String>(
    'mensaje',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _requiereConfirmacionMeta =
      const VerificationMeta('requiereConfirmacion');
  @override
  late final GeneratedColumn<bool> requiereConfirmacion = GeneratedColumn<bool>(
    'requiere_confirmacion',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("requiere_confirmacion" IN (0, 1))',
    ),
    defaultValue: const Constant(false),
  );
  @override
  List<GeneratedColumn> get $columns => [
    orden,
    uuid,
    servidor,
    usuarioId,
    organizacionId,
    tipo,
    datos,
    registradoEn,
    estado,
    mensaje,
    requiereConfirmacion,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'envios';
  @override
  VerificationContext validateIntegrity(
    Insertable<Envio> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('orden')) {
      context.handle(
        _ordenMeta,
        orden.isAcceptableOrUnknown(data['orden']!, _ordenMeta),
      );
    }
    if (data.containsKey('uuid')) {
      context.handle(
        _uuidMeta,
        uuid.isAcceptableOrUnknown(data['uuid']!, _uuidMeta),
      );
    } else if (isInserting) {
      context.missing(_uuidMeta);
    }
    if (data.containsKey('servidor')) {
      context.handle(
        _servidorMeta,
        servidor.isAcceptableOrUnknown(data['servidor']!, _servidorMeta),
      );
    } else if (isInserting) {
      context.missing(_servidorMeta);
    }
    if (data.containsKey('usuario_id')) {
      context.handle(
        _usuarioIdMeta,
        usuarioId.isAcceptableOrUnknown(data['usuario_id']!, _usuarioIdMeta),
      );
    } else if (isInserting) {
      context.missing(_usuarioIdMeta);
    }
    if (data.containsKey('organizacion_id')) {
      context.handle(
        _organizacionIdMeta,
        organizacionId.isAcceptableOrUnknown(
          data['organizacion_id']!,
          _organizacionIdMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_organizacionIdMeta);
    }
    if (data.containsKey('tipo')) {
      context.handle(
        _tipoMeta,
        tipo.isAcceptableOrUnknown(data['tipo']!, _tipoMeta),
      );
    } else if (isInserting) {
      context.missing(_tipoMeta);
    }
    if (data.containsKey('datos')) {
      context.handle(
        _datosMeta,
        datos.isAcceptableOrUnknown(data['datos']!, _datosMeta),
      );
    } else if (isInserting) {
      context.missing(_datosMeta);
    }
    if (data.containsKey('registrado_en')) {
      context.handle(
        _registradoEnMeta,
        registradoEn.isAcceptableOrUnknown(
          data['registrado_en']!,
          _registradoEnMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_registradoEnMeta);
    }
    if (data.containsKey('estado')) {
      context.handle(
        _estadoMeta,
        estado.isAcceptableOrUnknown(data['estado']!, _estadoMeta),
      );
    }
    if (data.containsKey('mensaje')) {
      context.handle(
        _mensajeMeta,
        mensaje.isAcceptableOrUnknown(data['mensaje']!, _mensajeMeta),
      );
    }
    if (data.containsKey('requiere_confirmacion')) {
      context.handle(
        _requiereConfirmacionMeta,
        requiereConfirmacion.isAcceptableOrUnknown(
          data['requiere_confirmacion']!,
          _requiereConfirmacionMeta,
        ),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {orden};
  @override
  Envio map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return Envio(
      orden: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}orden'],
      )!,
      uuid: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}uuid'],
      )!,
      servidor: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}servidor'],
      )!,
      usuarioId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}usuario_id'],
      )!,
      organizacionId: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}organizacion_id'],
      )!,
      tipo: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}tipo'],
      )!,
      datos: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}datos'],
      )!,
      registradoEn: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}registrado_en'],
      )!,
      estado: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}estado'],
      )!,
      mensaje: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}mensaje'],
      ),
      requiereConfirmacion: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}requiere_confirmacion'],
      )!,
    );
  }

  @override
  $EnviosTable createAlias(String alias) {
    return $EnviosTable(attachedDatabase, alias);
  }
}

class Envio extends DataClass implements Insertable<Envio> {
  final int orden;
  final String uuid;
  final String servidor;
  final int usuarioId;
  final int organizacionId;
  final String tipo;
  final String datos;
  final String registradoEn;
  final String estado;
  final String? mensaje;
  final bool requiereConfirmacion;
  const Envio({
    required this.orden,
    required this.uuid,
    required this.servidor,
    required this.usuarioId,
    required this.organizacionId,
    required this.tipo,
    required this.datos,
    required this.registradoEn,
    required this.estado,
    this.mensaje,
    required this.requiereConfirmacion,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['orden'] = Variable<int>(orden);
    map['uuid'] = Variable<String>(uuid);
    map['servidor'] = Variable<String>(servidor);
    map['usuario_id'] = Variable<int>(usuarioId);
    map['organizacion_id'] = Variable<int>(organizacionId);
    map['tipo'] = Variable<String>(tipo);
    map['datos'] = Variable<String>(datos);
    map['registrado_en'] = Variable<String>(registradoEn);
    map['estado'] = Variable<String>(estado);
    if (!nullToAbsent || mensaje != null) {
      map['mensaje'] = Variable<String>(mensaje);
    }
    map['requiere_confirmacion'] = Variable<bool>(requiereConfirmacion);
    return map;
  }

  EnviosCompanion toCompanion(bool nullToAbsent) {
    return EnviosCompanion(
      orden: Value(orden),
      uuid: Value(uuid),
      servidor: Value(servidor),
      usuarioId: Value(usuarioId),
      organizacionId: Value(organizacionId),
      tipo: Value(tipo),
      datos: Value(datos),
      registradoEn: Value(registradoEn),
      estado: Value(estado),
      mensaje: mensaje == null && nullToAbsent
          ? const Value.absent()
          : Value(mensaje),
      requiereConfirmacion: Value(requiereConfirmacion),
    );
  }

  factory Envio.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return Envio(
      orden: serializer.fromJson<int>(json['orden']),
      uuid: serializer.fromJson<String>(json['uuid']),
      servidor: serializer.fromJson<String>(json['servidor']),
      usuarioId: serializer.fromJson<int>(json['usuarioId']),
      organizacionId: serializer.fromJson<int>(json['organizacionId']),
      tipo: serializer.fromJson<String>(json['tipo']),
      datos: serializer.fromJson<String>(json['datos']),
      registradoEn: serializer.fromJson<String>(json['registradoEn']),
      estado: serializer.fromJson<String>(json['estado']),
      mensaje: serializer.fromJson<String?>(json['mensaje']),
      requiereConfirmacion: serializer.fromJson<bool>(
        json['requiereConfirmacion'],
      ),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'orden': serializer.toJson<int>(orden),
      'uuid': serializer.toJson<String>(uuid),
      'servidor': serializer.toJson<String>(servidor),
      'usuarioId': serializer.toJson<int>(usuarioId),
      'organizacionId': serializer.toJson<int>(organizacionId),
      'tipo': serializer.toJson<String>(tipo),
      'datos': serializer.toJson<String>(datos),
      'registradoEn': serializer.toJson<String>(registradoEn),
      'estado': serializer.toJson<String>(estado),
      'mensaje': serializer.toJson<String?>(mensaje),
      'requiereConfirmacion': serializer.toJson<bool>(requiereConfirmacion),
    };
  }

  Envio copyWith({
    int? orden,
    String? uuid,
    String? servidor,
    int? usuarioId,
    int? organizacionId,
    String? tipo,
    String? datos,
    String? registradoEn,
    String? estado,
    Value<String?> mensaje = const Value.absent(),
    bool? requiereConfirmacion,
  }) => Envio(
    orden: orden ?? this.orden,
    uuid: uuid ?? this.uuid,
    servidor: servidor ?? this.servidor,
    usuarioId: usuarioId ?? this.usuarioId,
    organizacionId: organizacionId ?? this.organizacionId,
    tipo: tipo ?? this.tipo,
    datos: datos ?? this.datos,
    registradoEn: registradoEn ?? this.registradoEn,
    estado: estado ?? this.estado,
    mensaje: mensaje.present ? mensaje.value : this.mensaje,
    requiereConfirmacion: requiereConfirmacion ?? this.requiereConfirmacion,
  );
  Envio copyWithCompanion(EnviosCompanion data) {
    return Envio(
      orden: data.orden.present ? data.orden.value : this.orden,
      uuid: data.uuid.present ? data.uuid.value : this.uuid,
      servidor: data.servidor.present ? data.servidor.value : this.servidor,
      usuarioId: data.usuarioId.present ? data.usuarioId.value : this.usuarioId,
      organizacionId: data.organizacionId.present
          ? data.organizacionId.value
          : this.organizacionId,
      tipo: data.tipo.present ? data.tipo.value : this.tipo,
      datos: data.datos.present ? data.datos.value : this.datos,
      registradoEn: data.registradoEn.present
          ? data.registradoEn.value
          : this.registradoEn,
      estado: data.estado.present ? data.estado.value : this.estado,
      mensaje: data.mensaje.present ? data.mensaje.value : this.mensaje,
      requiereConfirmacion: data.requiereConfirmacion.present
          ? data.requiereConfirmacion.value
          : this.requiereConfirmacion,
    );
  }

  @override
  String toString() {
    return (StringBuffer('Envio(')
          ..write('orden: $orden, ')
          ..write('uuid: $uuid, ')
          ..write('servidor: $servidor, ')
          ..write('usuarioId: $usuarioId, ')
          ..write('organizacionId: $organizacionId, ')
          ..write('tipo: $tipo, ')
          ..write('datos: $datos, ')
          ..write('registradoEn: $registradoEn, ')
          ..write('estado: $estado, ')
          ..write('mensaje: $mensaje, ')
          ..write('requiereConfirmacion: $requiereConfirmacion')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    orden,
    uuid,
    servidor,
    usuarioId,
    organizacionId,
    tipo,
    datos,
    registradoEn,
    estado,
    mensaje,
    requiereConfirmacion,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is Envio &&
          other.orden == this.orden &&
          other.uuid == this.uuid &&
          other.servidor == this.servidor &&
          other.usuarioId == this.usuarioId &&
          other.organizacionId == this.organizacionId &&
          other.tipo == this.tipo &&
          other.datos == this.datos &&
          other.registradoEn == this.registradoEn &&
          other.estado == this.estado &&
          other.mensaje == this.mensaje &&
          other.requiereConfirmacion == this.requiereConfirmacion);
}

class EnviosCompanion extends UpdateCompanion<Envio> {
  final Value<int> orden;
  final Value<String> uuid;
  final Value<String> servidor;
  final Value<int> usuarioId;
  final Value<int> organizacionId;
  final Value<String> tipo;
  final Value<String> datos;
  final Value<String> registradoEn;
  final Value<String> estado;
  final Value<String?> mensaje;
  final Value<bool> requiereConfirmacion;
  const EnviosCompanion({
    this.orden = const Value.absent(),
    this.uuid = const Value.absent(),
    this.servidor = const Value.absent(),
    this.usuarioId = const Value.absent(),
    this.organizacionId = const Value.absent(),
    this.tipo = const Value.absent(),
    this.datos = const Value.absent(),
    this.registradoEn = const Value.absent(),
    this.estado = const Value.absent(),
    this.mensaje = const Value.absent(),
    this.requiereConfirmacion = const Value.absent(),
  });
  EnviosCompanion.insert({
    this.orden = const Value.absent(),
    required String uuid,
    required String servidor,
    required int usuarioId,
    required int organizacionId,
    required String tipo,
    required String datos,
    required String registradoEn,
    this.estado = const Value.absent(),
    this.mensaje = const Value.absent(),
    this.requiereConfirmacion = const Value.absent(),
  }) : uuid = Value(uuid),
       servidor = Value(servidor),
       usuarioId = Value(usuarioId),
       organizacionId = Value(organizacionId),
       tipo = Value(tipo),
       datos = Value(datos),
       registradoEn = Value(registradoEn);
  static Insertable<Envio> custom({
    Expression<int>? orden,
    Expression<String>? uuid,
    Expression<String>? servidor,
    Expression<int>? usuarioId,
    Expression<int>? organizacionId,
    Expression<String>? tipo,
    Expression<String>? datos,
    Expression<String>? registradoEn,
    Expression<String>? estado,
    Expression<String>? mensaje,
    Expression<bool>? requiereConfirmacion,
  }) {
    return RawValuesInsertable({
      if (orden != null) 'orden': orden,
      if (uuid != null) 'uuid': uuid,
      if (servidor != null) 'servidor': servidor,
      if (usuarioId != null) 'usuario_id': usuarioId,
      if (organizacionId != null) 'organizacion_id': organizacionId,
      if (tipo != null) 'tipo': tipo,
      if (datos != null) 'datos': datos,
      if (registradoEn != null) 'registrado_en': registradoEn,
      if (estado != null) 'estado': estado,
      if (mensaje != null) 'mensaje': mensaje,
      if (requiereConfirmacion != null)
        'requiere_confirmacion': requiereConfirmacion,
    });
  }

  EnviosCompanion copyWith({
    Value<int>? orden,
    Value<String>? uuid,
    Value<String>? servidor,
    Value<int>? usuarioId,
    Value<int>? organizacionId,
    Value<String>? tipo,
    Value<String>? datos,
    Value<String>? registradoEn,
    Value<String>? estado,
    Value<String?>? mensaje,
    Value<bool>? requiereConfirmacion,
  }) {
    return EnviosCompanion(
      orden: orden ?? this.orden,
      uuid: uuid ?? this.uuid,
      servidor: servidor ?? this.servidor,
      usuarioId: usuarioId ?? this.usuarioId,
      organizacionId: organizacionId ?? this.organizacionId,
      tipo: tipo ?? this.tipo,
      datos: datos ?? this.datos,
      registradoEn: registradoEn ?? this.registradoEn,
      estado: estado ?? this.estado,
      mensaje: mensaje ?? this.mensaje,
      requiereConfirmacion: requiereConfirmacion ?? this.requiereConfirmacion,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (orden.present) {
      map['orden'] = Variable<int>(orden.value);
    }
    if (uuid.present) {
      map['uuid'] = Variable<String>(uuid.value);
    }
    if (servidor.present) {
      map['servidor'] = Variable<String>(servidor.value);
    }
    if (usuarioId.present) {
      map['usuario_id'] = Variable<int>(usuarioId.value);
    }
    if (organizacionId.present) {
      map['organizacion_id'] = Variable<int>(organizacionId.value);
    }
    if (tipo.present) {
      map['tipo'] = Variable<String>(tipo.value);
    }
    if (datos.present) {
      map['datos'] = Variable<String>(datos.value);
    }
    if (registradoEn.present) {
      map['registrado_en'] = Variable<String>(registradoEn.value);
    }
    if (estado.present) {
      map['estado'] = Variable<String>(estado.value);
    }
    if (mensaje.present) {
      map['mensaje'] = Variable<String>(mensaje.value);
    }
    if (requiereConfirmacion.present) {
      map['requiere_confirmacion'] = Variable<bool>(requiereConfirmacion.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('EnviosCompanion(')
          ..write('orden: $orden, ')
          ..write('uuid: $uuid, ')
          ..write('servidor: $servidor, ')
          ..write('usuarioId: $usuarioId, ')
          ..write('organizacionId: $organizacionId, ')
          ..write('tipo: $tipo, ')
          ..write('datos: $datos, ')
          ..write('registradoEn: $registradoEn, ')
          ..write('estado: $estado, ')
          ..write('mensaje: $mensaje, ')
          ..write('requiereConfirmacion: $requiereConfirmacion')
          ..write(')'))
        .toString();
  }
}

abstract class _$BaseLocal extends GeneratedDatabase {
  _$BaseLocal(QueryExecutor e) : super(e);
  $BaseLocalManager get managers => $BaseLocalManager(this);
  late final $GuardadosTable guardados = $GuardadosTable(this);
  late final $EnviosTable envios = $EnviosTable(this);
  @override
  Iterable<TableInfo<Table, Object?>> get allTables =>
      allSchemaEntities.whereType<TableInfo<Table, Object?>>();
  @override
  List<DatabaseSchemaEntity> get allSchemaEntities => [guardados, envios];
}

typedef $$GuardadosTableCreateCompanionBuilder = GuardadosCompanion Function({
  required String clave,
  required String valor,
  Value<DateTime> actualizado,
  Value<int> rowid,
});
typedef $$GuardadosTableUpdateCompanionBuilder = GuardadosCompanion Function({
  Value<String> clave,
  Value<String> valor,
  Value<DateTime> actualizado,
  Value<int> rowid,
});

class $$GuardadosTableFilterComposer
    extends Composer<_$BaseLocal, $GuardadosTable> {
  $$GuardadosTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get clave => $composableBuilder(
    column: $table.clave,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get valor => $composableBuilder(
    column: $table.valor,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<DateTime> get actualizado => $composableBuilder(
    column: $table.actualizado,
    builder: (column) => ColumnFilters(column),
  );
}

class $$GuardadosTableOrderingComposer
    extends Composer<_$BaseLocal, $GuardadosTable> {
  $$GuardadosTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get clave => $composableBuilder(
    column: $table.clave,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get valor => $composableBuilder(
    column: $table.valor,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<DateTime> get actualizado => $composableBuilder(
    column: $table.actualizado,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$GuardadosTableAnnotationComposer
    extends Composer<_$BaseLocal, $GuardadosTable> {
  $$GuardadosTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get clave =>
      $composableBuilder(column: $table.clave, builder: (column) => column);

  GeneratedColumn<String> get valor =>
      $composableBuilder(column: $table.valor, builder: (column) => column);

  GeneratedColumn<DateTime> get actualizado => $composableBuilder(
    column: $table.actualizado,
    builder: (column) => column,
  );
}

class $$GuardadosTableTableManager
    extends
        RootTableManager<
          _$BaseLocal,
          $GuardadosTable,
          Guardado,
          $$GuardadosTableFilterComposer,
          $$GuardadosTableOrderingComposer,
          $$GuardadosTableAnnotationComposer,
          $$GuardadosTableCreateCompanionBuilder,
          $$GuardadosTableUpdateCompanionBuilder,
          (Guardado, BaseReferences<_$BaseLocal, $GuardadosTable, Guardado>),
          Guardado,
          PrefetchHooks Function()
        > {
  $$GuardadosTableTableManager(_$BaseLocal db, $GuardadosTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$GuardadosTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$GuardadosTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$GuardadosTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> clave = const Value.absent(),
                Value<String> valor = const Value.absent(),
                Value<DateTime> actualizado = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => GuardadosCompanion(
                clave: clave,
                valor: valor,
                actualizado: actualizado,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String clave,
                required String valor,
                Value<DateTime> actualizado = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => GuardadosCompanion.insert(
                clave: clave,
                valor: valor,
                actualizado: actualizado,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$GuardadosTable, Guardado>(table),
                  BaseReferences<_$BaseLocal, $GuardadosTable, Guardado>(
                    db,
                    table,
                    e,
                  ),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$GuardadosTableProcessedTableManager =
    ProcessedTableManager<
      _$BaseLocal,
      $GuardadosTable,
      Guardado,
      $$GuardadosTableFilterComposer,
      $$GuardadosTableOrderingComposer,
      $$GuardadosTableAnnotationComposer,
      $$GuardadosTableCreateCompanionBuilder,
      $$GuardadosTableUpdateCompanionBuilder,
      (Guardado, BaseReferences<_$BaseLocal, $GuardadosTable, Guardado>),
      Guardado,
      PrefetchHooks Function()
    >;
typedef $$EnviosTableCreateCompanionBuilder = EnviosCompanion Function({
  Value<int> orden,
  required String uuid,
  required String servidor,
  required int usuarioId,
  required int organizacionId,
  required String tipo,
  required String datos,
  required String registradoEn,
  Value<String> estado,
  Value<String?> mensaje,
  Value<bool> requiereConfirmacion,
});
typedef $$EnviosTableUpdateCompanionBuilder = EnviosCompanion Function({
  Value<int> orden,
  Value<String> uuid,
  Value<String> servidor,
  Value<int> usuarioId,
  Value<int> organizacionId,
  Value<String> tipo,
  Value<String> datos,
  Value<String> registradoEn,
  Value<String> estado,
  Value<String?> mensaje,
  Value<bool> requiereConfirmacion,
});

class $$EnviosTableFilterComposer extends Composer<_$BaseLocal, $EnviosTable> {
  $$EnviosTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<int> get orden => $composableBuilder(
    column: $table.orden,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get uuid => $composableBuilder(
    column: $table.uuid,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get servidor => $composableBuilder(
    column: $table.servidor,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get usuarioId => $composableBuilder(
    column: $table.usuarioId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get organizacionId => $composableBuilder(
    column: $table.organizacionId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get tipo => $composableBuilder(
    column: $table.tipo,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get datos => $composableBuilder(
    column: $table.datos,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get registradoEn => $composableBuilder(
    column: $table.registradoEn,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get estado => $composableBuilder(
    column: $table.estado,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get mensaje => $composableBuilder(
    column: $table.mensaje,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<bool> get requiereConfirmacion => $composableBuilder(
    column: $table.requiereConfirmacion,
    builder: (column) => ColumnFilters(column),
  );
}

class $$EnviosTableOrderingComposer
    extends Composer<_$BaseLocal, $EnviosTable> {
  $$EnviosTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<int> get orden => $composableBuilder(
    column: $table.orden,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get uuid => $composableBuilder(
    column: $table.uuid,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get servidor => $composableBuilder(
    column: $table.servidor,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get usuarioId => $composableBuilder(
    column: $table.usuarioId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get organizacionId => $composableBuilder(
    column: $table.organizacionId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get tipo => $composableBuilder(
    column: $table.tipo,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get datos => $composableBuilder(
    column: $table.datos,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get registradoEn => $composableBuilder(
    column: $table.registradoEn,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get estado => $composableBuilder(
    column: $table.estado,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get mensaje => $composableBuilder(
    column: $table.mensaje,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get requiereConfirmacion => $composableBuilder(
    column: $table.requiereConfirmacion,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$EnviosTableAnnotationComposer
    extends Composer<_$BaseLocal, $EnviosTable> {
  $$EnviosTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<int> get orden =>
      $composableBuilder(column: $table.orden, builder: (column) => column);

  GeneratedColumn<String> get uuid =>
      $composableBuilder(column: $table.uuid, builder: (column) => column);

  GeneratedColumn<String> get servidor =>
      $composableBuilder(column: $table.servidor, builder: (column) => column);

  GeneratedColumn<int> get usuarioId =>
      $composableBuilder(column: $table.usuarioId, builder: (column) => column);

  GeneratedColumn<int> get organizacionId => $composableBuilder(
    column: $table.organizacionId,
    builder: (column) => column,
  );

  GeneratedColumn<String> get tipo =>
      $composableBuilder(column: $table.tipo, builder: (column) => column);

  GeneratedColumn<String> get datos =>
      $composableBuilder(column: $table.datos, builder: (column) => column);

  GeneratedColumn<String> get registradoEn => $composableBuilder(
    column: $table.registradoEn,
    builder: (column) => column,
  );

  GeneratedColumn<String> get estado =>
      $composableBuilder(column: $table.estado, builder: (column) => column);

  GeneratedColumn<String> get mensaje =>
      $composableBuilder(column: $table.mensaje, builder: (column) => column);

  GeneratedColumn<bool> get requiereConfirmacion => $composableBuilder(
    column: $table.requiereConfirmacion,
    builder: (column) => column,
  );
}

class $$EnviosTableTableManager
    extends
        RootTableManager<
          _$BaseLocal,
          $EnviosTable,
          Envio,
          $$EnviosTableFilterComposer,
          $$EnviosTableOrderingComposer,
          $$EnviosTableAnnotationComposer,
          $$EnviosTableCreateCompanionBuilder,
          $$EnviosTableUpdateCompanionBuilder,
          (Envio, BaseReferences<_$BaseLocal, $EnviosTable, Envio>),
          Envio,
          PrefetchHooks Function()
        > {
  $$EnviosTableTableManager(_$BaseLocal db, $EnviosTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$EnviosTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$EnviosTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$EnviosTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<int> orden = const Value.absent(),
                Value<String> uuid = const Value.absent(),
                Value<String> servidor = const Value.absent(),
                Value<int> usuarioId = const Value.absent(),
                Value<int> organizacionId = const Value.absent(),
                Value<String> tipo = const Value.absent(),
                Value<String> datos = const Value.absent(),
                Value<String> registradoEn = const Value.absent(),
                Value<String> estado = const Value.absent(),
                Value<String?> mensaje = const Value.absent(),
                Value<bool> requiereConfirmacion = const Value.absent(),
              }) => EnviosCompanion(
                orden: orden,
                uuid: uuid,
                servidor: servidor,
                usuarioId: usuarioId,
                organizacionId: organizacionId,
                tipo: tipo,
                datos: datos,
                registradoEn: registradoEn,
                estado: estado,
                mensaje: mensaje,
                requiereConfirmacion: requiereConfirmacion,
              ),
          createCompanionCallback:
              ({
                Value<int> orden = const Value.absent(),
                required String uuid,
                required String servidor,
                required int usuarioId,
                required int organizacionId,
                required String tipo,
                required String datos,
                required String registradoEn,
                Value<String> estado = const Value.absent(),
                Value<String?> mensaje = const Value.absent(),
                Value<bool> requiereConfirmacion = const Value.absent(),
              }) => EnviosCompanion.insert(
                orden: orden,
                uuid: uuid,
                servidor: servidor,
                usuarioId: usuarioId,
                organizacionId: organizacionId,
                tipo: tipo,
                datos: datos,
                registradoEn: registradoEn,
                estado: estado,
                mensaje: mensaje,
                requiereConfirmacion: requiereConfirmacion,
              ),
          withReferenceMapper: (p0) => p0
              .map(
                (e) => (
                  e.readTable<$EnviosTable, Envio>(table),
                  BaseReferences<_$BaseLocal, $EnviosTable, Envio>(
                    db,
                    table,
                    e,
                  ),
                ),
              )
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$EnviosTableProcessedTableManager =
    ProcessedTableManager<
      _$BaseLocal,
      $EnviosTable,
      Envio,
      $$EnviosTableFilterComposer,
      $$EnviosTableOrderingComposer,
      $$EnviosTableAnnotationComposer,
      $$EnviosTableCreateCompanionBuilder,
      $$EnviosTableUpdateCompanionBuilder,
      (Envio, BaseReferences<_$BaseLocal, $EnviosTable, Envio>),
      Envio,
      PrefetchHooks Function()
    >;

class $BaseLocalManager {
  final _$BaseLocal _db;
  $BaseLocalManager(this._db);
  $$GuardadosTableTableManager get guardados =>
      $$GuardadosTableTableManager(_db, _db.guardados);
  $$EnviosTableTableManager get envios =>
      $$EnviosTableTableManager(_db, _db.envios);
}
