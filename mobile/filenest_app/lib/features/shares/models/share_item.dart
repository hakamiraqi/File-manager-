class ShareItem {
  final int id;
  final String shareableType;
  final int shareableId;
  final String? shareableName;
  final String url;
  final bool isPasswordProtected;
  final bool allowDownload;
  final bool oneTimeDownload;
  final int? maxDownloads;
  final int downloadCount;
  final bool isExpired;
  final bool isRevoked;

  ShareItem({
    required this.id,
    required this.shareableType,
    required this.shareableId,
    required this.shareableName,
    required this.url,
    required this.isPasswordProtected,
    required this.allowDownload,
    required this.oneTimeDownload,
    required this.maxDownloads,
    required this.downloadCount,
    required this.isExpired,
    required this.isRevoked,
  });

  factory ShareItem.fromJson(Map<String, dynamic> json) {
    return ShareItem(
      id: json['id'] as int,
      shareableType: json['shareable_type'] as String,
      shareableId: json['shareable_id'] as int,
      shareableName: json['shareable_name'] as String?,
      url: json['url'] as String,
      isPasswordProtected: json['is_password_protected'] as bool? ?? false,
      allowDownload: json['allow_download'] as bool? ?? true,
      oneTimeDownload: json['one_time_download'] as bool? ?? false,
      maxDownloads: json['max_downloads'] as int?,
      downloadCount: (json['download_count'] as num?)?.toInt() ?? 0,
      isExpired: json['is_expired'] as bool? ?? false,
      isRevoked: json['is_revoked'] as bool? ?? false,
    );
  }
}
