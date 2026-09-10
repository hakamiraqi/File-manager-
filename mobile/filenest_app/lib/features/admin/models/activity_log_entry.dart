/// One row of the admin activity log.
class ActivityLogEntry {
  final int id;
  final String action;
  final String? description;
  final String? ipAddress;
  final String? userName;
  final DateTime createdAt;

  const ActivityLogEntry({
    required this.id,
    required this.action,
    required this.description,
    required this.ipAddress,
    required this.userName,
    required this.createdAt,
  });

  factory ActivityLogEntry.fromJson(Map<String, dynamic> json) {
    return ActivityLogEntry(
      id: json['id'] as int,
      action: json['action'] as String,
      description: json['description'] as String?,
      ipAddress: json['ip_address'] as String?,
      userName: (json['user'] as Map<String, dynamic>?)?['name'] as String?,
      createdAt: DateTime.parse(json['created_at'] as String),
    );
  }
}

class ActivityLogPage {
  final List<ActivityLogEntry> logs;
  final int currentPage;
  final int lastPage;
  final int total;

  const ActivityLogPage({required this.logs, required this.currentPage, required this.lastPage, required this.total});

  factory ActivityLogPage.fromJson(Map<String, dynamic> json) {
    final meta = json['meta'] as Map<String, dynamic>;
    return ActivityLogPage(
      logs: (json['logs'] as List).map((e) => ActivityLogEntry.fromJson(e as Map<String, dynamic>)).toList(),
      currentPage: meta['current_page'] as int,
      lastPage: meta['last_page'] as int,
      total: meta['total'] as int,
    );
  }
}
