import 'package:flutter/material.dart';

import '../../../core/i18n/app_strings.dart';
import '../../../core/network/api_client.dart';
import '../data/admin_repository.dart';

const _roleLabelKeys = {'guest': 'role.guest', 'user': 'role.user', 'editor': 'role.editor', 'contributor': 'role.contributor'};
const _actionLabelKeys = {
  'view': 'permission.view',
  'download': 'permission.download',
  'share': 'permission.share',
  'upload': 'permission.upload',
  'delete': 'permission.delete',
  'rename': 'permission.rename',
  'move': 'permission.move',
  'copy': 'permission.copy',
  'new_folder': 'permission.new_folder',
  'delete_folder': 'permission.delete_folder',
  'rename_folder': 'permission.rename_folder',
  'manage_questions': 'permission.manage_questions',
  'comment': 'permission.comment',
};

// Guests can only ever view/download/share by design — matches
// Permissions.vue's GUEST_ALLOWED_ACTIONS.
const _guestAllowedActions = ['view', 'download', 'share'];

/// Mobile counterpart of Admin/Permissions.vue: a scrollable role x action
/// matrix of checkboxes.
class PermissionsScreen extends StatefulWidget {
  const PermissionsScreen({super.key});

  @override
  State<PermissionsScreen> createState() => _PermissionsScreenState();
}

class _PermissionsScreenState extends State<PermissionsScreen> {
  final _repository = AdminRepository();

  bool _loading = true;
  bool _saving = false;
  String? _error;
  List<String> _roles = [];
  List<String> _actions = [];
  Map<String, Map<String, bool>> _matrix = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final payload = await _repository.permissionsPayload();
      final rawMatrix = payload['matrix'] as Map<String, dynamic>;
      setState(() {
        _roles = (payload['roles'] as List).map((e) => e as String).toList();
        _actions = (payload['actions'] as List).map((e) => e as String).toList();
        _matrix = rawMatrix.map((role, actions) => MapEntry(
              role,
              (actions as Map<String, dynamic>).map((action, allowed) => MapEntry(action, allowed as bool)),
            ));
      });
    } catch (e) {
      if (mounted) setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  bool _isEditable(String role, String action) => role != 'guest' || _guestAllowedActions.contains(action);

  Future<void> _submit() async {
    setState(() => _saving = true);
    try {
      await _repository.updatePermissions(_matrix);
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.tr('permissions.saved'))));
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.tr('admin.nav.permissions'))),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _saving ? null : _submit,
        icon: const Icon(Icons.save_outlined),
        label: Text(context.tr('action.save')),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!))
              : Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.all(12),
                      child: Text(
                        context.tr('permissions.description'),
                        style: const TextStyle(fontSize: 12, color: Colors.grey),
                      ),
                    ),
                    Expanded(
                      child: SingleChildScrollView(
                        scrollDirection: Axis.horizontal,
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                        child: DataTable(
                          columns: [
                            DataColumn(label: Text(context.tr('permissions.action'))),
                            ..._roles.map((role) => DataColumn(label: Text(context.tr(_roleLabelKeys[role] ?? role)))),
                          ],
                          rows: _actions.map((action) {
                            return DataRow(cells: [
                              DataCell(Text(context.tr(_actionLabelKeys[action] ?? action))),
                              ..._roles.map((role) {
                                final editable = _isEditable(role, action);
                                return DataCell(Checkbox(
                                  value: _matrix[role]?[action] ?? false,
                                  onChanged: editable
                                      ? (v) => setState(() => _matrix[role]![action] = v ?? false)
                                      : null,
                                ));
                              }),
                            ]);
                          }).toList(),
                        ),
                      ),
                    ),
                    const SizedBox(height: 72),
                  ],
                ),
    );
  }
}
