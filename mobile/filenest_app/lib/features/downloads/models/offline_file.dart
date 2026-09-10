/// One entry in the offline-downloads manifest — a file that has been
/// saved to local storage and can be opened again with zero network
/// access, until explicitly deleted from the Downloads tab.
///
/// [quizQuestions] is a best-effort snapshot of the video's interactive
/// questions (raw `GET /files/{id}/questions` "questions" array, see
/// backend README 23.13/27) taken at download time — null for a
/// non-video file, for a video with the feature disabled, or if the
/// fetch simply failed; either way the download itself still succeeds.
/// It lets the quiz run fully offline, with `answered_choice_id`
/// updated locally as the user answers (see OfflineDownloadService).
class OfflineFileRecord {
  final int fileId;
  final String name;
  final String localPath;
  final int size;
  final String? mimeType;
  final DateTime downloadedAt;
  final List<Map<String, dynamic>>? quizQuestions;

  const OfflineFileRecord({
    required this.fileId,
    required this.name,
    required this.localPath,
    required this.size,
    required this.mimeType,
    required this.downloadedAt,
    this.quizQuestions,
  });

  bool get isVideo => mimeType?.startsWith('video/') ?? false;

  bool get hasQuiz => quizQuestions != null && quizQuestions!.isNotEmpty;

  OfflineFileRecord copyWith({List<Map<String, dynamic>>? quizQuestions}) {
    return OfflineFileRecord(
      fileId: fileId,
      name: name,
      localPath: localPath,
      size: size,
      mimeType: mimeType,
      downloadedAt: downloadedAt,
      quizQuestions: quizQuestions ?? this.quizQuestions,
    );
  }

  factory OfflineFileRecord.fromJson(Map<String, dynamic> json) {
    return OfflineFileRecord(
      fileId: json['file_id'] as int,
      name: json['name'] as String,
      localPath: json['local_path'] as String,
      size: (json['size'] as num?)?.toInt() ?? 0,
      mimeType: json['mime_type'] as String?,
      downloadedAt: DateTime.parse(json['downloaded_at'] as String),
      quizQuestions: (json['quiz_questions'] as List?)?.map((e) => Map<String, dynamic>.from(e as Map)).toList(),
    );
  }

  Map<String, dynamic> toJson() => {
        'file_id': fileId,
        'name': name,
        'local_path': localPath,
        'size': size,
        'mime_type': mimeType,
        'downloaded_at': downloadedAt.toIso8601String(),
        'quiz_questions': quizQuestions,
      };
}
