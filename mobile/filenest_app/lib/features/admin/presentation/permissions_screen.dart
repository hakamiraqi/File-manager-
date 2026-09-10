import 'package:flutter/material.dart';

import '../../../core/network/api_client.dart';
import '../data/admin_repository.dart';

const _roleLabels = {'guest': 'زائر', 'user': 'مستخدم', 'editor': 'محرّر', 'contributor': 'مساهم'};
const _actionLabels = {
  'view': 'عرض الملفات',
  'download': 'تحميل الملفات',
  'share': 'إنشاء روابط مشاركة',
  'upload': 'رفع ملفات',
  'delete': 'حذف ملفات',
  'rename': 'إعادة تسمية ملفات',
  'move': 'نقل ملفات/مجلدات',
  'copy': 'نسخ ملفات/مجلدات',
  'new_folder': 'إنشاء مجلدات',
  'delete_folder': 'حذف مجلدات',
  'rename_folder': 'إعادة تسمية مجلدات',
  'manage_questions': 'إدارة أسئلة الفيديو التفاعلي',
  'comment': 'التعليق على الفيديوهات',
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
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم حفظ الصلاحيات.')));
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('مصفوفة الصلاحيات')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _saving ? null : _submit,
        icon: const Icon(Icons.save_outlined),
        label: const Text('حفظ'),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!))
              : Column(
                  children: [
                    const Padding(
                      padding: EdgeInsets.all(12),
                      child: Text(
                        'يملك المدير (Admin) دائماً كل الصلاحيات ولا يظهر في هذا الجدول.',
                        style: TextStyle(fontSize: 12, color: Colors.grey),
                      ),
                    ),
                    Expanded(
                      child: SingleChildScrollView(
                        scrollDirection: Axis.horizontal,
                        padding: const EdgeInsets.symmetric(horizontal: 12),
                        child: DataTable(
                          columns: [
                            const DataColumn(label: Text('الإجراء')),
                            ..._roles.map((role) => DataColumn(label: Text(_roleLabels[role] ?? role))),
                          ],
                          rows: _actions.map((action) {
                            return DataRow(cells: [
                              DataCell(Text(_actionLabels[action] ?? action)),
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
