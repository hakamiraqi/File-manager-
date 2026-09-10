import 'package:flutter/material.dart';

import '../../../core/network/api_client.dart';
import '../data/admin_repository.dart';

/// Mobile counterpart of Admin/Mail.vue — SMTP settings, event
/// notification toggles, and a send-test-email action.
class MailScreen extends StatefulWidget {
  const MailScreen({super.key});

  @override
  State<MailScreen> createState() => _MailScreenState();
}

class _MailScreenState extends State<MailScreen> {
  final _repository = AdminRepository();

  bool _loading = true;
  bool _saving = false;
  bool _sendingTest = false;
  String? _error;

  final _hostController = TextEditingController();
  final _portController = TextEditingController();
  final _usernameController = TextEditingController();
  final _passwordController = TextEditingController();
  final _fromAddressController = TextEditingController();
  final _fromNameController = TextEditingController();
  final _testEmailController = TextEditingController();

  String _encryption = 'tls';
  bool _notifyUpload = false;
  bool _notifyDownload = false;
  bool _notifyLogin = false;
  bool _notifyNewFolder = false;
  bool _notifyRegistration = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _hostController.dispose();
    _portController.dispose();
    _usernameController.dispose();
    _passwordController.dispose();
    _fromAddressController.dispose();
    _fromNameController.dispose();
    _testEmailController.dispose();
    super.dispose();
  }

  bool _isTrue(dynamic value) => value == '1' || value == true;

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final settings = await _repository.mailSettings();
      setState(() {
        _hostController.text = settings['mail_host'] as String? ?? '';
        _portController.text = '${settings['mail_port'] ?? ''}';
        _usernameController.text = settings['mail_username'] as String? ?? '';
        _fromAddressController.text = settings['mail_from_address'] as String? ?? '';
        _fromNameController.text = settings['mail_from_name'] as String? ?? '';
        _encryption = settings['mail_encryption'] as String? ?? 'tls';
        _notifyUpload = _isTrue(settings['notify_upload']);
        _notifyDownload = _isTrue(settings['notify_download']);
        _notifyLogin = _isTrue(settings['notify_login']);
        _notifyNewFolder = _isTrue(settings['notify_newfolder']);
        _notifyRegistration = _isTrue(settings['notify_registration']);
      });
    } catch (e) {
      if (mounted) setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _submit() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await _repository.updateMailSettings({
        'mail_host': _hostController.text,
        'mail_port': _portController.text,
        'mail_username': _usernameController.text,
        if (_passwordController.text.isNotEmpty) 'mail_password': _passwordController.text,
        'mail_encryption': _encryption,
        'mail_from_address': _fromAddressController.text,
        'mail_from_name': _fromNameController.text,
        'notify_upload': _notifyUpload,
        'notify_download': _notifyDownload,
        'notify_login': _notifyLogin,
        'notify_newfolder': _notifyNewFolder,
        'notify_registration': _notifyRegistration,
      });
      if (mounted) {
        _passwordController.clear();
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم حفظ إعدادات البريد.')));
      }
    } catch (e) {
      if (mounted) setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _sendTest() async {
    if (_testEmailController.text.trim().isEmpty) return;
    setState(() => _sendingTest = true);
    try {
      await _repository.sendTestMail(_testEmailController.text.trim());
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم إرسال رسالة الاختبار.')));
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    } finally {
      if (mounted) setState(() => _sendingTest = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('البريد الإلكتروني')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _Section(title: 'خادم SMTP', children: [
                    TextField(controller: _hostController, decoration: const InputDecoration(labelText: 'الخادم (Host)'), textDirection: TextDirection.ltr),
                    const SizedBox(height: 8),
                    TextField(controller: _portController, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'المنفذ (Port)'), textDirection: TextDirection.ltr),
                    const SizedBox(height: 8),
                    TextField(controller: _usernameController, decoration: const InputDecoration(labelText: 'اسم المستخدم'), textDirection: TextDirection.ltr),
                    const SizedBox(height: 8),
                    TextField(controller: _passwordController, obscureText: true, decoration: const InputDecoration(labelText: 'كلمة المرور (اتركه فارغاً للإبقاء عليها)'), textDirection: TextDirection.ltr),
                    const SizedBox(height: 8),
                    DropdownButtonFormField<String>(
                      value: _encryption,
                      decoration: const InputDecoration(labelText: 'التشفير'),
                      items: const [
                        DropdownMenuItem(value: 'tls', child: Text('TLS')),
                        DropdownMenuItem(value: 'ssl', child: Text('SSL')),
                        DropdownMenuItem(value: 'none', child: Text('بدون')),
                      ],
                      onChanged: (v) => setState(() => _encryption = v ?? 'tls'),
                    ),
                    const SizedBox(height: 8),
                    TextField(controller: _fromAddressController, decoration: const InputDecoration(labelText: 'عنوان المرسل'), textDirection: TextDirection.ltr),
                    const SizedBox(height: 8),
                    TextField(controller: _fromNameController, decoration: const InputDecoration(labelText: 'اسم المرسل')),
                  ]),
                  _Section(title: 'إشعارات الأحداث (تُرسل لكل مشرف نشط)', children: [
                    SwitchListTile(contentPadding: EdgeInsets.zero, value: _notifyUpload, onChanged: (v) => setState(() => _notifyUpload = v), title: const Text('عند رفع ملف')),
                    SwitchListTile(contentPadding: EdgeInsets.zero, value: _notifyDownload, onChanged: (v) => setState(() => _notifyDownload = v), title: const Text('عند تحميل ملف')),
                    SwitchListTile(contentPadding: EdgeInsets.zero, value: _notifyLogin, onChanged: (v) => setState(() => _notifyLogin = v), title: const Text('عند تسجيل دخول مستخدم')),
                    SwitchListTile(contentPadding: EdgeInsets.zero, value: _notifyNewFolder, onChanged: (v) => setState(() => _notifyNewFolder = v), title: const Text('عند إنشاء مجلد جديد')),
                    SwitchListTile(contentPadding: EdgeInsets.zero, value: _notifyRegistration, onChanged: (v) => setState(() => _notifyRegistration = v), title: const Text('عند انضمام مستخدم جديد')),
                  ]),
                  if (_error != null) ...[
                    Text(_error!, style: const TextStyle(color: Colors.red)),
                    const SizedBox(height: 8),
                  ],
                  FilledButton(onPressed: _saving ? null : _submit, child: const Text('حفظ إعدادات البريد')),
                  const SizedBox(height: 16),
                  _Section(title: 'إرسال رسالة اختبار', children: [
                    Row(children: [
                      Expanded(
                        child: TextField(
                          controller: _testEmailController,
                          keyboardType: TextInputType.emailAddress,
                          decoration: const InputDecoration(hintText: 'you@example.com'),
                          textDirection: TextDirection.ltr,
                        ),
                      ),
                      const SizedBox(width: 8),
                      OutlinedButton(onPressed: _sendingTest ? null : _sendTest, child: const Text('إرسال')),
                    ]),
                  ]),
                ],
              ),
            ),
    );
  }
}

class _Section extends StatelessWidget {
  const _Section({required this.title, required this.children});

  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
            const SizedBox(height: 12),
            ...children,
          ],
        ),
      ),
    );
  }
}
