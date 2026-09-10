/// One comment on a video, optionally anchored to a timestamp — mirrors
/// `GET /files/{id}/comments` (backend README 27.5 / 23.13).
class VideoComment {
  final int id;
  final int userId;
  final String? userName;
  final String body;
  final int? timestampSeconds;
  final DateTime createdAt;

  const VideoComment({
    required this.id,
    required this.userId,
    required this.userName,
    required this.body,
    required this.timestampSeconds,
    required this.createdAt,
  });

  factory VideoComment.fromJson(Map<String, dynamic> json) {
    return VideoComment(
      id: json['id'] as int,
      userId: json['user_id'] as int,
      userName: (json['user'] as Map<String, dynamic>?)?['name'] as String?,
      body: json['body'] as String,
      timestampSeconds: json['timestamp_seconds'] as int?,
      createdAt: DateTime.parse(json['created_at'] as String),
    );
  }
}
