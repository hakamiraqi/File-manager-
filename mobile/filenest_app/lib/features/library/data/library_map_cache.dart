import 'dart:convert';
import 'dart:io';

import 'package:path_provider/path_provider.dart';

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
      if (await PreferencesStorage.instance.readOfflineMap()) await refresh();
    } catch (_) {}
  }
}
