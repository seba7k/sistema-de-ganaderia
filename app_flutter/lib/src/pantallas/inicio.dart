import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../estado/estado.dart';
import '../modelos/modelos.dart';
import '../tareas.dart';

String fechaHoraCorta(String f) {
  if (f.length < 16) return f;
  final p = f.substring(0, 10).split('-');
  return '${p[2]}/${p[1]} ${f.substring(11, 16)}';
}

/// Inicio: quién trabaja, en qué organización, el estado de los datos del
/// campo y las tareas que su rol permite.
class PantallaInicio extends ConsumerWidget {
  const PantallaInicio({super.key, required this.abrirEnvios, required this.cantidadEnvios});

  final VoidCallback abrirEnvios;
  final int cantidadEnvios;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sesion = ref.watch(sesionProvider).value!;
    final estado = ref.watch(catalogoProvider).value ?? const EstadoCatalogo();
    final catalogo = estado.catalogo;
    final permitidas = tareasPermitidas(sesion.operaciones);
    final tema = Theme.of(context);

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Terreno'),
            Text(sesion.usuario.organizacion, style: tema.textTheme.bodySmall?.copyWith(color: tema.colorScheme.onPrimary)),
          ],
        ),
        actions: [
          IconButton(
            tooltip: 'Envíos',
            onPressed: abrirEnvios,
            icon: Badge(
              isLabelVisible: cantidadEnvios > 0,
              label: Text('$cantidadEnvios'),
              child: const Icon(Icons.outbox_outlined),
            ),
          ),
          PopupMenuButton<String>(
            key: const Key('menu-inicio'),
            onSelected: (opcion) {
              if (opcion == 'salir') _confirmarSalida(context, ref);
            },
            itemBuilder: (_) => const [PopupMenuItem(value: 'salir', child: Text('Cerrar sesión'))],
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.read(catalogoProvider.notifier).actualizar(),
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text('Hola, ${sesion.usuario.nombre.split(' ').first}', style: tema.textTheme.headlineSmall),
            Text(sesion.usuario.rolNombre, style: tema.textTheme.bodyMedium),
            const SizedBox(height: 16),
            _TarjetaDatos(estado: estado, catalogo: catalogo),
            const SizedBox(height: 20),
            Text('¿Qué vas a registrar?', style: tema.textTheme.titleMedium),
            const SizedBox(height: 8),
            if (permitidas.isEmpty)
              const Padding(
                padding: EdgeInsets.all(16),
                child: Text('Tu rol no tiene tareas de terreno. Pedile acceso al administrador.'),
              ),
            GridView.count(
              crossAxisCount: MediaQuery.sizeOf(context).width > 600 ? 3 : 2,
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              mainAxisSpacing: 10,
              crossAxisSpacing: 10,
              childAspectRatio: 1.15,
              children: [
                for (final tarea in permitidas) _BotonTarea(tarea: tarea, habilitado: catalogo != null),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _confirmarSalida(BuildContext context, WidgetRef ref) async {
    final si = await showDialog<bool>(
      context: context,
      builder: (contexto) => AlertDialog(
        title: const Text('Cerrar sesión'),
        content: const Text('Se borra el catálogo del teléfono. Los envíos pendientes quedan guardados para esta cuenta. Para volver a trabajar hay que ingresar con señal.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(contexto, false), child: const Text('Cancelar')),
          FilledButton(key: const Key('confirmar-salida'), onPressed: () => Navigator.pop(contexto, true), child: const Text('Cerrar sesión')),
        ],
      ),
    );
    if (si == true) await ref.read(sesionProvider.notifier).cerrar();
  }
}

class _TarjetaDatos extends ConsumerWidget {
  const _TarjetaDatos({required this.estado, required this.catalogo});

  final EstadoCatalogo estado;
  final Catalogo? catalogo;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tema = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                _Cifra(valor: catalogo == null ? '—' : '${catalogo!.animales.length}', rotulo: 'animales'),
                _Cifra(valor: catalogo == null ? '—' : '${catalogo!.corrales.length}', rotulo: 'corrales'),
                _Cifra(valor: catalogo == null ? '—' : fechaHoraCorta(catalogo!.generadoEn), rotulo: 'datos del'),
              ],
            ),
            if (estado.aviso != null) ...[
              const SizedBox(height: 8),
              Text(
                catalogo == null ? estado.aviso! : '${estado.aviso} Seguís trabajando con los datos guardados.',
                key: const Key('aviso-catalogo'),
                style: TextStyle(color: tema.colorScheme.error),
              ),
            ],
            const SizedBox(height: 8),
            OutlinedButton.icon(
              key: const Key('boton-actualizar'),
              onPressed: estado.descargando ? null : () => ref.read(catalogoProvider.notifier).actualizar(),
              icon: estado.descargando
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.sync),
              label: Text(estado.descargando ? 'Descargando datos del campo…' : 'Actualizar datos del campo'),
            ),
          ],
        ),
      ),
    );
  }
}

class _Cifra extends StatelessWidget {
  const _Cifra({required this.valor, required this.rotulo});

  final String valor;
  final String rotulo;

  @override
  Widget build(BuildContext context) => Expanded(
        child: Column(
          children: [
            Text(valor, style: Theme.of(context).textTheme.titleLarge),
            Text(rotulo, style: Theme.of(context).textTheme.bodySmall),
          ],
        ),
      );
}

class _BotonTarea extends StatelessWidget {
  const _BotonTarea({required this.tarea, required this.habilitado});

  final Tarea tarea;
  final bool habilitado;

  @override
  Widget build(BuildContext context) => Card(
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          key: Key('tarea-${tarea.tipo}'),
          onTap: () => ScaffoldMessenger.of(context)
            ..hideCurrentSnackBar()
            ..showSnackBar(SnackBar(
              content: Text(habilitado
                  ? '«${tarea.titulo}» llega en la próxima versión de la app.'
                  : 'Faltan los datos del campo: conectate y actualizá.'),
            )),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Icon(tarea.icono, color: tarea.color, size: 32),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(tarea.titulo, style: Theme.of(context).textTheme.titleSmall),
                    Text(tarea.detalle, maxLines: 2, overflow: TextOverflow.ellipsis, style: Theme.of(context).textTheme.bodySmall),
                  ],
                ),
              ],
            ),
          ),
        ),
      );
}
