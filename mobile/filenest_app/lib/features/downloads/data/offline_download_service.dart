import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

import '../../../core/models/file_item.dart';
import '../../../core/network/api_client.dart';
import '../../files/data/file_repository.dart';
import '../models/offline_file.dart';
import '../models/pending_quiz_answer.dart';

/// Persists downloaded files to app-private local storage (not the OS
/// temp/cache directory, so they survive as long as the app is installed)
/// plus a small JSON manifest describing what's been saved, so a file
/// downloaded once can be reopened later with **zero** network access —
/// the actual "work without internet" part of the offline-downloads
/// feature. Progress reporting and cancellation are the caller's
/// responsibility to surface (see DownloadsProvider); this class only
/// owns the disk state.
///
/// For a video with the interactive-quiz feature on, downloading also
/// snapshots its questions (see [download]) so MediaPlayerScreen can run
/// the same pause-and-answer flow with zero network access. Answers given
/// offline are queued (see [recordOfflineAnswer]) and confirmed against
/// the server's own scoring the next time [syncPendingAnswers] succeeds —
/// the local copy is only ever a provisional stand-in, never the
/// authoritative score.
class OfflineDownloadService {
  OfflineDownloadService._();
  static final OfflineDownloadService instance = OfflineDownloadService._();

  final FileRepository _fileRepository = FileRepository();
  final Map<int, CancelToken> _activeCancelTokens = {};

  List<OfflineFileRecord> _manifest = [];
  List<PendingQuizAnswer> _pendingAnswers = [];
  bool _loaded = false;

  List<OfflineFileRecord> get manifest => List.unmodifiable(_manifest);

  List<PendingQuizAnswer> get pendingAnswers => List.unmodifiable(_pendingAnswers);

