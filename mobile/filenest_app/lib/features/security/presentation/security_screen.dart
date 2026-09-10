import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../../core/network/api_client.dart';
import '../../auth/state/auth_provider.dart';
import '../data/two_factor_service.dart';

class SecurityScreen extends StatefulWidget {
  const SecurityScreen({super.key});

  @override
  State<SecurityScreen> createState() => _SecurityScreenState();
}

class _SecurityScreenState extends State<SecurityScreen> {
  final _twoFactorService = TwoFactorService();
  final _codeController = TextEditingController();

  TwoFactorSetup? _pendingSetup;
  List<String>? _recoveryCodes;
  bool _isLoading = false;
  String? _error;

  /// The account's current password is required by every 2FA-management
  /// endpoint (see TwoFactorService) — this is the app's equivalent of the
  /// web dashboard's "confirm password" gate. Returns null if the user
  /// cancels the dialog.
  Future<String?> _promptPassword(String title) {
    final controller = TextEditingController();
    return showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(title),
        content: TextField(
          controller: controller,
          obscureText: true,
          autofocus: true,
          decoration: const InputDecoration(labelText: 'كلمة المرور الحالية'),
          onSubmitted: (value) => Navigator.of(context).pop(value),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.of(context).pop(controller.text), child: const Text('متابعة')),
        ],
      ),
    );
  }

  Future<void> _enable() async {
    final password = await _promptPassword('تفعيل المصادقة الثنائية');
    if (password == null || password.isEmpty) return;

    setState(() {
      _isLoading = true;
      _error = null;
    });
    try {
      _pendingSetup = await _twoFactorService.enable(password);
    } catch (e) {
      setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _confirm() async {
    final password = await _promptPassword('تأكيد التفعيل');
    if (password == null || password.isEmpty) return;

    setState(() {
      _isLoading = true;
      _error = null;
    });
    try {
      final codes = await _twoFactorService.confirm(
        code: _codeController.text.trim(),
        password: password,
      );
      setState(() {
        _recoveryCodes = codes;
        _pendingSetup = null;
      });
      await context.read<AuthProvider>().refreshProfile();
    } catch (e) {
      setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _regenerateRecoveryCodes() async {
    final password = await _promptPassword('توليد رموز استرداد جديدة');
    if (password == null || password.isEmpty) return;

    setState(() => _isLoading = true);
    try {
      final codes = await _twoFactorService.regenerateRecoveryCodes(password);
      setState(() => _recoveryCodes = codes);
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _disable() async {
    final password = await _promptPassword('تعطيل المصادقة الثنائية');
    if (password == null || password.isEmpty) return;

    setState(() => _isLoading = true);
    try {
      await _twoFactorService.disable(password);
      await context.read<AuthProvider>().refreshProfile();
      setState(() => _recoveryCodes = null);
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user;
    final enabled = user?.twoFactorEnabled ?? false;

    return Scaffold(
      appBar: AppBar(title: const Text('الأمان')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'المصادقة الثنائية (2FA)',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 8),
              const Text('طبقة حماية إضافية: بعد كلمة المرور، سيُطلب منك أيضاً رمز من تطبيق مصادقة.'),
              const SizedBox(height: 16),

              if (!enabled && _pendingSetup == null)
                FilledButton(
                  onPressed: _isLoading ? null : _enable,
                  child: const Text('تفعيل المصادقة الثنائية'),
                ),

              if (_pendingSetup != null) ...[
                const Text('امسح رمز QR بتطبيق المصادقة، أو أدخل المفتاح يدوياً:'),
                const SizedBox(height: 12),
                Center(
                  child: QrImageView(data: _pendingSetup!.qrCodeUri, size: 200),
                ),
                const SizedBox(height: 8),
                SelectableText(_pendingSetup!.secret, textAlign: TextAlign.center),
                const SizedBox(height: 16),
                TextField(
                  controller: _codeController,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(labelText: 'رمز التحقق', border: OutlineInputBorder()),
                ),
                const SizedBox(height: 12),
                FilledButton(onPressed: _isLoading ? null : _confirm, child: const Text('تأكيد')),
              ],

              if (enabled) ...[
                const Text('المصادقة الثنائية مفعّلة.', style: TextStyle(color: Colors.green, fontWeight: FontWeight.w600)),
                const SizedBox(height: 12),
                OutlinedButton(
                  onPressed: _isLoading ? null : _regenerateRecoveryCodes,
                  child: const Text('توليد رموز استرداد جديدة'),
                ),
                const SizedBox(height: 8),
                OutlinedButton(
                  onPressed: _isLoading ? null : _disable,
                  style: OutlinedButton.styleFrom(foregroundColor: Colors.red),
                  child: const Text('تعطيل'),
                ),
              ],

              if (_recoveryCodes != null) ...[
                const SizedBox(height: 16),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: Colors.amber.shade50,
                    border: Border.all(color: Colors.amber.shade200),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'احفظ رموز الاسترداد هذه — لن تظهر مجدداً:',
                        style: TextStyle(fontWeight: FontWeight.w600),
                      ),
                      const SizedBox(height: 8),
                      ..._recoveryCodes!.map((c) => SelectableText(c)),
                    ],
                  ),
                ),
              ],

              if (_error != null) ...[
                const SizedBox(height: 12),
                Text(_error!, style: const TextStyle(color: Colors.red)),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
