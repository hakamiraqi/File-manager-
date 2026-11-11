import 'dart:async';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../config/api_config.dart';
import '../network/api_client.dart';
import '../widgets/ui_kit.dart';

/// «التحديث التلقائي» (2026-11-11, by request, like the clinics app): the app looks at the
/// server's releases list when it starts and every few hours. A newer Android or Windows build
/// is downloaded in the background, then the reader is asked once to install it. iPhone builds
/// are installed from the download page.
class AppUpdater {
  AppUpdater._();
  static final AppUpdater instance = AppUpdater._();

  /// Set by the build (--dart-define=APP_VERSION=1.0.N).
  static const current = String.fromEnvironment('APP_VERSION', defaultValue: '0.0.0');

  Timer? _timer;
  bool _busy = false;
  String? _offered;

  String get _platform => Platform.isAndroid ? 'android' : Platform.isWindows ? 'windows' : Platform.isIOS ? 'ios' : '';

  static bool newer(String a, String b) {
    final x = a.split('.').map((e) => int.tryParse(e) ?? 0).toList();
    final y = b.split('.').map((e) => int.tryParse(e) ?? 0).toList();
    for (var i = 0; i < 3; i++) {
      final p = i < x.length ? x[i] : 0, q = i < y.length ? y[i] : 0;
      if (p != q) return p > q;
    }
    return false;
  }

  /// Checks now and then every 6 hours while the app is open.
  void start(BuildContext context) {
    if (current == '0.0.0') return; // a development build
    Future.delayed(const Duration(seconds: 8), () {
      if (context.mounted) check(context);
    });
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(hours: 6), (_) {
      if (context.mounted) check(context);
    });
  }

  /// The newest release for this device, or null when this one is current (or offline).
  Future<({String version, String url})?> latest() async {
    final base = ApiConfig.stripApiSuffix(ApiClient.instance.baseUrl);
    final r = await Dio(BaseOptions(connectTimeout: const Duration(seconds: 10), receiveTimeout: const Duration(seconds: 20)))
        .get('$base/downloads/releases.json');
    final data = r.data is Map ? r.data as Map : null;
    final mine = data?[_platform];
    if (mine is! Map) return null;
    final v = '${mine['version']}';
    if (!newer(v, current)) return null;
    final url = '${mine['url']}';
    return (version: v, url: url.startsWith('http') ? url : '$base$url');
  }

  Future<void> check(BuildContext context, {bool manual = false}) async {
    if (_busy) return;
    _busy = true;
    try {
      final rel = await latest();
      if (rel == null) {
        if (manual && context.mounted) context.toast(context.l('لديك آخر نسخة ($current).', 'You have the latest version ($current).'));
        return;
      }
      if (!manual && _offered == rel.version) return;
      if (Platform.isIOS) {
        _offered = rel.version;
        if (context.mounted && await _ask(context, rel.version, ready: false)) {
          await launchUrl(Uri.parse('${ApiConfig.stripApiSuffix(ApiClient.instance.baseUrl)}/download'), mode: LaunchMode.externalApplication);
        }
        return;
      }
      // Downloaded quietly first, so «تثبيت» starts at once.
      final dir = await getTemporaryDirectory();
      final path = '${dir.path}/${Uri.parse(rel.url).pathSegments.last}';
      final file = File(path);
      if (!await file.exists() || await file.length() == 0) {
        await Dio().download(rel.url, '$path.part');
        await File('$path.part').rename(path);
      }
      _offered = rel.version;
      if (!context.mounted || !await _ask(context, rel.version, ready: true)) return;
      if (Platform.isWindows) {
        await Process.start(path, ['/SILENT', '/CLOSEAPPLICATIONS'], mode: ProcessStartMode.detached);
        exit(0);
      }
      final r = await OpenFilex.open(path, type: 'application/vnd.android.package-archive');
      if (r.type != ResultType.done && context.mounted) {
        context.toast(context.l('اسمح للتطبيق بتثبيت التحديثات من الإعدادات ثم أعد المحاولة.', 'Allow the app to install updates in Settings, then try again.'), error: true);
      }
    } catch (_) {
      if (manual && context.mounted) context.toast(context.l('تعذّر التحقق من التحديثات الآن.', 'Could not check for updates now.'), error: true);
    } finally {
      _busy = false;
    }
  }

  Future<bool> _ask(BuildContext context, String version, {required bool ready}) async {
    final ok = await showDialog<bool>(
      context: context,
      useRootNavigator: true,
      builder: (dialog) => AlertDialog(
        icon: const Icon(Icons.system_update_rounded, size: 36),
        title: Text(context.l('تحديث جديد $version', 'Update $version')),
        content: Text(ready
            ? context.l('نزل التحديث وهو جاهز للتثبيت. نسختك الحالية $current.', 'The update is downloaded and ready. You have $current.')
            : context.l('نسخة أحدث متاحة في صفحة التنزيل. نسختك الحالية $current.', 'A newer version is on the download page. You have $current.')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dialog, false), child: Text(context.l('لاحقاً', 'Later'))),
          FilledButton(onPressed: () => Navigator.pop(dialog, true), child: Text(ready ? context.l('تثبيت الآن', 'Install now') : context.l('فتح صفحة التنزيل', 'Open download page'))),
        ],
      ),
    );
    return ok == true;
  }
}