  Future<void> ensureLoaded() async {
    if (_loaded) return;
    _manifest = await _readManifest();
    _pendingAnswers = await _readPendingAnswers();
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

    List<Map<String, dynamic>>? quizQuestions;
    if (file.isVideo) {
      try {
        final response = await ApiClient.instance.dio.get('/files/${file.id}/questions');
        quizQuestions =
            ((response.data as Map)['questions'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      } catch (_) {
        // Feature disabled, no questions yet, or the fetch failed — the
        // video download itself already succeeded, so this is a silent
        // "no offline quiz for this one" rather than a failed download.
        quizQuestions = null;
      }
    }

    final record = OfflineFileRecord(
      fileId: file.id,
      name: file.name,
      localPath: localPath,
      size: file.size,
      mimeType: file.mimeType,
      downloadedAt: DateTime.now(),
      quizQuestions: quizQuestions,
    );

    _manifest.removeWhere((r) => r.fileId == file.id);
    _manifest.add(record);
    await _writeManifest();
    if (file.hasThumbnail) {
      _thumbTried.remove(file.id);
      unawaited(thumbnailFor(record));
    }

    return record;
  }

  /// Records an answer given while playing an offline video, so the quiz
  /// can immediately reflect it (score badge, "answered" state) without
  /// any network access, then queues it for [syncPendingAnswers]. When the
  /// cached choice already carries `is_correct` (the downloader had
  /// manage_questions), correctness is known immediately; otherwise it
  /// stays unknown until the answer is confirmed against the server.
  Future<void> recordOfflineAnswer({
    required int fileId,
    required int questionId,
    required int choiceId,
    required bool? isCorrectIfKnown,
  }) async {
    await ensureLoaded();

    final index = _manifest.indexWhere((r) => r.fileId == fileId);
    if (index != -1) {
      final record = _manifest[index];
      final updatedQuestions = (record.quizQuestions ?? []).map((q) {
        if (q['id'] != questionId) return q;
        return {
          ...q,
          'answered_choice_id': choiceId,
          'answered_correctly': isCorrectIfKnown,
        };
      }).toList();
      _manifest[index] = record.copyWith(quizQuestions: updatedQuestions);
      await _writeManifest();
    }

    _pendingAnswers.add(PendingQuizAnswer(
      fileId: fileId,
      questionId: questionId,
      choiceId: choiceId,
      answeredAt: DateTime.now(),
    ));
    await _writePendingAnswers();
  }

  /// Attempts to confirm every answer recorded offline against the real
  /// `POST /questions/{id}/answer` endpoint. Safe to call opportunistically
  /// (app resume, opening the Downloads tab) — a network failure just
  /// leaves an answer queued for the next attempt; a 422 ("already
  /// answered", e.g. the same question was also answered online meanwhile)
  /// is treated as already-synced rather than an error. Returns how many
  /// answers were confirmed.
  Future<int> syncPendingAnswers() async {
    await ensureLoaded();
    if (_pendingAnswers.isEmpty) return 0;

    var syncedCount = 0;
    final remaining = <PendingQuizAnswer>[];

    for (final pending in _pendingAnswers) {
      try {
        final response = await ApiClient.instance.dio.post(
          '/questions/${pending.questionId}/answer',
          data: {'choice_id': pending.choiceId},
        );
        _applyServerAnswer(pending.fileId, pending.questionId, response.data as Map<String, dynamic>);
        syncedCount++;
      } on DioException catch (e) {
        if (e.response?.statusCode == 422) {
          syncedCount++;
        } else {
          remaining.add(pending);
        }
      } catch (_) {
        remaining.add(pending);
      }
    }

    _pendingAnswers = remaining;
    await _writePendingAnswers();
    return syncedCount;
  }

  void _applyServerAnswer(int fileId, int questionId, Map<String, dynamic> answerResponse) {
    final index = _manifest.indexWhere((r) => r.fileId == fileId);
    if (index == -1) return;

    final record = _manifest[index];
    final updatedQuestions = (record.quizQuestions ?? []).map((q) {
      if (q['id'] != questionId) return q;
      return {...q, 'answered_correctly': answerResponse['is_correct']};
    }).toList();
    _manifest[index] = record.copyWith(quizQuestions: updatedQuestions);
  }

  /// Cancels an in-progress download for [fileId], if any — a no-op
  /// otherwise. The cancelled Dio call throws a DioException(cancel),
  /// which the caller (DownloadsProvider) treats as "not an error".
  void cancel(int fileId) {
    _activeCancelTokens[fileId]?.cancel('cancelled by user');
  }

  // Small pictures for the downloads list (2026-11-11, by request), saved next to the files so
  // they show without internet: the server's preview image, fetched once.
  final Set<int> _thumbTried = {};

  Future<File> _thumbFile(int fileId) async => File('${(await _filesDirectory()).path}/thumbs/$fileId.jpg');

  Future<File?> thumbnailFor(OfflineFileRecord record) async {
    final f = await _thumbFile(record.fileId);
    if (await f.exists() && await f.length() > 0) return f;
    if (_thumbTried.contains(record.fileId)) return null;
    _thumbTried.add(record.fileId);
    try {
      await f.parent.create(recursive: true);
      await ApiClient.instance.dio.download('/files/${record.fileId}/thumbnail', f.path);
      if (await f.exists() && await f.length() > 0) return f;
    } catch (_) {}
    try {
      if (await f.exists()) await f.delete();
    } catch (_) {}
    return null;
  }

  Future<void> delete(int fileId) async {
    await ensureLoaded();
    try {
      final thumb = await _thumbFile(fileId);
      if (await thumb.exists()) await thumb.delete();
    } catch (_) {}

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
    _pendingAnswers.removeWhere((p) => p.fileId == fileId);
    await _writeManifest();
    await _writePendingAnswers();
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

  Future<File> _pendingAnswersFile() async {
    final docs = await getApplicationDocumentsDirectory();
    return File('${docs.path}/offline_pending_quiz_answers.json');
  }

  Future<List<PendingQuizAnswer>> _readPendingAnswers() async {
    try {
      final file = await _pendingAnswersFile();
      if (!await file.exists()) return [];

      final raw = await file.readAsString();
      final decoded = jsonDecode(raw) as List;
      return decoded.map((e) => PendingQuizAnswer.fromJson(e as Map<String, dynamic>)).toList();
    } catch (_) {
      return [];
    }
  }

  Future<void> _writePendingAnswers() async {
    final file = await _pendingAnswersFile();
    await file.writeAsString(jsonEncode(_pendingAnswers.map((p) => p.toJson()).toList()));
  }
}
