import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

import '../../../core/models/file_item.dart';
import '../../files/data/file_repository.dart';
import '../models/offline_file.dart';

/// Persists downloaded files to app-private local storage (not the OS
/// temp/cache directory, so they survive as long as the app is installed)
/// plus a small JSON manifest describing what's been saved, so a file
/// downloaded once can be reopened later with **zero** network access —
/// the actual "work without internet" part of the offline-downloads
/// feature. Progress reporting and cancellation are the caller's
/// responsibility to surface (see DownloadsProvider); this class only
/// owns the disk state.
class OfflineDownloadService {
  OfflineDownloadService._();
  static final OfflineDownloadService instance = OfflineDownloadService._();

  final FileRepository _fileRepository = FileRepository();
  final Map<int, CancelToken> _activeCancelTokens = {};

  List<OfflineFileRecord> _manifest = [];
  bool _loaded = false;

  List<OfflineFileRecord> get manifest => List.unmodifiable(_manifest);

  Future<void> ensureLoaded() async {
    if (_loaded) return;
    _manifest = await _readManifest();
    _loaded = true;
  }

  bool isDownloaded(int fileId) => _manifest.any((r) => r.fileId == fileId);

  OfflineFileRecord? recordFor(int fileId) {
    for (final record in _manifest) {
      if (record.fileId == fileId) return record;
    }
    return null;
  }

  Future<OfflineFileRecord> download(FileItem file, {void Function(double progress)? onProgress}) async {
    await ensureLoaded();

    final dir = await _filesDirectory();
    final safeName = file.name.replaceAll(RegExp(r'[\\/:*?"<>|]'), '_');
    final localPath = '${dir.path}/${file.id}_$safeName';

    final cancelToken = CancelToken();
    _activeCancelTokens[file.id] = cancelToken;

    try {
      await _fileRepository.downloadFile(
        file,
        localPath,
        onProgress: onProgress,
        cancelToken: cancelToken,
      );
    } finally {
      _activeCancelTokens.remove(file.id);
    }

    final record = OfflineFileRecord(
      fileId: file.id,
      name: file.name,
      localPath: localPath,
      size: file.size,
      mimeType: file.mimeType,
      downloadedAt: DateTime.now(),
    );

    _manifest.removeWhere((r) => r.fileId == file.id);
    _manifest.add(record);
    await _writeManifest();

    return record;
  }

  /// Cancels an in-progress download for [fileId], if any — a no-op
  /// otherwise. The cancelled Dio call throws a DioException(cancel),
  /// which the caller (DownloadsProvider) treats as "not an error".
  void cancel(int fileId) {
    _activeCancelTokens[fileId]?.cancel('cancelled by user');
  }

  Future<void> delete(int fileId) async {
    await ensureLoaded();

    final record = recordFor(fileId);
    if (record == null) return;

    try {
      final file = File(record.localPath);
      if (await file.exists()) await file.delete();
    } catch (_) {
      // The manifest entry is removed either way — a file already missing
      // from disk shouldn't leave a permanently "downloaded" ghost entry.
    }

    _manifest.removeWhere((r) => r.fileId == fileId);
    await _writeManifest();
  }

  Future<void> openOffline(int fileId) async {
    final record = recordFor(fileId);
    if (record == null) return;
    await OpenFilex.open(record.localPath);
  }

  Future<Directory> _filesDirectory() async {
    final docs = await getApplicationDocumentsDirectory();
    final dir = Directory('${docs.path}/offline_files');
    if (!await dir.exists()) {
      await dir.create(recursive: true);
    }
    return dir;
  }

  Future<File> _manifestFile() async {
    final docs = await getApplicationDocumentsDirectory();
    return File('${docs.path}/offline_manifest.json');
  }

  Future<List<OfflineFileRecord>> _readManifest() async {
    try {
      final file = await _manifestFile();
      if (!await file.exists()) return [];

      final raw = await file.readAsString();
      final decoded = jsonDecode(raw) as List;
      return decoded.map((e) => OfflineFileRecord.fromJson(e as Map<String, dynamic>)).toList();
    } catch (_) {
      // A corrupt or unreadable manifest just means "nothing downloaded
      // yet" instead of crashing the Downloads tab.
      return [];
    }
  }

  Future<void> _writeManifest() async {
    final file = await _manifestFile();
    await file.writeAsString(jsonEncode(_manifest.map((r) => r.toJson()).toList()));
  }
}
