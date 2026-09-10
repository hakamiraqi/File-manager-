/// A user row as the admin panel sees it — mirrors `AdminUserResource`
/// (backend README 23.x admin API), a superset of what a user sees of
/// themselves via [FileNestUser].
class AdminUser {
  final int id;
  final String name;
  final String email;
  final String role;
  final bool isActive;
  final bool isMasterAdmin;
  final int storageUsedBytes;
  final int storageQuotaBytes;
  final bool canManageAppearance;
  final bool canManageUsers;
  final bool canManageTranslations;
  final bool canViewStatistics;
  final bool canManageSettings;

  const AdminUser({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
    required this.isActive,
    required this.isMasterAdmin,
    required this.storageUsedBytes,
    required this.storageQuotaBytes,
    required this.canManageAppearance,
    required this.canManageUsers,
    required this.canManageTranslations,
    required this.canViewStatistics,
    required this.canManageSettings,
  });

  factory AdminUser.fromJson(Map<String, dynamic> json) {
    return AdminUser(
      id: json['id'] as int,
      name: json['name'] as String,
      email: json['email'] as String,
      role: json['role'] as String,
      isActive: json['is_active'] as bool? ?? true,
      isMasterAdmin: json['is_master_admin'] as bool? ?? false,
      storageUsedBytes: (json['storage_used_bytes'] as num?)?.toInt() ?? 0,
      storageQuotaBytes: (json['storage_quota_bytes'] as num?)?.toInt() ?? 0,
      canManageAppearance: json['can_manage_appearance'] as bool? ?? false,
      canManageUsers: json['can_manage_users'] as bool? ?? false,
      canManageTranslations: json['can_manage_translations'] as bool? ?? false,
      canViewStatistics: json['can_view_statistics'] as bool? ?? false,
      canManageSettings: json['can_manage_settings'] as bool? ?? false,
    );
  }
}

/// A page of [AdminUser] plus the trimmed pagination metadata the admin
/// API returns (current_page/last_page/total — no per_page/links).
class AdminUserPage {
  final List<AdminUser> users;
  final int currentPage;
  final int lastPage;
  final int total;

  const AdminUserPage({
    required this.users,
    required this.currentPage,
    required this.lastPage,
    required this.total,
  });

  factory AdminUserPage.fromJson(Map<String, dynamic> json) {
    final meta = json['meta'] as Map<String, dynamic>;
    return AdminUserPage(
      users: (json['users'] as List).map((e) => AdminUser.fromJson(e as Map<String, dynamic>)).toList(),
      currentPage: meta['current_page'] as int,
      lastPage: meta['last_page'] as int,
      total: meta['total'] as int,
    );
  }
}
