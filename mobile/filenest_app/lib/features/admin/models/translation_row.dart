/// One translation string, base value plus any admin override (backend
/// admin Translations page/API).
class TranslationRow {
  final String key;
  final String base;
  String value;
  bool overridden;

  TranslationRow({required this.key, required this.base, required this.value, required this.overridden});

  factory TranslationRow.fromJson(Map<String, dynamic> json) {
    return TranslationRow(
      key: json['key'] as String,
      base: json['base'] as String? ?? '',
      value: json['value'] as String? ?? '',
      overridden: json['overridden'] as bool? ?? false,
    );
  }
}
