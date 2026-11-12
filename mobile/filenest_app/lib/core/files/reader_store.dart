import 'dart:convert';
import 'dart:io';

import 'package:path_provider/path_provider.dart';

import '../network/api_client.dart';

/// What one reader keeps on one PDF: the page they stopped at, bookmarks and notes
/// (highlights, underlines, sticky notes…). The same shape as the website's reader, so
/// both show the same notes: positions are on a board 1000 wide, from the page's top-left.
class ReaderData {
  int lastPage = 1;
  List<Map<String, dynamic>> bookmarks = [];
  List<Map<String, dynamic>> annotations = [];

  /// Notes deleted on this device since the last sync (so a merge does not bring them back).
  Set<String> deleted = {};

  /// Changed on this device and not yet saved on the server.
  bool dirty = false;

  Map<String, dynamic> toJson() => {
        'last_page': lastPage,
        'bookmarks': bookmarks,
        'annotations': annotations,
        'deleted': deleted.toList(),
        'dirty': dirty,
      };

  static ReaderData fromJson(Map<String, dynamic> j) => ReaderData()
    ..lastPage = (j['last_page'] as num?)?.toInt() ?? 1
    ..bookmarks = [for (final b in (j['bookmarks'] as List? ?? const [])) Map<String, dynamic>.from(b as Map)]
    ..annotations = [for (final a in (j['annotations'] as List? ?? const [])) Map<String, dynamic>.from(a as Map)]
    ..deleted = {for (final d in (j['deleted'] as List? ?? const [])) '$d'}
    ..dirty = j['dirty'] == true;
}

/// Keeps [ReaderData] on the device (2026-11-12: notes work without internet) and, for a file
/// of the library ([fileId]), brings it in line with the server whenever there is a connection.
class ReaderStore {
  ReaderStore({required this.path, this.fileId});

  final String path;
  final int? fileId;

  Future<File> _file() async {
    final docs = await getApplicationDocumentsDirectory();
    final dir = Directory('${docs.path}/reader_state');
    if (!await dir.exists()) await dir.create(recursive: true);
    final key = fileId != null ? 'file-$fileId' : 'path-${path.hashCode.toUnsigned(32).toRadixString(16)}';
    return File('${dir.path}/$key.json');
  }

  Future<ReaderData> load() async {
    try {
      final f = await _file();
      if (await f.exists()) return ReaderData.fromJson(jsonDecode(await f.readAsString()) as Map<String, dynamic>);
    } catch (_) {
      // A broken file starts fresh.
    }
    return ReaderData();
  }

  Future<void> save(ReaderData d) async {
    try {
      await (await _file()).writeAsString(jsonEncode(d.toJson()), flush: true);
    } catch (_) {
      // Reading goes on; the next change tries again.
    }
  }

  /// Brings [d] and the server in line. Returns true when it reached the server.
  Future<bool> sync(ReaderData d) async {
    if (fileId == null) return false;
    try {
      final r = await ApiClient.instance.dio.get('/files/$fileId/reader-state');
      final s = Map<String, dynamic>.from((r.data as Map)['state'] as Map? ?? const {});
      final serverNotes = [for (final a in (s['annotations'] as List? ?? const [])) Map<String, dynamic>.from(a as Map)];
      final serverMarks = [for (final b in (s['bookmarks'] as List? ?? const [])) Map<String, dynamic>.from(b as Map)];
      if (!d.dirty) {
        d.annotations = serverNotes;
        d.bookmarks = serverMarks;
        final page = (s['last_page'] as num?)?.toInt() ?? 1;
        if (page > 1) d.lastPage = page;
        await save(d);
        return true;
      }
      // Changed here while away: the server's notes, without what was deleted here, plus what is new here.
      final ids = {for (final a in serverNotes) '${a['id']}'};
      d.annotations = [
        ...serverNotes.where((a) => !d.deleted.contains('${a['id']}')),
        ...d.annotations.where((a) => !ids.contains('${a['id']}')),
      ];
      final pages = {for (final b in d.bookmarks) (b['page'] as num).toInt()};
      d.bookmarks = [...d.bookmarks, ...serverMarks.where((b) => !pages.contains((b['page'] as num).toInt()) && !d.deleted.contains('bookmark-${b['page']}'))]
        ..sort((a, b) => (a['page'] as num).compareTo(b['page'] as num));
      await ApiClient.instance.dio.put('/files/$fileId/reader-state', data: {
        'last_page': d.lastPage,
        'bookmarks': d.bookmarks,
        'annotations': d.annotations,
      });
      d.dirty = false;
      d.deleted = {};
      await save(d);
      return true;
    } catch (_) {
      return false; // offline: kept on the device, sent next time
    }
  }
}
