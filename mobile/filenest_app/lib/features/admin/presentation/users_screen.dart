import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/i18n/app_strings.dart';
import '../../../core/network/api_client.dart';
import '../../../core/utils/formatters.dart';
import '../../auth/state/auth_provider.dart';
import '../data/admin_repository.dart';
import '../models/admin_user.dart';
import 'widgets/user_form_dialog.dart';

const _roleLabelKeys = {'admin': 'role.admin', 'editor': 'role.editor', 'contributor': 'role.contributor', 'user': 'role.user'};

/// Mobile counterpart of Admin/Users/Index.vue: searchable, paginated user
/// list with create/edit/delete.
class UsersScreen extends StatefulWidget {
  const UsersScreen({super.key});

  @override
  State<UsersScreen> createState() => _UsersScreenState();
}

class _UsersScreenState extends State<UsersScreen> {
  final _repository = AdminRepository();
  final _searchController = TextEditingController();

  AdminUserPage? _page;
  int _currentPage = 1;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _load({int page = 1}) async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final result = await _repository.users(query: _searchController.text.trim(), page: page);
      if (mounted) {
        setState(() {
          _page = result;
          _currentPage = page;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _openForm({AdminUser? user}) async {
    final isMasterAdmin = context.read<AuthProvider>().user?.isMasterAdmin ?? false;
    final saved = await showDialog<bool>(
      context: context,
      builder: (_) => UserFormDialog(user: user, isMasterAdmin: isMasterAdmin),
    );
    if (saved == true) _load(page: _currentPage);
  }

  Future<void> _delete(AdminUser user) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(context.tr('admin.users.delete_title')),
        content: Text(context.tr('admin.users.confirm_delete', {'name': user.name})),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: Text(context.tr('action.cancel'))),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () => Navigator.of(context).pop(true),
            child: Text(context.tr('action.delete')),
          ),
        ],
      ),
    );
    if (confirmed != true) return;

    try {
      await _repository.deleteUser(user.id);
      _load(page: _currentPage);
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.tr('admin.nav.users'))),
      floatingActionButton: FloatingActionButton(
        onPressed: () => _openForm(),
        child: const Icon(Icons.add_rounded),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(12),
            child: TextField(
              controller: _searchController,
              decoration: InputDecoration(
                hintText: context.tr('admin.users.search_placeholder'),
                prefixIcon: const Icon(Icons.search_rounded),
                border: const OutlineInputBorder(),
                isDense: true,
                suffixIcon: IconButton(icon: const Icon(Icons.clear_rounded), onPressed: () {
                  _searchController.clear();
                  _load();
                }),
              ),
              onSubmitted: (_) => _load(),
            ),
          ),
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : _error != null
                    ? Center(child: Text(_error!))
                    : _buildList(),
          ),
        ],
      ),
    );
  }

  Widget _buildList() {
    final page = _page;
    if (page == null || page.users.isEmpty) {
      return Center(child: Text(context.tr('admin.users.none')));
    }

    return RefreshIndicator(
      onRefresh: () => _load(page: _currentPage),
      child: ListView.separated(
        itemCount: page.users.length + 1,
        separatorBuilder: (_, __) => const Divider(height: 1),
        itemBuilder: (context, index) {
          if (index == page.users.length) return _buildPager(page);
          final user = page.users[index];
          return ListTile(
            title: Text(user.name),
            subtitle: Text('${user.email} · ${formatBytes(user.storageUsedBytes)} / ${formatBytes(user.storageQuotaBytes)}'),
            trailing: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                _RoleChip(role: user.role, isMasterAdmin: user.isMasterAdmin, isActive: user.isActive),
                IconButton(icon: const Icon(Icons.edit_outlined), onPressed: () => _openForm(user: user)),
                if (!user.isMasterAdmin)
                  IconButton(icon: const Icon(Icons.delete_outline_rounded, color: Colors.red), onPressed: () => _delete(user)),
              ],
            ),
          );
        },
      ),
    );
  }

  Widget _buildPager(AdminUserPage page) {
    if (page.lastPage <= 1) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.all(12),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          IconButton(
            icon: const Icon(Icons.chevron_right_rounded),
            onPressed: _currentPage > 1 ? () => _load(page: _currentPage - 1) : null,
          ),
          Text('${page.currentPage} / ${page.lastPage}'),
          IconButton(
            icon: const Icon(Icons.chevron_left_rounded),
            onPressed: _currentPage < page.lastPage ? () => _load(page: _currentPage + 1) : null,
          ),
        ],
      ),
    );
  }
}

class _RoleChip extends StatelessWidget {
  const _RoleChip({required this.role, required this.isMasterAdmin, required this.isActive});

  final String role;
  final bool isMasterAdmin;
  final bool isActive;

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Text(
          isMasterAdmin
              ? '${context.tr(_roleLabelKeys[role] ?? role)} (${context.tr('admin.users.master')})'
              : context.tr(_roleLabelKeys[role] ?? role),
          style: TextStyle(
            fontSize: 11,
            fontWeight: FontWeight.w600,
            color: role == 'admin' ? Theme.of(context).colorScheme.primary : Colors.grey.shade600,
          ),
        ),
        Text(
          isActive ? context.tr('admin.users.active') : context.tr('admin.users.suspended'),
          style: TextStyle(fontSize: 10, color: isActive ? Colors.green : Colors.red),
        ),
      ],
    );
  }
}
