import 'package:flutter/material.dart';

import '../../../core/i18n/app_strings.dart';
import '../../../core/network/api_client.dart';
import '../data/auth_service.dart';

/// Step 2: the token from the emailed reset link (Laravel appends it as
/// `...?token=xxxx&email=...` to the web reset page URL) plus a new
/// password. [email] is pre-filled from ForgotPasswordScreen but stays
/// editable in case the user opens this screen directly.
class ResetPasswordScreen extends StatefulWidget {
  const ResetPasswordScreen({super.key, this.email = ''});

  final String email;

  @override
  State<ResetPasswordScreen> createState() => _ResetPasswordScreenState();
}

class _ResetPasswordScreenState extends State<ResetPasswordScreen> {
  final _authService = AuthService();
  final _formKey = GlobalKey<FormState>();
  late final _emailController = TextEditingController(text: widget.email);
  final _tokenController = TextEditingController();
  final _passwordController = TextEditingController();

  bool _isLoading = false;
  String? _error;

  @override
  void dispose() {
    _emailController.dispose();
    _tokenController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isLoading = true;
      _error = null;
    });

    try {
      await _authService.resetPassword(
        email: _emailController.text.trim(),
        token: _tokenController.text.trim(),
        password: _passwordController.text,
      );

      if (mounted) {
        Navigator.of(context).popUntil((route) => route.isFirst);
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(context.tr('auth.password_reset_success'))),
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
      appBar: AppBar(title: Text(context.tr('auth.reset_password_title'))),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  context.tr('auth.reset_password_instructions'),
                  style: const TextStyle(color: Colors.grey),
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _emailController,
                  keyboardType: TextInputType.emailAddress,
                  decoration: InputDecoration(labelText: context.tr('auth.email'), border: const OutlineInputBorder()),
                  validator: (value) => (value == null || value.isEmpty) ? context.tr('auth.email_required') : null,
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _tokenController,
                  decoration: InputDecoration(labelText: context.tr('auth.reset_token'), border: const OutlineInputBorder()),
                  validator: (value) => (value == null || value.isEmpty) ? context.tr('auth.reset_token_required') : null,
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _passwordController,
                  obscureText: true,
                  decoration: InputDecoration(labelText: context.tr('auth.new_password_min_length'), border: const OutlineInputBorder()),
                  validator: (value) =>
                      (value == null || value.length < 8) ? context.tr('auth.password_min_length_error') : null,
                ),
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  Text(_error!, style: const TextStyle(color: Colors.red)),
                ],
                const SizedBox(height: 24),
                FilledButton(
                  onPressed: _isLoading ? null : _submit,
                  child: _isLoading
                      ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                      : Text(context.tr('account.update_password')),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
