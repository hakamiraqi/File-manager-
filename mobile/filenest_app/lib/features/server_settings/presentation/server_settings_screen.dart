import 'package:flutter/material.dart';

import '../../../core/config/api_config.dart';
import '../../../core/network/server_config_service.dart';

/// Lets the user configure a local-network address (for a fully offline
/// LAN setup, no internet required) and/or a hosted/internet address, and
/// choose whether the app should pick automatically, always use the local
/// one, or always use the hosted one.
class ServerSettingsScreen extends StatefulWidget {
  const ServerSettingsScreen({super.key});

  @override
  State<ServerSettingsScreen> createState() => _ServerSettingsScreenState();
}

class _ServerSettingsScreenState extends State<ServerSettingsScreen> {
  final _service = ServerConfigService.instance;
  final _localController = TextEditingController();
  final _hostedController = TextEditingController();

  ConnectionMode _mode = ConnectionMode.auto;
  bool _loading = true;
  bool _saving = false;

  bool? _localReachable;
  bool? _hostedReachable;
  bool _testingLocal = false;
  bool _testingHosted = false;

  ResolvedServer? _active;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _localController.dispose();
    _hostedController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final local = await _service.localOrigin;
    final hosted = await _service.hostedOrigin;
    final mode = await _service.mode;

    _localController.text = local ?? '';
    _hostedController.text = hosted;

    setState(() {
      _mode = mode;
      _loading = false;
    });
  }

  Future<void> _testConnection({required bool local}) async {
    final origin = local ? _localController.text : _hostedController.text;
    if (origin.trim().isEmpty) return;

    setState(() {
      if (local) {
        _testingLocal = true;
        _localReachable = null;
      } else {
        _testingHosted = true;
        _hostedReachable = null;
      }
    });

    final reachable = await _service.isReachable(origin);

    if (!mounted) return;
    setState(() {
      if (local) {
        _testingLocal = false;
        _localReachable = reachable;
      } else {
        _testingHosted = false;
        _hostedReachable = reachable;
      }
    });
  }

  Future<void> _save() async {
    setState(() => _saving = true);

    await _service.save(
      localOrigin: _localController.text,
      hostedOrigin: _hostedController.text.trim().isEmpty
          ? ApiConfig.defaultHostedOrigin
          : _hostedController.text,
      mode: _mode,
    );

    final active = await _service.resolveAndApply();

    if (!mounted) return;
    setState(() {
      _active = active;
      _saving = false;
    });

    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(active.usedLocal
          ? 'تم الحفظ — متصل الآن عبر الشبكة المحلية.'
          : 'تم الحفظ — متصل الآن عبر الاستضافة.'),
    ));
  }

  Widget _statusIcon(bool? reachable, bool testing) {
    if (testing) {
      return const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2));
    }
    if (reachable == null) return const SizedBox.shrink();
    return Icon(
      reachable ? Icons.check_circle : Icons.error,
      color: reachable ? Colors.green : Colors.red,
      size: 20,
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    return Scaffold(
      appBar: AppBar(title: const Text('إعدادات الخادم')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            const Text(
              'اختر كيف يتصل التطبيق بخادم FileNest: عبر الشبكة المحلية مباشرة (بدون إنترنت، مثلاً في المنزل أو المكتب)، أو عبر الاستضافة على الإنترنت، أو تلقائياً بحيث يجرّب الشبكة المحلية أولاً وينتقل للاستضافة عند تعذّر الوصول إليها.',
              style: TextStyle(color: Colors.grey),
            ),
            const SizedBox(height: 20),

            const Text('طريقة الاتصال', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 12),
            SegmentedButton<ConnectionMode>(
              segments: const [
                ButtonSegment(value: ConnectionMode.auto, label: Text('تلقائي'), icon: Icon(Icons.sync)),
                ButtonSegment(value: ConnectionMode.local, label: Text('محلي فقط'), icon: Icon(Icons.wifi)),
                ButtonSegment(value: ConnectionMode.hosted, label: Text('استضافة'), icon: Icon(Icons.cloud)),
              ],
              selected: {_mode},
              onSelectionChanged: (selection) => setState(() => _mode = selection.first),
            ),

            const Divider(height: 40),

            const Text('عنوان الشبكة المحلية (بدون إنترنت)', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 4),
            const Text(
              'مثال: http://192.168.1.10:8000 — عنوان الجهاز الذي يشغّل FileNest على نفس شبكة WiFi. اتركه فارغاً إن لم يكن لديك خادم محلي.',
              style: TextStyle(color: Colors.grey, fontSize: 12),
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _localController,
                    keyboardType: TextInputType.url,
                    decoration: const InputDecoration(
                      labelText: 'عنوان الشبكة المحلية',
                      hintText: 'http://192.168.1.10:8000',
                      border: OutlineInputBorder(),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                _statusIcon(_localReachable, _testingLocal),
                IconButton(
                  icon: const Icon(Icons.wifi_find_rounded),
                  tooltip: 'اختبار الاتصال',
                  onPressed: _testingLocal ? null : () => _testConnection(local: true),
                ),
              ],
            ),

            const SizedBox(height: 24),

            const Text('عنوان الاستضافة (الإنترنت)', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            const SizedBox(height: 4),
            const Text(
              'مثال: https://your-domain.com — الخادم الذي يمكن الوصول إليه من أي مكان عبر الإنترنت.',
              style: TextStyle(color: Colors.grey, fontSize: 12),
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _hostedController,
                    keyboardType: TextInputType.url,
                    decoration: const InputDecoration(
                      labelText: 'عنوان الاستضافة',
                      hintText: 'https://your-domain.com',
                      border: OutlineInputBorder(),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                _statusIcon(_hostedReachable, _testingHosted),
                IconButton(
                  icon: const Icon(Icons.wifi_find_rounded),
                  tooltip: 'اختبار الاتصال',
                  onPressed: _testingHosted ? null : () => _testConnection(local: false),
                ),
              ],
            ),

            const SizedBox(height: 28),
            FilledButton(
              onPressed: _saving ? null : _save,
              child: _saving
                  ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Text('حفظ وتطبيق'),
            ),

            if (_active != null) ...[
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Theme.of(context).colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Row(
                  children: [
                    Icon(_active!.usedLocal ? Icons.wifi : Icons.cloud, size: 20),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'متصل الآن عبر ${_active!.usedLocal ? "الشبكة المحلية" : "الاستضافة"}: ${_active!.origin}',
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
