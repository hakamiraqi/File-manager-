import 'package:flutter/material.dart';

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
          const SnackBar(content: Text('تم تحديث كلمة المرور، يمكنك تسجيل الدخول الآن.')),
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
      appBar: AppBar(title: const Text('إعادة تعيين كلمة المرور')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Text(
                  'أدخل الرمز الموجود في رابط البريد الإلكتروني (الجزء بعد "token=" في الرابط) مع كلمة مرورك الجديدة.',
                  style: TextStyle(color: Colors.grey),
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _emailController,
                  keyboardType: TextInputType.emailAddress,
                  decoration: const InputDecoration(labelText: 'البريد الإلكتروني', border: OutlineInputBorder()),
                  validator: (value) => (value == null || value.isEmpty) ? 'الرجاء إدخال البريد الإلكتروني' : null,
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _tokenController,
                  decoration: const InputDecoration(labelText: 'رمز الاستعادة', border: OutlineInputBorder()),
                  validator: (value) => (value == null || value.isEmpty) ? 'الرجاء إدخال رمز الاستعادة' : null,
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _passwordController,
                  obscureText: true,
                  decoration: const InputDecoration(labelText: 'كلمة المرور الجديدة (8 أحرف على الأقل)', border: OutlineInputBorder()),
                  validator: (value) =>
                      (value == null || value.length < 8) ? 'يجب أن تتكون كلمة المرور من 8 أحرف على الأقل' : null,
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
                      : const Text('تحديث كلمة المرور'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
