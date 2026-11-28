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

abstract class _$BaseLocal extends GeneratedDatabase {
  _$BaseLocal(QueryExecutor e) : super(e);
  $BaseLocalManager get managers => $BaseLocalManager(this);
  late final $GuardadosTable guardados = $GuardadosTable(this);
  @override
  Iterable<TableInfo<Table, Object?>> get allTables =>
      allSchemaEntities.whereType<TableInfo<Table, Object?>>();
  @override
  List<DatabaseSchemaEntity> get allSchemaEntities => [guardados];
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

class $BaseLocalManager {
  final _$BaseLocal _db;
  $BaseLocalManager(this._db);
  $$GuardadosTableTableManager get guardados =>
      $$GuardadosTableTableManager(_db, _db.guardados);
}
