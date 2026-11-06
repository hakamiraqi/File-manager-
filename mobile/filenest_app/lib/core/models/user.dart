/// Which admin sections the current user's account can reach — mirrors
/// HandleInertiaRequests' `permissions.admin.*` on the web, but as a
/// mobile-side model here. Null (via [FileNestUser.admin]) for a non-admin.
class AdminCapabilities {
  final bool appearance;
  final bool users;
  final bool translations;
  final bool statistics;
  final bool settings;
  final bool settingsPage;

  const AdminCapabilities({
    required this.appearance,
    required this.users,
    required this.translations,
    required this.statistics,
    required this.settings,
    required this.settingsPage,
  });

  factory AdminCapabilities.fromJson(Map<String, dynamic> json) {
    return AdminCapabilities(
      appearance: json['appearance'] as bool? ?? false,
      users: json['users'] as bool? ?? false,
      translations: json['translations'] as bool? ?? false,
      statistics: json['statistics'] as bool? ?? false,
      settings: json['settings'] as bool? ?? false,
      settingsPage: json['settings_page'] as bool? ?? false,
    );
  }
}

/// Per-role capabilities (RolePermission), not admin-only — mirrors
/// HandleInertiaRequests' `permissions.manage_questions`/`permissions.comment`
/// on the web, used to hide the interactive-video authoring UI and the
/// comment box for a role that lacks them.
class RolePermissions {
  final bool manageQuestions;
  final bool comment;

  const RolePermissions({required this.manageQuestions, required this.comment});

  factory RolePermissions.fromJson(Map<String, dynamic>? json) {
    return RolePermissions(
      manageQuestions: json?['manage_questions'] as bool? ?? false,
      comment: json?['comment'] as bool? ?? false,
    );
  }
}

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
  final bool isMasterAdmin;
  final AdminCapabilities? admin;
  final RolePermissions permissions;
  final String? avatarUrl;
  final bool manageLibrary;
  final bool manageContact;

  bool get isAdmin => admin != null;

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
    required this.isMasterAdmin,
    required this.admin,
    required this.permissions,
    this.avatarUrl,
    this.manageLibrary = false,
    this.manageContact = false,
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
      isMasterAdmin: json['is_master_admin'] as bool? ?? false,
      admin: json['admin'] is Map
          ? AdminCapabilities.fromJson(json['admin'] as Map<String, dynamic>)
          : null,
      permissions: RolePermissions.fromJson(json['permissions'] as Map<String, dynamic>?),
      avatarUrl: json['avatar_url'] as String?,
      manageLibrary: json['manage_library'] as bool? ?? false,
      manageContact: json['manage_contact'] as bool? ?? false,
    );
  }
}
