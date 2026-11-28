import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../estado/estado.dart';
import '../estado/sincronizacion.dart';
import '../modelos/modelos.dart';
import '../modelos/tareas_animal.dart';
import '../tareas.dart';

class PantallaTareaAnimal extends ConsumerStatefulWidget {
  const PantallaTareaAnimal({
    super.key,
    required this.tarea,
    required this.volver,
  });

  final Tarea tarea;
  final VoidCallback volver;

  @override
  ConsumerState<PantallaTareaAnimal> createState() =>
      _PantallaTareaAnimalState();
}

class _PantallaTareaAnimalState extends ConsumerState<PantallaTareaAnimal> {
  Animal? _animal;
  String _consulta = '';

  void _volverABuscar() => setState(() {
    _animal = null;
    _consulta = '';
  });

  @override
  Widget build(BuildContext context) {
    final catalogo = ref.watch(catalogoProvider).value?.catalogo;
    final animal = _animal;
    final volver = animal == null ? widget.volver : _volverABuscar;
    final soloHembras = {'PARTO', 'SECADO'}.contains(widget.tarea.tipo);
    final resultados = buscarAnimales(
      catalogo?.animales ?? [],
      _consulta,
      soloHembras: soloHembras,
    );
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (salio, _) {
        if (!salio) volver();
      },
      child: Scaffold(
        appBar: AppBar(
          leading: BackButton(onPressed: volver),
          title: Text(widget.tarea.titulo),
        ),
        body: animal != null && catalogo != null
            ? _FormularioAnimal(
                tarea: widget.tarea,
                animal: animal,
                gdpMaxima: catalogo.gdpMaxima,
                guardado: _volverABuscar,
              )
            : Column(
                children: [
                  Padding(
                    padding: const EdgeInsets.all(16),
                    child: TextField(
                      key: const Key('buscar-animal'),
                      onChanged: (valor) => setState(() => _consulta = valor),
                      textInputAction: TextInputAction.search,
                      autocorrect: false,
                      decoration: InputDecoration(
                        labelText: soloHembras
                            ? 'Buscar hembra'
                            : 'Buscar animal',
                        hintText: 'DIIO, EID o número interno',
                        prefixIcon: const Icon(Icons.search),
                      ),
                    ),
                  ),
                  if (_consulta.trim().isNotEmpty && resultados.isEmpty)
                    const Padding(
                      padding: EdgeInsets.all(16),
                      child: Text(
                        'No hay coincidencias en los datos guardados.',
                      ),
                    ),
                  Expanded(
                    child: ListView.builder(
                      keyboardDismissBehavior:
                          ScrollViewKeyboardDismissBehavior.onDrag,
                      itemCount: resultados.length,
                      itemBuilder: (context, indice) {
                        final candidato = resultados[indice];
                        return ListTile(
                          key: ValueKey('animal-${candidato.id}'),
                          title: Text(candidato.nombre),
                          subtitle: Text(
                            [
                              candidato.categoria,
                              if (candidato.eid != null &&
                                  candidato.eid != candidato.nombre)
                                'EID ${candidato.eid}',
                              if (candidato.interno != null &&
                                  candidato.interno != candidato.nombre)
                                'Interno ${candidato.interno}',
                            ].join(' · '),
                          ),
                          trailing: const Icon(Icons.chevron_right),
                          onTap: () {
                            FocusScope.of(context).unfocus();
                            setState(() => _animal = candidato);
                          },
                        );
                      },
                    ),
                  ),
                ],
              ),
      ),
    );
  }
}

class _FormularioAnimal extends ConsumerStatefulWidget {
  const _FormularioAnimal({
    required this.tarea,
    required this.animal,
    required this.gdpMaxima,
    required this.guardado,
  });

  final Tarea tarea;
  final Animal animal;
  final double gdpMaxima;
  final VoidCallback guardado;

  @override
  ConsumerState<_FormularioAnimal> createState() => _FormularioAnimalState();
}

