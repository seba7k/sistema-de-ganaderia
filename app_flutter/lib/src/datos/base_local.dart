import 'package:drift/drift.dart';
import 'package:drift_flutter/drift_flutter.dart';

part 'base_local.g.dart';

/// Datos guardados como clave → texto (el catálogo, en JSON).
class Guardados extends Table {
  TextColumn get clave => text()();
  TextColumn get valor => text()();
  DateTimeColumn get actualizado => dateTime().withDefault(currentDateAndTime)();

  @override
  Set<Column> get primaryKey => {clave};
}

@DataClassName('Envio')
class Envios extends Table {
  IntColumn get orden => integer().autoIncrement()();
  TextColumn get uuid => text().unique()();
  TextColumn get servidor => text()();
  IntColumn get usuarioId => integer()();
  IntColumn get organizacionId => integer()();
  TextColumn get tipo => text()();
  TextColumn get datos => text()();
  TextColumn get registradoEn => text()();
  TextColumn get estado => text().withDefault(const Constant('PENDIENTE'))();
  TextColumn get mensaje => text().nullable()();
  BoolColumn get requiereConfirmacion => boolean().withDefault(const Constant(false))();
}

@DriftDatabase(tables: [Guardados, Envios])
class BaseLocal extends _$BaseLocal {
  BaseLocal([QueryExecutor? ejecutor]) : super(ejecutor ?? driftDatabase(name: 'terreno'));

  @override
  int get schemaVersion => 2;

  @override
  MigrationStrategy get migration => MigrationStrategy(
        onUpgrade: (migrador, desde, hasta) async {
          if (desde < 2) await migrador.createTable(envios);
        },
      );

  Future<String?> leer(String clave) async =>
      (await (select(guardados)..where((g) => g.clave.equals(clave))).getSingleOrNull())?.valor;

  Future<void> escribir(String clave, String valor) => into(guardados).insertOnConflictUpdate(
        GuardadosCompanion.insert(clave: clave, valor: valor, actualizado: Value(DateTime.now())),
      );

  Future<void> borrar(String clave) =>
      (delete(guardados)..where((g) => g.clave.equals(clave))).go();
}
