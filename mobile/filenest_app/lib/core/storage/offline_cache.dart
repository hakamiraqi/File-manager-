import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:path_provider/path_provider.dart';

import 'preferences_storage.dart';

/// Lists kept on the device for reading without internet (2026-11-11, by request): the theses
/// list and every folder opened (a thesis's files). Written only while «حفظ الملفات والرسائل
/// المفتوحة» is on; read whenever the server cannot be reached.
class OfflineCache {
  OfflineCache._();
  static final OfflineCache instance = OfflineCache._();

  Future<File> _file(String key) async {
    final dir = Directory('${(await getApplicationSupportDirectory()).path}/offline_cache');
    if (!await dir.exists()) await dir.create(recursive: true);
    // Readable start, plus a checksum of the whole key so two Arabic searches never share a file.
    var h = 0x811c9dc5;
    for (final u in key.codeUnits) {
      h = ((h ^ u) * 0x01000193) & 0xffffffff;
    }
    final safe = key.replaceAll(RegExp(r'[^a-zA-Z0-9_-]'), '_');
    return File('${dir.path}/${safe.length > 80 ? safe.substring(0, 80) : safe}_${h.toRadixString(16)}.json');
  }

  Future<void> put(String key, Object? data) async {
    try {
      if (!await PreferencesStorage.instance.readKeepOpenedFiles()) return;
      await (await _file(key)).writeAsString(jsonEncode(data));
    } catch (_) {}
  }

  Future<dynamic> get(String key) async {
    try {
      final f = await _file(key);
      if (await f.exists()) return jsonDecode(await f.readAsString());
    } catch (_) {}
    return null;
  }

  /// No answer from the server at all (no internet, server unreachable), as opposed to an
  /// answer saying no: only then is the saved copy shown.
  static bool isOffline(Object e) =>
      e is DioException &&
      (e.response == null ||
          e.type == DioExceptionType.connectionError ||
          e.type == DioExceptionType.connectionTimeout ||
          e.type == DioExceptionType.receiveTimeout ||
          e.type == DioExceptionType.sendTimeout);
}
