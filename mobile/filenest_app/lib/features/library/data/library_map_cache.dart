import 'dart:convert';
import 'dart:io';

import 'package:path_provider/path_provider.dart';

import '../../../core/config/api_config.dart';
import '../../../core/network/api_client.dart';
import '../../../core/storage/preferences_storage.dart';

/// The library map kept on the device (2026-11-11, by request), so it opens without internet.
/// The server's «version» changes whenever the map is edited; [refresh] replaces the saved copy
/// only then, and says so, so the app can tell the reader the map was updated.
class LibraryMapCache {
  LibraryMapCache._();
  static final LibraryMapCache instance = LibraryMapCache._();

  Map<String, dynamic>? _data;

  Future<File> _file() async => File('${(await getApplicationSupportDirectory()).path}/library_map.json');

  /// The saved map, or null before the first download.
  Future<Map<String, dynamic>?> saved() async {
    if (_data != null) return _data;
    try {
      final f = await _file();
      if (await f.exists()) _data = Map<String, dynamic>.from(jsonDecode(await f.readAsString()) as Map);
    } catch (_) {}
    return _data;
  }

  /// Fetches the map; returns true when it changed (or was saved for the first time).
  /// Throws when there is no internet. With «خريطة المكتبة بدون إنترنت» off it is not kept.
  Future<bool> refresh() async {
    final r = await ApiClient.instance.dio.get('/public/library/map-data');
    final fresh = Map<String, dynamic>.from(r.data as Map);
    final old = await saved();
    final changed = old == null || old['version'] != fresh['version'];
    _data = fresh;
    if (await PreferencesStorage.instance.readOfflineMap()) {
      if (changed) await (await _file()).writeAsString(jsonEncode(fresh));
    } else {
      try {
        final f = await _file();
        if (await f.exists()) await f.delete();
      } catch (_) {}
    }
    return changed;
  }

  /// Opening the library: keep the saved map current, quietly.
  Future<void> refreshQuietly() async {
    try {
      if (await PreferencesStorage.instance.readOfflineMap()) {
        await refresh();
        await refreshBundle();
      }
    } catch (_) {}
  }

  // The map page itself (3D and flat plan, search, the walk to a bookcase), built by the server
  // as one script and one stylesheet (/library/map-bundle), kept next to the saved data.

  Future<Directory> _bundleDir() async {
    final dir = Directory('${(await getApplicationSupportDirectory()).path}/offline_map');
    if (!await dir.exists()) await dir.create(recursive: true);
    return dir;
  }

  Future<bool> hasBundle() async {
    final dir = await _bundleDir();
    return File('${dir.path}/map.js').existsSync() && File('${dir.path}/map.css').existsSync() && File('${dir.path}/version').existsSync();
  }

  /// Downloads the map page when the server has a new build of it; true when it changed.
  Future<bool> refreshBundle() async {
    if (!await PreferencesStorage.instance.readOfflineMap()) return false;
    final r = await ApiClient.instance.dio.get('/public/library/map-bundle');
    final info = Map<String, dynamic>.from(r.data as Map);
    final dir = await _bundleDir();
    final versionFile = File('${dir.path}/version');
    final version = '${info['version']}';
    if (versionFile.existsSync() && versionFile.readAsStringSync() == version && await hasBundle()) return false;
    final base = ApiConfig.stripApiSuffix(ApiClient.instance.baseUrl);
    final dio = ApiClient.instance.dio;
    await dio.download('$base${info['js']}?v=$version', '${dir.path}/map.js.part');
    await dio.download('$base${info['css']}?v=$version', '${dir.path}/map.css.part');
    await File('${dir.path}/map.js.part').rename('${dir.path}/map.js');
    await File('${dir.path}/map.css.part').rename('${dir.path}/map.css');
    await versionFile.writeAsString(version);
    return true;
  }

  /// Everything fetched while there is internet: null when offline, else whether the saved
  /// map changed (a bookcase moved, a new build of the page).
  Future<bool?> refreshAll() async {
    try {
      final hadData = await saved() != null;
      final data = await refresh().timeout(const Duration(seconds: 15));
      final page = await refreshBundle().timeout(const Duration(minutes: 2));
      return hadData && (data || page);
    } catch (_) {
      return null;
    }
  }

  /// The whole map as one page (script, style and data inside), opened from the device.
  Future<String?> buildHtml({int? bookId, bool dark = false}) async {
    final data = await saved();
    if (data == null || !await hasBundle()) return null;
    final dir = await _bundleDir();
    final js = (await File('${dir.path}/map.js').readAsString()).replaceAll('</script', '<\\/script');
    final css = (await File('${dir.path}/map.css').readAsString()).replaceAll('</style', '<\\/style');
    Map<String, dynamic>? target;
    if (bookId != null) {
      for (final b in (data['books'] as List? ?? [])) {
        if (b is Map && b['id'] == bookId) target = Map<String, dynamic>.from(b);
      }
    }
    final payload = jsonEncode({...data, 'target': target, 'dark': dark}).replaceAll('</', '<\\/');
    return '<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8">'
        '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
        '<style>$css</style></head><body><div id="app"></div>'
        '<script>window.FILENEST_MAP=$payload;</script><script>$js</script></body></html>';
  }

  /// The same page as a file (Windows opens it from disk).
  Future<String?> writeHtml({int? bookId, bool dark = false}) async {
    final html = await buildHtml(bookId: bookId, dark: dark);
    if (html == null) return null;
    final f = File('${(await _bundleDir()).path}/index.html');
    await f.writeAsString(html);
    return f.path;
  }
}
