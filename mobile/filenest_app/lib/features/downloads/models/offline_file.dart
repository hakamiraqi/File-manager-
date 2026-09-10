/// One entry in the offline-downloads manifest — a file that has been
/// saved to local storage and can be opened again with zero network
/// access, until explicitly deleted from the Downloads tab.
class OfflineFileRecord {
  final int fileId;
  final String name;
  final String localPath;
  final int size;
  final String? mimeType;
  final DateTime downloadedAt;

  const OfflineFileRecord({
    required this.fileId,
    required this.name,
    required this.localPath,
    required this.size,
    required this.mimeType,
    required this.downloadedAt,
  });

  factory OfflineFileRecord.fromJson(Map<String, dynamic> json) {
    return OfflineFileRecord(
      fileId: json['file_id'] as int,
      name: json['name'] as String,
      localPath: json['local_path'] as String,
      size: (json['size'] as num?)?.toInt() ?? 0,
      mimeType: json['mime_type'] as String?,
      downloadedAt: DateTime.parse(json['downloaded_at'] as String),
    );
  }

  Map<String, dynamic> toJson() => {
        'file_id': fileId,
        'name': name,
        'local_path': localPath,
        'size': size,
        'mime_type': mimeType,
        'downloaded_at': downloadedAt.toIso8601String(),
      };
}
