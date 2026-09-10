class FileNestUser {
  final int id;
  final String name;
  final String email;
  final String role;
  final String? locale;
  final int storageUsedBytes;
  final int storageQuotaBytes;
  final double storageUsagePercent;
  final bool twoFactorEnabled;

  FileNestUser({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
    required this.locale,
    required this.storageUsedBytes,
    required this.storageQuotaBytes,
    required this.storageUsagePercent,
    required this.twoFactorEnabled,
  });

  factory FileNestUser.fromJson(Map<String, dynamic> json) {
    return FileNestUser(
      id: json['id'] as int,
      name: json['name'] as String,
      email: json['email'] as String,
      role: json['role'] as String? ?? 'user',
      locale: json['locale'] as String?,
      storageUsedBytes: (json['storage_used_bytes'] as num?)?.toInt() ?? 0,
      storageQuotaBytes: (json['storage_quota_bytes'] as num?)?.toInt() ?? 0,
      storageUsagePercent:
          (json['storage_usage_percent'] as num?)?.toDouble() ?? 0,
      twoFactorEnabled: json['two_factor_enabled'] as bool? ?? false,
    );
  }
}