class _FormularioAnimalState extends ConsumerState<_FormularioAnimal> {
  final _formulario = GlobalKey<FormState>();
  final _fecha = TextEditingController(
    text: fechaVisible(fechaIso(DateTime.now())),
  );
  final _peso = TextEditingController();
  final _texto = TextEditingController();
  String _motivo = 'CONTROL';
  String? _resultado;
  String? _sexo;
  String _dificultad = 'NORMAL';
  bool _debil = false;
  bool _guardando = false;

  @override
  void dispose() {
    _fecha.dispose();
    _peso.dispose();
    _texto.dispose();
    super.dispose();
  }

  double? get _kg => double.tryParse(_peso.text.trim().replaceAll(',', '.'));

  Widget _campoPeso(String etiqueta, double maximo) => TextFormField(
    key: const Key('peso-animal'),
    controller: _peso,
    keyboardType: const TextInputType.numberWithOptions(decimal: true),
    decoration: InputDecoration(labelText: etiqueta, suffixText: 'kg'),
    validator: (_) {
      final peso = _kg;
      if (peso == null || !peso.isFinite || peso < 10 || peso > maximo) {
        return 'Ingresá un peso entre 10 y ${maximo.toInt()} kg.';
      }
      return null;
    },
  );

  Widget _opciones(
    String etiqueta,
    Map<String, String> opciones,
    String? valor,
    ValueChanged<String?> cambiar, {
    String? error,
  }) => DropdownButtonFormField<String>(
    key: ValueKey(etiqueta),
    initialValue: valor,
    decoration: InputDecoration(labelText: etiqueta),
    items: [
      for (final opcion in opciones.entries)
        DropdownMenuItem(value: opcion.key, child: Text(opcion.value)),
    ],
    onChanged: cambiar,
    validator: (valor) => valor == null ? error : null,
  );

