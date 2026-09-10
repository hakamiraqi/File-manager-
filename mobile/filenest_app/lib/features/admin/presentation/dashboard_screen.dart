import 'package:flutter/material.dart';

import '../../../core/i18n/app_strings.dart';
import '../../../core/network/api_client.dart';
import '../../../core/utils/formatters.dart';
import '../data/admin_repository.dart';
import '../models/dashboard_data.dart';

/// Mobile counterpart of Admin/Dashboard.vue — stat cards, top storage
/// users, and the most recent activity log entries.
class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  final _repository = AdminRepository();
  DashboardData? _data;
  bool _loading = true;
  String? _error;

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
      final data = await _repository.dashboard();
      if (mounted) setState(() => _data = data);
    } catch (e) {
      if (mounted) setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.tr('admin.nav.dashboard'))),
      body: RefreshIndicator(
        onRefresh: _load,
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? ListView(children: [Padding(padding: const EdgeInsets.all(24), child: Text(_error!))])
                : _buildBody(_data!),
      ),
    );
  }

  Widget _buildBody(DashboardData data) {
    final cards = [
      (_StatCard(icon: Icons.people_outline_rounded, label: context.tr('admin.dashboard.users'), value: '${data.stats.users}')),
      (_StatCard(icon: Icons.description_outlined, label: context.tr('admin.dashboard.files'), value: '${data.stats.files}')),
      (_StatCard(icon: Icons.folder_outlined, label: context.tr('admin.dashboard.folders'), value: '${data.stats.folders}')),
      (_StatCard(icon: Icons.ios_share_rounded, label: context.tr('admin.dashboard.shares'), value: '${data.stats.shares}')),
      (_StatCard(icon: Icons.storage_rounded, label: context.tr('admin.dashboard.total_storage'), value: formatBytes(data.stats.storageUsed))),
    ];

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        GridView.count(
          crossAxisCount: 2,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          mainAxisSpacing: 12,
          crossAxisSpacing: 12,
          childAspectRatio: 1.5,
          children: cards,
        ),
        const SizedBox(height: 24),
        Text(context.tr('admin.dashboard.top_users'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
        const SizedBox(height: 8),
        Card(
          child: Column(
            children: data.topUsers
                .map((u) => ListTile(
                      dense: true,
                      title: Text(u.name, overflow: TextOverflow.ellipsis),
                      trailing: Text(formatBytes(u.storageUsedBytes), style: const TextStyle(color: Colors.grey)),
                    ))
                .toList(),
          ),
        ),
        const SizedBox(height: 24),
        Text(context.tr('admin.dashboard.recent_activity'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
        const SizedBox(height: 8),
        Card(
          child: Column(
            children: data.recentActivity
                .map((log) => ListTile(
                      dense: true,
                      title: Text(log.description ?? log.action, overflow: TextOverflow.ellipsis),
                      subtitle: Text(log.userName ?? context.tr('admin.dashboard.system')),
                      trailing: Text(formatDate(log.createdAt), style: const TextStyle(fontSize: 11, color: Colors.grey)),
                    ))
                .toList(),
          ),
        ),
      ],
    );
  }
}

class _StatCard extends StatelessWidget {
  const _StatCard({required this.icon, required this.label, required this.value});

  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon, color: Theme.of(context).colorScheme.primary),
            const SizedBox(height: 6),
            Text(value, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
            Text(label, style: const TextStyle(fontSize: 12, color: Colors.grey), overflow: TextOverflow.ellipsis),
          ],
        ),
      ),
    );
  }
}
