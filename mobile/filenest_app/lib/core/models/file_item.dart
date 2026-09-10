/// One available resolution of a transcoded video, e.g. 480p — see the
/// backend's VideoTranscoder service and API section 23.12.
class VideoQualityOption {
  final int height;
  final String src;

  const VideoQualityOption({required this.height, required this.src});

  factory VideoQualityOption.fromJson(Map<String, dynamic> json) {
    return VideoQualityOption(
      height: (json['height'] as num).toInt(),
      src: json['src'] as String,
    );
  }
}

class FileItem {
  final int id;
  final String name;
  final int? folderId;
  final String? extension;
  final String? mimeType;
  final int size;
  final String humanSize;
  final bool isIndexed;
  final bool isPreviewable;
  final bool hasThumbnail;
  final String? contentSnippet;
  final String downloadUrl;
  final String previewUrl;
  final String? thumbnailUrl;
  final List<VideoQualityOption> videoQualities;
  final String? subtitlesUrl;

  FileItem({
    required this.id,
    required this.name,
    required this.folderId,
    required this.extension,
    required this.mimeType,
    required this.size,
    required this.humanSize,
    required this.isIndexed,
    required this.isPreviewable,
    required this.hasThumbnail,
    required this.contentSnippet,
    required this.downloadUrl,
    required this.previewUrl,
    required this.thumbnailUrl,
    this.videoQualities = const [],
    this.subtitlesUrl,
  });

  bool get isVideo => mimeType?.startsWith('video/') ?? false;

  bool get isAudio => mimeType?.startsWith('audio/') ?? false;

  bool get isPlayableMedia => isVideo || isAudio;

  factory FileItem.fromJson(Map<String, dynamic> json) {
    return FileItem(
      id: json['id'] as int,
      name: json['name'] as String,
      folderId: json['folder_id'] as int?,
      extension: json['extension'] as String?,
      mimeType: json['mime_type'] as String?,
      size: (json['size'] as num?)?.toInt() ?? 0,
      humanSize: json['human_size'] as String? ?? '',
      isIndexed: json['is_indexed'] as bool? ?? false,
      isPreviewable: json['is_previewable'] as bool? ?? false,
      hasThumbnail: json['has_thumbnail'] as bool? ?? false,
      contentSnippet: json['content_snippet'] as String?,
      downloadUrl: json['download_url'] as String? ?? '',
      previewUrl: json['preview_url'] as String? ?? '',
      thumbnailUrl: json['thumbnail_url'] as String?,
      videoQualities: (json['video_qualities'] as List?)
              ?.map((e) => VideoQualityOption.fromJson(e as Map<String, dynamic>))
              .toList() ??
          const [],
      subtitlesUrl: json['subtitles_url'] as String?,
    );
  }
}
