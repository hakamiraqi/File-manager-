import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/i18n/app_strings.dart';
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
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.tr('account.profile_saved'))));
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
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.tr('account.password_updated'))));
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
        title: Text(context.tr('account.delete_title')),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(context.tr('account.delete_confirm')),
            const SizedBox(height: 12),
            TextField(
              controller: _deletePasswordController,
              obscureText: true,
              decoration: InputDecoration(labelText: context.tr('auth.password')),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: Text(context.tr('action.cancel'))),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () => Navigator.of(context).pop(true),
            child: Text(context.tr('account.delete_permanently')),
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
      appBar: AppBar(title: Text(context.tr('nav.account'))),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(context.tr('account.personal_info'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 12),
            TextField(
              controller: _nameController,
              decoration: InputDecoration(labelText: context.tr('auth.name'), border: const OutlineInputBorder()),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _emailController,
              keyboardType: TextInputType.emailAddress,
              decoration: InputDecoration(labelText: context.tr('auth.email'), border: const OutlineInputBorder()),
            ),
            if (_profileError != null) ...[
              const SizedBox(height: 8),
              Text(_profileError!, style: const TextStyle(color: Colors.red)),
            ],
            const SizedBox(height: 12),
            FilledButton(onPressed: _savingProfile ? null : _saveProfile, child: Text(context.tr('action.save'))),

            const Divider(height: 40),

            Text(context.tr('settings.language'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 12),
            SegmentedButton<String>(
              segments: [
                ButtonSegment(value: 'ar', label: Text(context.tr('settings.language_ar'))),
                const ButtonSegment(value: 'en', label: Text('English')),
              ],
              selected: {user?.locale ?? 'ar'},
              onSelectionChanged: _savingLocale ? null : (selection) => _changeLocale(selection.first),
            ),

            const Divider(height: 40),

            Text(context.tr('account.change_password'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 12),
            TextField(
              controller: _currentPasswordController,
              obscureText: true,
              decoration: InputDecoration(labelText: context.tr('password.current'), border: const OutlineInputBorder()),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _newPasswordController,
              obscureText: true,
              decoration: InputDecoration(labelText: context.tr('password.new'), border: const OutlineInputBorder()),
            ),
            if (_passwordError != null) ...[
              const SizedBox(height: 8),
              Text(_passwordError!, style: const TextStyle(color: Colors.red)),
            ],
            const SizedBox(height: 12),
            FilledButton(onPressed: _savingPassword ? null : _changePassword, child: Text(context.tr('account.update_password'))),

            const Divider(height: 40),

            Text(context.tr('account.danger_zone'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.red)),
            const SizedBox(height: 12),
            OutlinedButton(
              onPressed: _confirmDeleteAccount,
              style: OutlinedButton.styleFrom(foregroundColor: Colors.red),
              child: Text(context.tr('account.delete_permanently')),
            ),
          ],
        ),
      ),
    );
  }
}
