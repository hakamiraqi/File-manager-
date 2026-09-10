import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../../../core/models/file_item.dart';
import '../data/offline_download_service.dart';
import '../models/offline_file.dart';

/// UI-facing state for the offline-downloads feature: which files are
/// downloaded, which are mid-download and at what percentage, wired to
/// [OfflineDownloadService] for the actual disk work. Registered once at
/// the app root (see app/app.dart) so any screen — the file browser's
/// download icon, the Downloads tab itself — shares the same state.
class DownloadsProvider extends ChangeNotifier {
  final _service = OfflineDownloadService.instance;
  final Map<int, double> _progress = {};

  bool _loading = true;
  bool get loading => _loading;

  /// Most recently downloaded first.
  List<OfflineFileRecord> get items => _service.manifest.reversed.toList();

  Future<void> load() async {
    await _service.ensureLoaded();
    _loading = false;
    notifyListeners();
  }

  bool isDownloaded(int fileId) => _service.isDownloaded(fileId);

  bool isDownloading(int fileId) => _progress.containsKey(fileId);

  double? progressFor(int fileId) => _progress[fileId];

  Future<void> download(FileItem file) async {
    if (isDownloaded(file.id) || isDownloading(file.id)) return;

    _progress[file.id] = 0;
    notifyListeners();

    try {
      await _service.download(
        file,
        onProgress: (progress) {
          _progress[file.id] = progress;
          notifyListeners();
        },
      );
    } on DioException catch (e) {
      // A user-initiated cancel() surfaces here as a cancel-type
      // exception — that's an expected outcome, not a failure to report.
      if (e.type != DioExceptionType.cancel) rethrow;
    } finally {
      _progress.remove(file.id);
      notifyListeners();
    }
  }

  void cancelDownload(int fileId) => _service.cancel(fileId);

  Future<void> delete(int fileId) async {
    await _service.delete(fileId);
    notifyListeners();
  }

  Future<void> openOffline(int fileId) => _service.openOffline(fileId);
}
