import 'package:flutter/material.dart';

import '../../models/analytics_data.dart';

const _palette = [
  Color(0xFF4F46E5),
  Color(0xFF059669),
  Color(0xFFD97706),
  Color(0xFF0284C7),
  Color(0xFFE11D48),
  Color(0xFF7C3AED),
];

/// A minimal multi-series line chart drawn with CustomPainter — the admin
/// Analytics page only ever needs a simple trend line per tracked action,
/// so a small painter here avoids pulling in a full charting package for
/// one screen. Mirrors Analytics.vue's Chart.js line chart in spirit
/// (same series/colors/legend), not pixel-for-pixel.
class AnalyticsLineChart extends StatelessWidget {
  const AnalyticsLineChart({super.key, required this.data});

  final AnalyticsData data;

  @override
  Widget build(BuildContext context) {
    final maxValue = data.series
        .expand((s) => s.data)
        .fold<int>(0, (max, v) => v > max ? v : max);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          height: 220,
          child: CustomPaint(
            size: Size.infinite,
            painter: _LineChartPainter(
              series: data.series,
              maxValue: maxValue == 0 ? 1 : maxValue,
              gridColor: Theme.of(context).dividerColor,
              labelColor: Theme.of(context).textTheme.bodySmall?.color ?? Colors.grey,
            ),
          ),
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            Text(data.labels.first, style: const TextStyle(fontSize: 11, color: Colors.grey)),
            const Spacer(),
            Text(data.labels.last, style: const TextStyle(fontSize: 11, color: Colors.grey)),
          ],
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 12,
          runSpacing: 6,
          children: data.series.asMap().entries.map((entry) {
            final color = _palette[entry.key % _palette.length];
            return Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(width: 10, height: 10, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
                const SizedBox(width: 4),
                Text(entry.value.label, style: const TextStyle(fontSize: 12)),
              ],
            );
          }).toList(),
        ),
      ],
    );
  }
}

class _LineChartPainter extends CustomPainter {
  _LineChartPainter({required this.series, required this.maxValue, required this.gridColor, required this.labelColor});

  final List<AnalyticsSeries> series;
  final int maxValue;
  final Color gridColor;
  final Color labelColor;

  @override
  void paint(Canvas canvas, Size size) {
    const leftPadding = 32.0;
    const bottomPadding = 4.0;
    final chartWidth = size.width - leftPadding;
    final chartHeight = size.height - bottomPadding;

    final gridPaint = Paint()
      ..color = gridColor
      ..strokeWidth = 1;

    // Horizontal gridlines + y-axis labels at 0%, 50%, 100% of maxValue.
    for (final fraction in [0.0, 0.5, 1.0]) {
      final y = chartHeight - (chartHeight * fraction);
      canvas.drawLine(Offset(leftPadding, y), Offset(size.width, y), gridPaint);

      final label = (maxValue * fraction).round().toString();
      final painter = TextPainter(
        text: TextSpan(text: label, style: TextStyle(fontSize: 10, color: labelColor)),
        textDirection: TextDirection.ltr,
      )..layout();
      painter.paint(canvas, Offset(0, y - painter.height / 2));
    }

    if (series.isEmpty || series.first.data.isEmpty) return;

    final pointCount = series.first.data.length;
    final stepX = pointCount > 1 ? chartWidth / (pointCount - 1) : 0.0;

    for (var s = 0; s < series.length; s++) {
      final color = _palette[s % _palette.length];
      final linePaint = Paint()
        ..color = color
        ..strokeWidth = 2.5
        ..style = PaintingStyle.stroke
        ..strokeJoin = StrokeJoin.round;

      final path = Path();
      final points = series[s].data;
      for (var i = 0; i < points.length; i++) {
        final x = leftPadding + stepX * i;
        final y = chartHeight - (chartHeight * (points[i] / maxValue));
        if (i == 0) {
          path.moveTo(x, y);
        } else {
          path.lineTo(x, y);
        }
      }
      canvas.drawPath(path, linePaint);
    }
  }

  @override
  bool shouldRepaint(covariant _LineChartPainter oldDelegate) {
    return oldDelegate.series != series || oldDelegate.maxValue != maxValue;
  }
}
