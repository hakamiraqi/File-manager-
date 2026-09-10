import 'package:flutter/material.dart';

import '../../../core/i18n/app_strings.dart';
import '../../../core/network/api_client.dart';
import '../data/auth_service.dart';
import 'reset_password_screen.dart';

/// Step 1 of the password-reset flow: request the email. The emailed link
/// opens the web reset page by default, but a user who'd rather finish
/// inside the app can paste the token from that email into
/// [ResetPasswordScreen] instead (linked from the success state below).
class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  final _authService = AuthService();
  final _formKey = GlobalKey<FormState>();
  final _emailController = TextEditingController();

  bool _isLoading = false;
  bool _sent = false;
  String? _error;

  @override
  void dispose() {
    _emailController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isLoading = true;
      _error = null;
    });

    try {
      await _authService.sendPasswordResetLink(_emailController.text.trim());
      setState(() => _sent = true);
    } catch (e) {
      setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.tr('auth.forgot_password'))),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: _sent ? _buildSentState() : _buildForm(),
          ),
        ),
      ),
    );
  }

  Widget _buildForm() {
    return Form(
      key: _formKey,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Icon(Icons.lock_reset_rounded, size: 56, color: Color(0xFF2AABEE)),
          const SizedBox(height: 16),
          Text(
            context.tr('auth.forgot_password_instructions'),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 24),
          TextFormField(
            controller: _emailController,
            keyboardType: TextInputType.emailAddress,
            decoration: InputDecoration(labelText: context.tr('auth.email'), border: const OutlineInputBorder()),
            validator: (value) => (value == null || value.isEmpty) ? context.tr('auth.email_required') : null,
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
                : Text(context.tr('auth.send_reset_link')),
          ),
        ],
      ),
    );
  }

  Widget _buildSentState() {
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Icon(Icons.mark_email_read_outlined, size: 56, color: Colors.green),
        const SizedBox(height: 16),
        Text(
          context.tr('auth.reset_link_sent', {'email': _emailController.text.trim()}),
          textAlign: TextAlign.center,
        ),
        const SizedBox(height: 24),
        FilledButton(
          onPressed: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => ResetPasswordScreen(email: _emailController.text.trim())),
          ),
          child: Text(context.tr('auth.have_reset_code')),
        ),
        const SizedBox(height: 8),
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(context.tr('auth.back_to_login')),
        ),
      ],
    );
  }
}
