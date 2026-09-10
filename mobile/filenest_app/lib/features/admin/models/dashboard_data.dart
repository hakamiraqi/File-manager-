import 'activity_log_entry.dart';

class DashboardStats {
  final int users;
  final int activeUsers;
  final int files;
  final int folders;
  final int shares;
  final int storageUsed;

  const DashboardStats({
    required this.users,
    required this.activeUsers,
    required this.files,
    required this.folders,
    required this.shares,
    required this.storageUsed,
  });

  factory DashboardStats.fromJson(Map<String, dynamic> json) {
    return DashboardStats(
      users: json['users'] as int,
      activeUsers: json['active_users'] as int,
      files: json['files'] as int,
      folders: json['folders'] as int,
      shares: json['shares'] as int,
      storageUsed: (json['storage_used'] as num).toInt(),
    );
  }
}

class TopUser {
  final int id;
  final String name;
  final String email;
  final int storageUsedBytes;
  final int storageQuotaBytes;

  const TopUser({
    required this.id,
    required this.name,
    required this.email,
    required this.storageUsedBytes,
    required this.storageQuotaBytes,
  });

  factory TopUser.fromJson(Map<String, dynamic> json) {
    return TopUser(
      id: json['id'] as int,
      name: json['name'] as String,
      email: json['email'] as String,
      storageUsedBytes: (json['storage_used_bytes'] as num?)?.toInt() ?? 0,
      storageQuotaBytes: (json['storage_quota_bytes'] as num?)?.toInt() ?? 0,
    );
  }
}

class DashboardData {
  final DashboardStats stats;
  final List<ActivityLogEntry> recentActivity;
  final List<TopUser> topUsers;

  const DashboardData({required this.stats, required this.recentActivity, required this.topUsers});

  factory DashboardData.fromJson(Map<String, dynamic> json) {
    return DashboardData(
      stats: DashboardStats.fromJson(json['stats'] as Map<String, dynamic>),
      recentActivity: (json['recent_activity'] as List)
          .map((e) => ActivityLogEntry.fromJson(e as Map<String, dynamic>))
          .toList(),
      topUsers: (json['top_users'] as List).map((e) => TopUser.fromJson(e as Map<String, dynamic>)).toList(),
    );
  }
}
