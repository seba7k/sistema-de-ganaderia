import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/cliente_api.dart';
import '../estado/estado.dart';
import '../modelos/modelos.dart';

/// Ingreso: dirección del servidor, correo y contraseña. Si la persona trabaja
/// en más de una organización, pide elegir con cuál.
class PantallaIngreso extends ConsumerStatefulWidget {
  const PantallaIngreso({super.key});

  @override
  ConsumerState<PantallaIngreso> createState() => _PantallaIngresoState();
}

class _PantallaIngresoState extends ConsumerState<PantallaIngreso> {
  final _formulario = GlobalKey<FormState>();
  final _servidor = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();

  bool _ingresando = false;
  String? _error;
  List<OrganizacionElegible> _organizaciones = const [];

  @override
  void initState() {
    super.initState();
    ref.read(almacenSesionProvider).ultimoIngreso().then((ultimo) {
      if (!mounted) return;
      if (_servidor.text.isEmpty && ultimo.servidor != null) _servidor.text = ultimo.servidor!;
      if (_email.text.isEmpty && ultimo.email != null) _email.text = ultimo.email!;
    });
  }

  @override
  void dispose() {
    _servidor.dispose();
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _ingresar({int? organizacionId}) async {
    if (!_formulario.currentState!.validate()) return;
    final servidor = ClienteApi.normalizarServidor(_servidor.text)!;
    setState(() {
      _ingresando = true;
      _error = null;
    });
    try {
      final resultado = await ref.read(sesionProvider.notifier).ingresar(
            servidor: servidor,
            email: _email.text,
            password: _password.text,
            organizacionId: organizacionId,
          );
      if (!mounted) return;
      if (resultado is EligeOrganizacion) {
        setState(() => _organizaciones = resultado.organizaciones);
      }
    } on ErrorApi catch (e) {
      if (mounted) setState(() => _error = e.mensaje);
    } finally {
      if (mounted) setState(() => _ingresando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Form(
                key: _formulario,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Icon(Icons.agriculture, size: 56, color: tema.colorScheme.primary),
                    const SizedBox(height: 8),
                    Text('Terreno', textAlign: TextAlign.center, style: tema.textTheme.headlineMedium),
                    Text('Gestión ganadera', textAlign: TextAlign.center, style: tema.textTheme.bodyMedium),
                    const SizedBox(height: 32),
                    if (_organizaciones.isEmpty) ..._camposIngreso() else ..._eleccionOrganizacion(tema),
                    if (_error != null) ...[
                      const SizedBox(height: 16),
                      Text(_error!, key: const Key('error-ingreso'), style: TextStyle(color: tema.colorScheme.error)),
                    ],
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  List<Widget> _camposIngreso() => [
        TextFormField(
          key: const Key('campo-servidor'),
          controller: _servidor,
          keyboardType: TextInputType.url,
          autocorrect: false,
          decoration: const InputDecoration(
            labelText: 'Dirección del sistema',
            hintText: 'https://tucampo.cl/ganaderia',
            prefixIcon: Icon(Icons.dns_outlined),
          ),
          validator: (v) => ClienteApi.normalizarServidor(v ?? '') == null ? 'Escribí la dirección del sistema.' : null,
        ),
        const SizedBox(height: 12),
        TextFormField(
          key: const Key('campo-email'),
          controller: _email,
          keyboardType: TextInputType.emailAddress,
          autocorrect: false,
          autofillHints: const [AutofillHints.email],
          decoration: const InputDecoration(labelText: 'Correo', prefixIcon: Icon(Icons.mail_outline)),
          validator: (v) => (v ?? '').contains('@') ? null : 'Escribí tu correo.',
        ),
        const SizedBox(height: 12),
        TextFormField(
          key: const Key('campo-password'),
          controller: _password,
          obscureText: true,
          autofillHints: const [AutofillHints.password],
          decoration: const InputDecoration(labelText: 'Contraseña', prefixIcon: Icon(Icons.lock_outline)),
          validator: (v) => (v ?? '').isEmpty ? 'Escribí tu contraseña.' : null,
          onFieldSubmitted: (_) => _ingresando ? null : _ingresar(),
        ),
        const SizedBox(height: 24),
        FilledButton(
          key: const Key('boton-ingresar'),
          onPressed: _ingresando ? null : _ingresar,
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 14),
            child: Text(_ingresando ? 'Ingresando…' : 'Ingresar'),
          ),
        ),
      ];

  List<Widget> _eleccionOrganizacion(ThemeData tema) => [
        Text('Trabajás en más de una organización. ¿Con cuál vas a trabajar en este teléfono?', style: tema.textTheme.titleMedium),
        const SizedBox(height: 16),
        for (final org in _organizaciones)
          Card(
            child: ListTile(
              key: Key('organizacion-${org.id}'),
              leading: const Icon(Icons.home_work_outlined),
              title: Text(org.nombre),
              subtitle: Text(org.rol),
              trailing: const Icon(Icons.chevron_right),
              enabled: !_ingresando,
              onTap: () => _ingresar(organizacionId: org.id),
            ),
          ),
        TextButton(
          onPressed: _ingresando ? null : () => setState(() => _organizaciones = const []),
          child: const Text('Volver'),
        ),
      ];
}
