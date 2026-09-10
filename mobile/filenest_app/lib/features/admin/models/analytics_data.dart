/// One tracked-action time series (e.g. "رفع ملفات" over the selected
/// date range) — backend admin Analytics API.
class AnalyticsSeries {
  final String label;
  final String action;
  final List<int> data;

  const AnalyticsSeries({required this.label, required this.action, required this.data});

  factory AnalyticsSeries.fromJson(Map<String, dynamic> json) {
    return AnalyticsSeries(
      label: json['label'] as String,
      action: json['action'] as String,
      data: (json['data'] as List).map((e) => (e as num).toInt()).toList(),
    );
  }
}

class AnalyticsTotal {
  final String label;
  final int total;

  const AnalyticsTotal({required this.label, required this.total});

  factory AnalyticsTotal.fromJson(Map<String, dynamic> json) {
    return AnalyticsTotal(label: json['label'] as String, total: (json['total'] as num).toInt());
  }
}

class AnalyticsData {
  final int days;
  final List<String> labels;
  final List<AnalyticsSeries> series;
  final List<AnalyticsTotal> totals;

  const AnalyticsData({required this.days, required this.labels, required this.series, required this.totals});

  factory AnalyticsData.fromJson(Map<String, dynamic> json) {
    return AnalyticsData(
      days: json['days'] as int,
      labels: (json['labels'] as List).map((e) => e as String).toList(),
      series: (json['series'] as List).map((e) => AnalyticsSeries.fromJson(e as Map<String, dynamic>)).toList(),
      totals: (json['totals'] as List).map((e) => AnalyticsTotal.fromJson(e as Map<String, dynamic>)).toList(),
    );
  }
}