  @override
  Widget build(BuildContext context) {
    final animal = widget.animal;
    final tipo = widget.tarea.tipo;
    final sesion = ref.watch(sesionProvider).value;
    final permitido = sesion?.operaciones.contains(tipo) ?? false;
    return Form(
      key: _formulario,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(animal.nombre, style: Theme.of(context).textTheme.headlineSmall),
          Text(animal.categoria),
          if (tipo == 'PESADA' && animal.peso != null)
            Text('Último peso del catálogo: ${animal.peso} kg'),
          const SizedBox(height: 16),
          TextFormField(
            key: const Key('fecha-animal'),
            controller: _fecha,
            keyboardType: TextInputType.datetime,
            decoration: const InputDecoration(
              labelText: 'Fecha',
              hintText: 'DD/MM/AAAA',
            ),
            validator: (valor) => leerFecha(valor ?? '', DateTime.now()) == null
                ? 'Ingresá una fecha válida, de hoy o anterior.'
                : null,
          ),
          const SizedBox(height: 16),
          if (tipo == 'PESADA') ...[
            _campoPeso('Peso', 1500),
            const SizedBox(height: 16),
            _opciones(
              'Motivo',
              const {
                'CONTROL': 'Control',
                'NACIMIENTO': 'Nacimiento',
                'DESTETE': 'Destete',
                'ENCASTE': 'Encaste',
                'PREVENTA': 'Preventa',
                'SALIDA': 'Salida',
              },
              _motivo,
              (v) => setState(() => _motivo = v!),
            ),
          ],
          if (tipo == 'NOTA')
            TextFormField(
              key: const Key('texto-nota'),
              controller: _texto,
              decoration: const InputDecoration(labelText: 'Nota'),
              maxLength: 500,
              maxLines: 4,
              validator: (valor) {
                final texto = (valor ?? '').trim();
                if (texto.isEmpty) return 'Escribí qué le pasó al animal.';
                if (texto.runes.length > 500) {
                  return 'La nota admite hasta 500 caracteres.';
                }
                return null;
              },
            ),
          if (tipo == 'SECADO')
            const Text('Registrar el fin de la lactancia para esta hembra.'),
          if (tipo == 'PARTO') ...[
            _opciones(
              'Resultado',
              const {
                'VIVO': 'Nacimiento vivo',
                'MUERTO': 'Nacimiento muerto',
                'ABORTO': 'Aborto',
              },
              _resultado,
              (v) => setState(() => _resultado = v),
              error: 'Elegí cómo terminó el parto.',
            ),
            if (_resultado == 'VIVO') ...[
              const SizedBox(height: 16),
              _opciones(
                'Sexo de la cría',
                const {'HEMBRA': 'Hembra', 'MACHO': 'Macho'},
                _sexo,
                (v) => setState(() => _sexo = v),
                error: 'Elegí el sexo de la cría.',
              ),
              const SizedBox(height: 16),
              _campoPeso('Peso al nacer', 90),
              CheckboxListTile(
                title: const Text('Cría débil'),
                value: _debil,
                onChanged: (valor) => setState(() => _debil = valor ?? false),
              ),
            ],
            const SizedBox(height: 16),
            _opciones(
              'Dificultad',
              const {
                'NORMAL': 'Normal',
                'DISTOCIA': 'Distocia',
                'CESAREA': 'Cesárea',
              },
              _dificultad,
              (v) => setState(() => _dificultad = v!),
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _texto,
              decoration: const InputDecoration(
                labelText: 'Observaciones (opcional)',
              ),
              maxLines: 3,
            ),
          ],
          const SizedBox(height: 20),
          if (!permitido)
            const Text('Tu rol ya no permite registrar esta tarea.'),
          FilledButton(
            key: const Key('guardar-animal'),
            onPressed: _guardando || !permitido ? null : _guardar,
            child: Text(_guardando ? 'Guardando…' : 'Guardar en el teléfono'),
          ),
        ],
      ),
    );
  }

  Future<void> _guardar() async {
    if (_guardando || !_formulario.currentState!.validate()) return;
    final sesion = ref.read(sesionProvider).value;
    final tipo = widget.tarea.tipo;
    if (sesion == null || !sesion.operaciones.contains(tipo)) return;
    final fecha = leerFecha(_fecha.text, DateTime.now())!;
    final peso = _kg;
    final animal = widget.animal;
    final datos = <String, dynamic>{
      (tipo == 'PARTO' ? 'vaca_id' : 'animal_id'): animal.id,
      'fecha': fecha,
      if (tipo == 'PESADA') ...{'peso_kg': peso, 'motivo': _motivo},
      if (tipo == 'NOTA') 'texto': _texto.text.trim(),
      if (tipo == 'PARTO') ...{
        'resultado': _resultado,
        'dificultad': _dificultad,
        if (_texto.text.trim().isNotEmpty) 'observaciones': _texto.text.trim(),
        if (_resultado == 'VIVO') ...{
          'sexo': _sexo,
          'peso_nacimiento': peso,
          'ternero_debil': _debil,
        },
      },
    };
    setState(() => _guardando = true);
    try {
      if (tipo == 'PESADA') {
        final pendientes = ref.read(enviosProvider).value ?? [];
        final aviso = avisoVariacionPeso(
          animal,
          pendientes,
          fecha,
          peso!,
          widget.gdpMaxima,
        );
        if (aviso != null) {
          final confirmado = await showDialog<bool>(
            context: context,
            builder: (contexto) => AlertDialog(
              title: const Text('¿Confirmás el peso?'),
              content: Text(
                '$aviso Revisá el peso y la identificación del animal.',
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(contexto, false),
                  child: const Text('Revisar'),
                ),
                FilledButton(
                  onPressed: () => Navigator.pop(contexto, true),
                  child: const Text('Confirmar peso'),
                ),
              ],
            ),
          );
          if (confirmado != true || !mounted) return;
          datos['confirmar'] = true;
        }
      }
      final actual = ref.read(sesionProvider).value;
      if (actual == null ||
          actual.token != sesion.token ||
          !actual.operaciones.contains(tipo)) {
        return;
      }
      await ref.read(almacenEnviosProvider).agregar(sesion, tipo, datos);
      if (!mounted) return;
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(
          const SnackBar(content: Text('Registro guardado en el teléfono.')),
        );
      widget.guardado();
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('No se pudo guardar el registro. Intentá de nuevo.'),
        ),
      );
    } finally {
      if (mounted) setState(() => _guardando = false);
    }
  }
}
