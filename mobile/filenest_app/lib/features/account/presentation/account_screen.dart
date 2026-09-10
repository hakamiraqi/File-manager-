import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/network/api_client.dart';
import '../../auth/state/auth_provider.dart';
import '../data/profile_service.dart';

class AccountScreen extends StatefulWidget {
  const AccountScreen({super.key});

  @override
  State<AccountScreen> createState() => _AccountScreenState();
}

class _AccountScreenState extends State<AccountScreen> {
  final _profileService = ProfileService();

  final _nameController = TextEditingController();
  final _emailController = TextEditingController();
  final _currentPasswordController = TextEditingController();
  final _newPasswordController = TextEditingController();
  final _deletePasswordController = TextEditingController();

  bool _savingProfile = false;
  bool _savingPassword = false;
  bool _savingLocale = false;
  String? _profileError;
  String? _passwordError;

  @override
  void initState() {
    super.initState();
    final user = context.read<AuthProvider>().user;
    _nameController.text = user?.name ?? '';
    _emailController.text = user?.email ?? '';
  }

  @override
  void dispose() {
    _nameController.dispose();
    _emailController.dispose();
    _currentPasswordController.dispose();
    _newPasswordController.dispose();
    _deletePasswordController.dispose();
    super.dispose();
  }

  Future<void> _saveProfile() async {
    setState(() {
      _savingProfile = true;
      _profileError = null;
    });
    try {
      await _profileService.updateProfile(
        name: _nameController.text.trim(),
        email: _emailController.text.trim(),
      );
      await context.read<AuthProvider>().refreshProfile();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم حفظ التغييرات.')));
      }
    } catch (e) {
      setState(() => _profileError = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _savingProfile = false);
    }
  }

  Future<void> _changePassword() async {
    setState(() {
      _savingPassword = true;
      _passwordError = null;
    });
    try {
      await _profileService.updatePassword(
        currentPassword: _currentPasswordController.text,
        password: _newPasswordController.text,
      );
      _currentPasswordController.clear();
      _newPasswordController.clear();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم تحديث كلمة المرور.')));
      }
    } catch (e) {
      setState(() => _passwordError = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _savingPassword = false);
    }
  }

  Future<void> _changeLocale(String locale) async {
    setState(() => _savingLocale = true);
    try {
      await _profileService.updatePreferences(locale: locale);
      await context.read<AuthProvider>().refreshProfile();
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    } finally {
      if (mounted) setState(() => _savingLocale = false);
    }
  }

  Future<void> _confirmDeleteAccount() async {
    _deletePasswordController.clear();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('حذف الحساب نهائياً'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('سيتم حذف حسابك وجميع ملفاتك نهائياً. أدخل كلمة المرور للتأكيد.'),
            const SizedBox(height: 12),
            TextField(
              controller: _deletePasswordController,
              obscureText: true,
              decoration: const InputDecoration(labelText: 'كلمة المرور'),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('إلغاء')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('حذف نهائياً'),
          ),
        ],
      ),
    );

    if (confirmed != true || !mounted) return;

    try {
      await context.read<AuthProvider>().deleteAccount(_deletePasswordController.text);
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user;

    return Scaffold(
      appBar: AppBar(title: const Text('الحساب')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            const Text('البيانات الشخصية', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 12),
            TextField(
              controller: _nameController,
              decoration: const InputDecoration(labelText: 'الاسم', border: OutlineInputBorder()),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _emailController,
              keyboardType: TextInputType.emailAddress,
              decoration: const InputDecoration(labelText: 'البريد الإلكتروني', border: OutlineInputBorder()),
            ),
            if (_profileError != null) ...[
              const SizedBox(height: 8),
              Text(_profileError!, style: const TextStyle(color: Colors.red)),
            ],
            const SizedBox(height: 12),
            FilledButton(onPressed: _savingProfile ? null : _saveProfile, child: const Text('حفظ')),

            const Divider(height: 40),

            const Text('اللغة', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 12),
            SegmentedButton<String>(
              segments: const [
                ButtonSegment(value: 'ar', label: Text('العربية')),
                ButtonSegment(value: 'en', label: Text('English')),
              ],
              selected: {user?.locale ?? 'ar'},
              onSelectionChanged: _savingLocale ? null : (selection) => _changeLocale(selection.first),
            ),

            const Divider(height: 40),

            const Text('تغيير كلمة المرور', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 12),
            TextField(
              controller: _currentPasswordController,
              obscureText: true,
              decoration: const InputDecoration(labelText: 'كلمة المرور الحالية', border: OutlineInputBorder()),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _newPasswordController,
              obscureText: true,
              decoration: const InputDecoration(labelText: 'كلمة المرور الجديدة', border: OutlineInputBorder()),
            ),
            if (_passwordError != null) ...[
              const SizedBox(height: 8),
              Text(_passwordError!, style: const TextStyle(color: Colors.red)),
            ],
            const SizedBox(height: 12),
            FilledButton(onPressed: _savingPassword ? null : _changePassword, child: const Text('تحديث كلمة المرور')),

            const Divider(height: 40),

            const Text('منطقة الخطر', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.red)),
            const SizedBox(height: 12),
            OutlinedButton(
              onPressed: _confirmDeleteAccount,
              style: OutlinedButton.styleFrom(foregroundColor: Colors.red),
              child: const Text('حذف الحساب نهائياً'),
            ),
          ],
        ),
      ),
    );
  }
}
