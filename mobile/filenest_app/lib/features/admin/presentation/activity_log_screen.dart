import 'package:flutter/material.dart';

import '../../../core/network/api_client.dart';
import '../../../core/utils/formatters.dart';
import '../data/admin_repository.dart';
import '../models/activity_log_entry.dart';

/// Mobile counterpart of Admin/ActivityLog.vue.
class ActivityLogScreen extends StatefulWidget {
  const ActivityLogScreen({super.key});

  @override
  State<ActivityLogScreen> createState() => _ActivityLogScreenState();
}

class _ActivityLogScreenState extends State<ActivityLogScreen> {
  final _repository = AdminRepository();
  ActivityLogPage? _page;
  int _currentPage = 1;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({int page = 1}) async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final result = await _repository.activityLog(page: page);
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('سجل النشاطات')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!))
              : RefreshIndicator(
                  onRefresh: () => _load(page: _currentPage),
                  child: _buildList(),
                ),
    );
  }

  Widget _buildList() {
    final page = _page;
    if (page == null || page.logs.isEmpty) {
      return ListView(children: const [Padding(padding: EdgeInsets.all(24), child: Text('لا توجد نشاطات بعد.'))]);
    }

    return ListView.separated(
      itemCount: page.logs.length + 1,
      separatorBuilder: (_, __) => const Divider(height: 1),
      itemBuilder: (context, index) {
        if (index == page.logs.length) return _buildPager(page);
        final ActivityLogEntry log = page.logs[index];
        return ListTile(
          dense: true,
          title: Text(log.description ?? log.action, overflow: TextOverflow.ellipsis),
          subtitle: Text('${log.userName ?? 'النظام'} · ${log.ipAddress ?? ''}'),
          trailing: Text(formatDate(log.createdAt), style: const TextStyle(fontSize: 11, color: Colors.grey)),
        );
      },
    );
  }

  Widget _buildPager(ActivityLogPage page) {
    if (page.lastPage <= 1) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.all(12),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          IconButton(icon: const Icon(Icons.chevron_right_rounded), onPressed: _currentPage > 1 ? () => _load(page: _currentPage - 1) : null),
          Text('${page.currentPage} / ${page.lastPage}'),
          IconButton(icon: const Icon(Icons.chevron_left_rounded), onPressed: _currentPage < page.lastPage ? () => _load(page: _currentPage + 1) : null),
        ],
      ),
    );
  }
}
