import 'package:flutter/material.dart';

import '../../../core/network/api_client.dart';
import '../data/admin_repository.dart';
import '../models/analytics_data.dart';
import 'widgets/analytics_line_chart.dart';

/// Mobile counterpart of Admin/Analytics.vue.
class AnalyticsScreen extends StatefulWidget {
  const AnalyticsScreen({super.key});

  @override
  State<AnalyticsScreen> createState() => _AnalyticsScreenState();
}

class _AnalyticsScreenState extends State<AnalyticsScreen> {
  final _repository = AdminRepository();
  AnalyticsData? _data;
  int _days = 30;
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
      final data = await _repository.analytics(days: _days);
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
      appBar: AppBar(title: const Text('التحليلات')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!))
              : ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    Row(
                      children: [
                        const Text('النطاق الزمني:'),
                        const SizedBox(width: 12),
                        DropdownButton<int>(
                          value: _days,
                          items: const [
                            DropdownMenuItem(value: 7, child: Text('آخر 7 أيام')),
                            DropdownMenuItem(value: 30, child: Text('آخر 30 يوماً')),
                            DropdownMenuItem(value: 90, child: Text('آخر 90 يوماً')),
                          ],
                          onChanged: (v) {
                            if (v == null) return;
                            setState(() => _days = v);
                            _load();
                          },
                        ),
                      ],
                    ),
                    const SizedBox(height: 16),
                    GridView.count(
                      crossAxisCount: 2,
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      mainAxisSpacing: 12,
                      crossAxisSpacing: 12,
                      childAspectRatio: 1.6,
                      children: _data!.totals
                          .map((t) => Card(
                                child: Center(
                                  child: Column(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      Text('${t.total}', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold)),
                                      Text(t.label, style: const TextStyle(fontSize: 11, color: Colors.grey), textAlign: TextAlign.center),
                                    ],
                                  ),
                                ),
                              ))
                          .toList(),
                    ),
                    const SizedBox(height: 24),
                    Card(
                      child: Padding(
                        padding: const EdgeInsets.all(16),
                        child: AnalyticsLineChart(data: _data!),
                      ),
                    ),
                  ],
                ),
    );
  }
}
