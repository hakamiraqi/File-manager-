/// One subtitle track available for a video, as listed by
/// `GET /files/{file}/subtitles` (backend API section 23.12).
class SubtitleTrack {
  final int id;
  final String language;
  final String label;

  const SubtitleTrack({required this.id, required this.language, required this.label});

  factory SubtitleTrack.fromJson(Map<String, dynamic> json) {
    return SubtitleTrack(
      id: json['id'] as int,
      language: json['language'] as String,
      label: json['label'] as String,
    );
  }
}
