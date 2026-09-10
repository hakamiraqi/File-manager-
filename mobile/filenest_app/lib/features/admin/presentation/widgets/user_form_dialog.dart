import 'package:flutter/material.dart';

import '../../../../core/i18n/app_strings.dart';
import '../../../../core/network/api_client.dart';
import '../../data/admin_repository.dart';
import '../../models/admin_user.dart';

const _capabilityLabelKeys = {
  'can_manage_appearance': 'capability.manage_appearance',
  'can_manage_users': 'capability.manage_users',
  'can_manage_translations': 'capability.manage_translations',
  'can_view_statistics': 'capability.view_statistics',
  'can_manage_settings': 'capability.manage_settings',
};

const _roleLabelKeys = {'user': 'role.user', 'editor': 'role.editor', 'contributor': 'role.contributor', 'admin': 'role.admin'};

/// Create/edit dialog mirroring UserFormModal.vue. Returns true via
/// Navigator.pop when a user was created/updated, so the caller knows to
/// refresh its list; null/false means the dialog was dismissed.
class UserFormDialog extends StatefulWidget {
  const UserFormDialog({super.key, this.user, required this.isMasterAdmin});

  final AdminUser? user;
  final bool isMasterAdmin;

  @override
  State<UserFormDialog> createState() => _UserFormDialogState();
}

class _UserFormDialogState extends State<UserFormDialog> {
  final _repository = AdminRepository();
  final _nameController = TextEditingController();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  final _quotaController = TextEditingController();

  late String _role;
  bool _isActive = true;
  final Map<String, bool> _capabilities = {for (final key in _capabilityLabelKeys.keys) key: false};

  bool _saving = false;
  String? _error;

  bool get _isEdit => widget.user != null;

  @override
  void initState() {
    super.initState();
    final user = widget.user;
    if (user != null) {
      _nameController.text = user.name;
      _emailController.text = user.email;
      _role = user.role;
      _isActive = user.isActive;
      _quotaController.text = user.storageQuotaBytes > 0 ? (user.storageQuotaBytes / (1024 * 1024)).round().toString() : '';
      _capabilities['can_manage_appearance'] = user.canManageAppearance;
      _capabilities['can_manage_users'] = user.canManageUsers;
      _capabilities['can_manage_translations'] = user.canManageTranslations;
      _capabilities['can_view_statistics'] = user.canViewStatistics;
      _capabilities['can_manage_settings'] = user.canManageSettings;
    } else {
      _role = 'user';
    }
  }

  @override
  void dispose() {
    _nameController.dispose();
    _emailController.dispose();
    _passwordController.dispose();
    _quotaController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _saving = true;
      _error = null;
    });

    final data = <String, dynamic>{
      'name': _nameController.text.trim(),
      'email': _emailController.text.trim(),
      'role': _role,
      'storage_quota_mb': _quotaController.text.trim().isEmpty ? null : int.tryParse(_quotaController.text.trim()),
      if (_role == 'admin' && widget.isMasterAdmin) ..._capabilities,
      if (_isEdit) 'is_active': _isActive,
    };
    if (_passwordController.text.isNotEmpty) data['password'] = _passwordController.text;

    try {
      if (_isEdit) {
        await _repository.updateUser(widget.user!.id, data);
      } else {
        await _repository.createUser(data);
      }
      if (mounted) Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(_isEdit ? context.tr('admin.users.edit') : context.tr('admin.users.new')),
      content: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextField(controller: _nameController, decoration: InputDecoration(labelText: context.tr('auth.name'))),
            const SizedBox(height: 8),
            TextField(
              controller: _emailController,
              keyboardType: TextInputType.emailAddress,
              decoration: InputDecoration(labelText: context.tr('auth.email')),
            ),
            const SizedBox(height: 8),
            TextField(
              controller: _passwordController,
              obscureText: true,
              decoration: InputDecoration(labelText: _isEdit ? context.tr('admin.users.new_password_optional') : context.tr('auth.password')),
            ),
            const SizedBox(height: 8),
            DropdownButtonFormField<String>(
              value: _role,
              decoration: InputDecoration(labelText: context.tr('admin.users.role')),
              items: _roleLabelKeys.entries.map((e) => DropdownMenuItem(value: e.key, child: Text(context.tr(e.value)))).toList(),
              onChanged: (value) => setState(() => _role = value ?? 'user'),
            ),
            const SizedBox(height: 8),
            TextField(
              controller: _quotaController,
              keyboardType: TextInputType.number,
              decoration: InputDecoration(labelText: context.tr('admin.users.quota')),
            ),
            if (_isEdit)
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                value: _isActive,
                onChanged: (v) => setState(() => _isActive = v ?? true),
                title: Text(context.tr('admin.users.account_active')),
              ),
            if (_role == 'admin' && widget.isMasterAdmin) ...[
              const Divider(),
              Align(alignment: Alignment.centerRight, child: Text(context.tr('admin.users.admin_capabilities'), style: const TextStyle(fontWeight: FontWeight.w600))),
              ..._capabilityLabelKeys.entries.map((e) => CheckboxListTile(
                    contentPadding: EdgeInsets.zero,
                    controlAffinity: ListTileControlAffinity.leading,
                    dense: true,
                    value: _capabilities[e.key],
                    onChanged: (v) => setState(() => _capabilities[e.key] = v ?? false),
                    title: Text(context.tr(e.value)),
                  )),
            ],
            if (_error != null) ...[
              const SizedBox(height: 8),
              Text(_error!, style: const TextStyle(color: Colors.red)),
            ],
          ],
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: Text(context.tr('action.cancel'))),
        FilledButton(onPressed: _saving ? null : _submit, child: Text(context.tr('action.save'))),
      ],
    );
  }
}
