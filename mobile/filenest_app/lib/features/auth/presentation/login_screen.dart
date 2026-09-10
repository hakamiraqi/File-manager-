import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/i18n/app_strings.dart';
import '../../../core/network/api_client.dart';
import '../../server_settings/presentation/server_settings_screen.dart';
import '../state/auth_provider.dart';
import 'forgot_password_screen.dart';
import 'register_screen.dart';
import 'two_factor_challenge_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _isLoading = false;
  String? _error;

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isLoading = true;
      _error = null;
    });

    try {
      final result = await context.read<AuthProvider>().login(
            _emailController.text.trim(),
            _passwordController.text,
          );

      if (result.twoFactorRequired && mounted) {
        Navigator.of(context).push(
          MaterialPageRoute(builder: (_) => TwoFactorChallengeScreen(twoFactorToken: result.twoFactorToken!)),
        );
      }
    } catch (e) {
      setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        elevation: 0,
        backgroundColor: Colors.transparent,
        actions: [
          IconButton(
            icon: const Icon(Icons.dns_rounded),
            tooltip: context.tr('nav.server_settings'),
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const ServerSettingsScreen()),
            ),
          ),
        ],
      ),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Form(
              key: _formKey,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Icon(Icons.folder_copy_rounded, size: 64, color: Color(0xFF2563EB)),
                  const SizedBox(height: 12),
                  Text('FileNest', textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineMedium),
                  const SizedBox(height: 32),
                  TextFormField(
                    controller: _emailController,
                    keyboardType: TextInputType.emailAddress,
                    decoration: InputDecoration(labelText: context.tr('auth.email'), border: const OutlineInputBorder()),
                    validator: (value) => (value == null || value.isEmpty) ? context.tr('auth.email_required') : null,
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _passwordController,
                    obscureText: true,
                    decoration: InputDecoration(labelText: context.tr('auth.password'), border: const OutlineInputBorder()),
                    validator: (value) => (value == null || value.isEmpty) ? context.tr('auth.password_required') : null,
                  ),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton(
                      onPressed: () => Navigator.of(context).push(
                        MaterialPageRoute(builder: (_) => const ForgotPasswordScreen()),
                      ),
                      child: Text(context.tr('auth.forgot_password')),
                    ),
                  ),
                  if (_error != null) ...[
                    const SizedBox(height: 12),
                    Text(_error!, style: const TextStyle(color: Colors.red)),
                  ],
                  const SizedBox(height: 12),
                  FilledButton(
                    onPressed: _isLoading ? null : _submit,
                    child: _isLoading
                        ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                        : Text(context.tr('auth.login')),
                  ),
                  const SizedBox(height: 12),
                  TextButton(
                    onPressed: () => Navigator.of(context).push(
                      MaterialPageRoute(builder: (_) => const RegisterScreen()),
                    ),
                    child: Text(context.tr('auth.no_account_create')),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
