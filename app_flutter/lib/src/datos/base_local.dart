import 'package:drift/drift.dart';
import 'package:drift_flutter/drift_flutter.dart';

part 'base_local.g.dart';

/// Datos guardados como clave → texto (el catálogo, en JSON).
///
/// La cola de operaciones sin señal llega en la etapa 2, como tabla propia con
/// su migración (schemaVersion 2).
class Guardados extends Table {
  TextColumn get clave => text()();
  TextColumn get valor => text()();
  DateTimeColumn get actualizado => dateTime().withDefault(currentDateAndTime)();

  @override
  Set<Column> get primaryKey => {clave};
}

@DriftDatabase(tables: [Guardados])
class BaseLocal extends _$BaseLocal {
  BaseLocal([QueryExecutor? ejecutor]) : super(ejecutor ?? driftDatabase(name: 'terreno'));

  @override
  int get schemaVersion => 1;

  Future<String?> leer(String clave) async =>
      (await (select(guardados)..where((g) => g.clave.equals(clave))).getSingleOrNull())?.valor;

  Future<void> escribir(String clave, String valor) => into(guardados).insertOnConflictUpdate(
        GuardadosCompanion.insert(clave: clave, valor: valor, actualizado: Value(DateTime.now())),
      );

  Future<void> borrarTodo() => delete(guardados).go();
}
