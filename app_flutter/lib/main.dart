import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'src/estado/estado.dart';
import 'src/pantallas/ingreso.dart';
import 'src/pantallas/inicio.dart';

void main() {
  runApp(const ProviderScope(child: AppTerreno()));
}

/// App de terreno de Gestión ganadera.
class AppTerreno extends ConsumerWidget {
  const AppTerreno({super.key});

  static const verde = Color(0xFF183A26);

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sesion = ref.watch(sesionProvider);
    return MaterialApp(
      title: 'Terreno',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: verde, primary: verde),
        appBarTheme: const AppBarTheme(backgroundColor: verde, foregroundColor: Colors.white),
        inputDecorationTheme: const InputDecorationTheme(border: OutlineInputBorder()),
      ),
      home: sesion.when(
        data: (s) => s == null ? const PantallaIngreso() : const PantallaInicio(),
        loading: () => const Scaffold(body: Center(child: CircularProgressIndicator())),
        error: (_, _) => const PantallaIngreso(),
      ),
    );
  }
}
