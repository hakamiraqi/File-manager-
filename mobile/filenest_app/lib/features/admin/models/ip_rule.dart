/// One IP blacklist/whitelist entry (backend `IpRule` model, admin Security
/// page/API).
class IpRule {
  final int id;
  final String type; // 'blacklist' | 'whitelist'
  final String ipOrCidr;
  final String? note;

  const IpRule({required this.id, required this.type, required this.ipOrCidr, required this.note});

  factory IpRule.fromJson(Map<String, dynamic> json) {
    return IpRule(
      id: json['id'] as int,
      type: json['type'] as String,
      ipOrCidr: json['ip_or_cidr'] as String,
      note: json['note'] as String?,
    );
  }
}
