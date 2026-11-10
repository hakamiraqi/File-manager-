import 'package:flutter/material.dart';

import '../../../../app/theme/app_colors.dart';
import '../../../../core/widgets/ui_kit.dart';
import '../../data/library_manage_api.dart';
import 'manage_widgets.dart';
import 'members_screen.dart' show memberTypeLabel;

/// «التقارير»: the library's numbers for a period: loans and returns by month, the most
/// borrowed books, the most active readers, sections, fines and losses.
class LibraryReportsScreen extends StatefulWidget {
  const LibraryReportsScreen({super.key});

  @override
  State<LibraryReportsScreen> createState() => _LibraryReportsScreenState();
}

class _LibraryReportsScreenState extends State<LibraryReportsScreen> {
  Json? _data;
  String? _error;
  DateTimeRange? _range;

  @override
  void initState() {
    super.initState();
    _load();
  }

  String _d(DateTime t) => '${t.year}-${t.month.toString().padLeft(2, '0')}-${t.day.toString().padLeft(2, '0')}';

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final r = await LibraryManageApi.instance.reports(from: _range == null ? null : _d(_range!.start), to: _range == null ? null : _d(_range!.end));
      if (mounted) setState(() => _data = r);
    } catch (e) {
      if (mounted) setState(() => _error = ApiMessage.of(e));
    }
  }

  Future<void> _pickRange() async {
    final f = _data?['filters'] as Map?;
    final from = DateTime.tryParse('${f?['from']}');
    final to = DateTime.tryParse('${f?['to']}');
    final r = await showDateRangePicker(
      context: context,
      firstDate: DateTime(2020),
      lastDate: DateTime.now().add(const Duration(days: 1)),
      initialDateRange: from != null && to != null ? DateTimeRange(start: from, end: to) : null,
    );
    if (r == null) return;
    setState(() {
      _range = r;
      _data = null;
    });
    _load();
  }

  Widget _bars(List<(String, num)> rows, Color color) {
    final max = rows.fold<num>(1, (m, r) => r.$2 > m ? r.$2 : m);
    return Column(children: [
      for (final r in rows)
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
          child: Row(children: [
            Expanded(flex: 4, child: Text(r.$1, maxLines: 1, overflow: TextOverflow.ellipsis)),
            Expanded(
              flex: 5,
              child: ClipRRect(
                borderRadius: BorderRadius.circular(6),
                child: LinearProgressIndicator(value: r.$2 / max, minHeight: 10, color: color, backgroundColor: color.withOpacity(0.12)),
              ),
            ),
            SizedBox(width: 44, child: Text('${r.$2}', textAlign: TextAlign.end, style: const TextStyle(fontWeight: FontWeight.w700))),
          ]),
        ),
      if (rows.isEmpty) Padding(padding: const EdgeInsets.all(16), child: Text(context.l('لا توجد بيانات في هذه المدة.', 'Nothing in this period.'))),
    ]);
  }

  @override
  Widget build(BuildContext context) {
    final d = _data;
    final f = d?['filters'] as Map?;
    return Scaffold(
      appBar: AppBar(
        title: Text(context.l('تقارير المكتبة', 'Library reports')),
        actions: [IconButton(tooltip: context.l('المدة', 'Period'), icon: const Icon(Icons.date_range_rounded), onPressed: _pickRange)],
      ),
      body: d == null
          ? LoadingOrError(error: _error, onRetry: _load)
          : RefreshIndicator(
              onRefresh: _load,
              child: Builder(builder: (context) {
                final t = Map<String, dynamic>.from(d['totals'] as Map);
                List<Map> list(String k) => ((d[k] as List?) ?? []).cast<Map>();
                final byType = (d['byType'] is Map) ? Map<String, dynamic>.from(d['byType'] as Map) : <String, dynamic>{};
                return ListView(padding: const EdgeInsets.only(bottom: 32), children: [
                  ListTile(
                    leading: const Icon(Icons.date_range_rounded),
                    title: Text(context.l('من ${f?['from']} إلى ${f?['to']}', 'From ${f?['from']} to ${f?['to']}')),
                    trailing: TextButton(onPressed: _pickRange, child: Text(context.l('تغيير', 'Change'))),
                  ),
                  StatGrid(tiles: [
                    StatTile(label: context.l('إعارات', 'Loans'), value: t['loans'], color: AppColors.primary, icon: Icons.north_east_rounded),
                    StatTile(label: context.l('إرجاعات', 'Returns'), value: t['returns'], color: const Color(0xFFEF6C00), icon: Icons.south_west_rounded),
                    StatTile(label: context.l('قرّاء', 'Readers'), value: t['readers'], color: const Color(0xFF7E57C2), icon: Icons.people_alt_outlined),
                    StatTile(label: context.l('عناوين', 'Titles'), value: t['titles'], color: const Color(0xFF00897B), icon: Icons.menu_book_rounded),
                    StatTile(label: context.l('مُعار الآن', 'On loan now'), value: t['active_now'], color: AppColors.primary, icon: Icons.swap_horiz_rounded),
                    StatTile(label: context.l('متأخر الآن', 'Overdue now'), value: t['overdue_now'], color: AppColors.danger, icon: Icons.warning_amber_rounded),
                    StatTile(label: context.l('أُرجع متأخراً', 'Returned late'), value: d['lateReturns'], color: AppColors.warning, icon: Icons.timer_off_outlined),
                    StatTile(label: context.l('غرامات محصّلة', 'Fines collected'), value: t['fines_collected'], color: AppColors.success, icon: Icons.payments_outlined),
                    StatTile(label: context.l('غرامات مستحقة', 'Fines due'), value: t['fines_due'], color: AppColors.danger, icon: Icons.request_quote_outlined),
                    StatTile(label: context.l('غرامات معفاة', 'Fines waived'), value: t['fines_waived'], color: Colors.blueGrey, icon: Icons.money_off_rounded),
                    StatTile(label: context.l('نسخ مفقودة', 'Lost copies'), value: t['lost'], color: AppColors.danger, icon: Icons.help_outline_rounded),
                    StatTile(label: context.l('نسخ تالفة', 'Damaged copies'), value: t['damaged'], color: const Color(0xFFEF6C00), icon: Icons.broken_image_outlined),
                    StatTile(label: context.l('الكتب', 'Books'), value: t['books'], color: const Color(0xFF3949AB), icon: Icons.library_books_outlined),
                    StatTile(label: context.l('النسخ', 'Copies'), value: t['copies'], color: const Color(0xFF3949AB), icon: Icons.qr_code_rounded),
                    StatTile(label: context.l('القرّاء المسجلون', 'Readers registered'), value: t['members'], color: const Color(0xFF3949AB), icon: Icons.badge_outlined),
                  ]),
                  GroupCard(title: context.l('حسب الشهر (إعارات / إرجاعات)', 'By month (loans / returns)'), children: [
                    for (final m in list('monthly'))
                      ListTile(dense: true, title: Text('${m['month']}'), trailing: Text('${m['loans']} / ${m['returns']}', style: const TextStyle(fontWeight: FontWeight.w700))),
                  ]),
                  GroupCard(
                    title: context.l('الأكثر إعارة', 'Most borrowed'),
                    children: [_bars([for (final b in list('topBooks')) ('${b['title'] ?? ''}', (b['count'] as num?) ?? 0)], AppColors.primary)],
                  ),
                  GroupCard(
                    title: context.l('أكثر القرّاء استعارة', 'Most active readers'),
                    children: [_bars([for (final r in list('topReaders')) ('${r['name'] ?? ''}', (r['count'] as num?) ?? 0)], const Color(0xFF7E57C2))],
                  ),
                  GroupCard(
                    title: context.l('حسب القسم', 'By section'),
                    children: [_bars([for (final s in list('bySection')) ('${s['name'] ?? ''}', (s['count'] as num?) ?? 0)], const Color(0xFF00897B))],
                  ),
                  GroupCard(
                    title: context.l('حسب نوع القارئ', 'By reader type'),
                    children: [_bars([for (final e in byType.entries) (memberTypeLabel(context, e.key), (e.value as num?) ?? 0)], const Color(0xFFEF6C00))],
                  ),
                ]);
              }),
            ),
    );
  }
}
