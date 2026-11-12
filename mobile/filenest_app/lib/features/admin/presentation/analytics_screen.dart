import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../../core/network/api_client.dart';
import '../../../core/widgets/ui_kit.dart';

/// «التحليلات وأداء الموظفين», as on the site (Admin/Analytics.vue): a period, headline numbers
/// against the previous period, activity over time and by type, the staff ranking, peak hours
/// and weekdays, uploaded file types and the quality of entered records. Same data as the site
/// (GET /admin/staff-analytics, the site page's props).
class AnalyticsScreen extends StatefulWidget {
  const AnalyticsScreen({super.key});

  @override
  State<AnalyticsScreen> createState() => _AnalyticsScreenState();
}

class _AnalyticsScreenState extends State<AnalyticsScreen> {
  Map<String, dynamic>? _data;
  String? _error;
  bool _loading = true;
  String _preset = 'last_30';
  int? _user;

  static const _presets = [
    'today', 'shift', 'yesterday', 'this_week', 'last_7', 'this_month', 'last_month', 'last_30', 'last_90', 'this_year',
  ];

  static const _categories = ['uploads', 'data_entry', 'circulation', 'sharing', 'downloads', 'deletions'];

  static const _catColors = {
    'uploads': Color(0xFF3B82F6),
    'data_entry': Color(0xFF10B981),
    'circulation': Color(0xFFF59E0B),
    'sharing': Color(0xFF8B5CF6),
    'downloads': Color(0xFF06B6D4),
    'deletions': Color(0xFFEF4444),
  };

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final r = await ApiClient.instance.dio.get('/admin/staff-analytics', queryParameters: {
        'preset': _preset,
        if (_user != null) 'user': _user,
      });
      if (mounted) setState(() => _data = Map<String, dynamic>.from(r.data as Map));
    } catch (e) {
      if (mounted) setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _presetLabel(BuildContext c, String p) => switch (p) {
        'today' => c.l('اليوم', 'Today'),
        'shift' => c.l('من بداية الدوام', 'Since the shift started'),
        'yesterday' => c.l('أمس', 'Yesterday'),
        'this_week' => c.l('هذا الأسبوع', 'This week'),
        'last_7' => c.l('آخر 7 أيام', 'Last 7 days'),
        'this_month' => c.l('هذا الشهر', 'This month'),
        'last_month' => c.l('الشهر الماضي', 'Last month'),
        'last_30' => c.l('آخر 30 يوماً', 'Last 30 days'),
        'last_90' => c.l('آخر 90 يوماً', 'Last 90 days'),
        _ => c.l('هذه السنة', 'This year'),
      };

  String _catLabel(BuildContext c, String k) => switch (k) {
        'uploads' => c.l('الرفع', 'Uploads'),
        'data_entry' => c.l('إدخال البيانات', 'Data entry'),
        'circulation' => c.l('الإعارة', 'Circulation'),
        'sharing' => c.l('المشاركة', 'Sharing'),
        'downloads' => c.l('التنزيل', 'Downloads'),
        _ => c.l('الحذف', 'Deletions'),
      };

  String _typeLabel(BuildContext c, String k) => switch (k) {
        'pdf' => 'PDF',
        'word' => c.l('مستندات Word ونصوص', 'Word documents and text'),
        'excel' => c.l('جداول Excel', 'Excel sheets'),
        'slides' => c.l('عروض تقديمية', 'Presentations'),
        'image' => c.l('صور', 'Images'),
        'video' => c.l('فيديو', 'Video'),
        'audio' => c.l('صوت', 'Audio'),
        'archive' => c.l('أرشيفات مضغوطة', 'Archives'),
        _ => c.l('أخرى', 'Other'),
      };

  static String _mb(num bytes) => bytes >= 1073741824
      ? '${(bytes / 1073741824).toStringAsFixed(1)} GB'
      : '${(bytes / 1048576).toStringAsFixed(bytes >= 104857600 ? 0 : 1)} MB';

  @override
  Widget build(BuildContext context) {
    final data = _data;
    return Scaffold(
      appBar: AppBar(title: Text(context.l('التحليلات وأداء الموظفين', 'Analytics and staff performance'))),
      body: Column(children: [
        _buildFilters(context),
        Expanded(
          child: _loading && data == null
              ? const Center(child: CircularProgressIndicator())
              : _error != null
                  ? Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(_error!, textAlign: TextAlign.center)))
                  : RefreshIndicator(onRefresh: _load, child: _buildReport(context, data!)),
        ),
      ]),
    );
  }

  Widget _buildFilters(BuildContext context) {
    final users = (_data?['users'] as List? ?? []).cast<Map>();
    return Material(
      color: Theme.of(context).colorScheme.surface,
      elevation: 1,
      child: Column(children: [
        SizedBox(
          height: 52,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            children: [
              for (final p in _presets)
                Padding(
                  padding: const EdgeInsetsDirectional.only(end: 6),
                  child: ChoiceChip(
                    label: Text(_presetLabel(context, p)),
                    selected: _preset == p,
                    onSelected: (_) {
                      setState(() => _preset = p);
                      _load();
                    },
                  ),
                ),
            ],
          ),
        ),
        if (users.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
            child: DropdownButtonFormField<int?>(
              initialValue: _user,
              isExpanded: true,
              isDense: true,
              decoration: InputDecoration(prefixIcon: const Icon(Icons.person_search_outlined), labelText: context.l('الموظف', 'Staff member')),
              items: [
                DropdownMenuItem<int?>(value: null, child: Text(context.l('كل الموظفين', 'All staff'))),
                for (final u in users) DropdownMenuItem<int?>(value: (u['id'] as num).toInt(), child: Text('${u['name']}', overflow: TextOverflow.ellipsis)),
              ],
              onChanged: (v) {
                setState(() => _user = v);
                _load();
              },
            ),
          ),
        if (_loading) const LinearProgressIndicator(minHeight: 2),
      ]),
    );
  }

  Widget _buildReport(BuildContext context, Map<String, dynamic> data) {
    final report = Map<String, dynamic>.from(data['report'] as Map? ?? {});
    final kpis = Map<String, dynamic>.from(report['kpis'] as Map? ?? {});
    final staff = (report['staff'] as List? ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    final period = Map<String, dynamic>.from(data['period'] as Map? ?? {});

    return ListView(
      padding: const EdgeInsets.fromLTRB(14, 14, 14, 30),
      children: [
        Directionality(
          textDirection: TextDirection.ltr,
          child: Text('${period['from'] ?? ''}  →  ${period['to'] ?? ''}',
              textAlign: TextAlign.center, style: TextStyle(fontSize: 12, color: Theme.of(context).colorScheme.onSurfaceVariant)),
        ),
        const SizedBox(height: 12),
        _kpiGrid(context, kpis),
        const SizedBox(height: 16),
        _card(context, Icons.show_chart_rounded, context.l('النشاط عبر الزمن', 'Activity over time'), _trend(context, report)),
        _card(context, Icons.donut_small_outlined, context.l('توزيع النشاط حسب النوع', 'Activity by type'), _byCategory(context, kpis)),
        _card(context, Icons.emoji_events_outlined, context.l('تقرير أداء الموظفين', 'Staff performance report'),
            staff.isEmpty
                ? Padding(padding: const EdgeInsets.all(16), child: Text(context.l('لا يوجد نشاط في هذه الفترة', 'No activity in this period')))
                : Column(children: [for (final s in staff.take(50)) _staffTile(context, s)])),
        _card(context, Icons.schedule_rounded, context.l('ساعات الذروة', 'Peak hours'), _bars(context, (report['hours'] as List? ?? []).cast<num>(), (i) => '$i')),
        _card(
          context,
          Icons.calendar_view_week_rounded,
          context.l('النشاط حسب أيام الأسبوع', 'Activity by day of the week'),
          _bars(context, (report['weekdays'] as List? ?? []).cast<num>(), (i) {
            const ar = ['سبت', 'أحد', 'اثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة'];
            const en = ['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
            return context.l(ar[i % 7], en[i % 7]);
          }, wide: true),
        ),
        _card(context, Icons.insert_drive_file_outlined, context.l('أنواع الملفات المرفوعة', 'Uploaded file types'), _fileTypes(context, report)),
        _card(context, Icons.fact_check_outlined, context.l('جودة البيانات المُدخلة', 'Quality of entered data'), _quality(context, report)),
      ],
    );
  }

  Widget _card(BuildContext context, IconData icon, String title, Widget child) {
    final scheme = Theme.of(context).colorScheme;
    return Card(
      margin: const EdgeInsets.only(bottom: 14),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            Icon(icon, size: 20, color: scheme.primary),
            const SizedBox(width: 8),
            Expanded(child: Text(title, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15))),
          ]),
          const SizedBox(height: 12),
          child,
        ]),
      ),
    );
  }

  Widget _kpiGrid(BuildContext context, Map<String, dynamic> kpis) {
    final items = <(String, IconData, String, bool)>[
      ('total', Icons.insights_rounded, context.l('مجموع النشاط', 'Total activity'), false),
      ('active_staff', Icons.groups_2_outlined, context.l('موظفون نشطون', 'Active staff'), false),
      ('uploads', Icons.cloud_upload_outlined, _catLabel(context, 'uploads'), false),
      ('bytes', Icons.storage_rounded, context.l('حجم المرفوع', 'Uploaded size'), true),
      ('data_entry', Icons.edit_note_rounded, _catLabel(context, 'data_entry'), false),
      ('circulation', Icons.menu_book_outlined, _catLabel(context, 'circulation'), false),
    ];
    return LayoutBuilder(builder: (context, c) {
      final cols = c.maxWidth > 700 ? 3 : 2;
      final w = (c.maxWidth - (cols - 1) * 10) / cols;
      return Wrap(spacing: 10, runSpacing: 10, children: [
        for (final (key, icon, label, bytes) in items)
          SizedBox(width: w, child: _kpi(context, icon, label, Map<String, dynamic>.from(kpis[key] as Map? ?? {}), bytes)),
      ]);
    });
  }

  Widget _kpi(BuildContext context, IconData icon, String label, Map<String, dynamic> k, bool bytes) {
    final scheme = Theme.of(context).colorScheme;
    final value = (k['value'] as num?) ?? 0;
    final prev = (k['previous'] as num?) ?? 0;
    final delta = prev == 0 ? (value > 0 ? 100 : 0) : ((value - prev) / prev * 100).round();
    final up = delta >= 0;
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: scheme.surfaceContainerLow, borderRadius: BorderRadius.circular(14)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Icon(icon, size: 18, color: scheme.primary),
          const SizedBox(width: 6),
          Expanded(child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant))),
        ]),
        const SizedBox(height: 6),
        Text(bytes ? _mb(value) : '$value', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
        const SizedBox(height: 2),
        Row(children: [
          Icon(up ? Icons.arrow_upward_rounded : Icons.arrow_downward_rounded, size: 14, color: up ? Colors.green : Colors.red),
          Directionality(
            textDirection: TextDirection.ltr,
            child: Text('${delta.abs()}%', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: up ? Colors.green : Colors.red)),
          ),
          const SizedBox(width: 4),
          Flexible(
            child: Text(context.l('عن الفترة السابقة', 'vs previous'),
                overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 11, color: scheme.onSurfaceVariant)),
          ),
        ]),
      ]),
    );
  }

  Widget _trend(BuildContext context, Map<String, dynamic> report) {
    final trend = Map<String, dynamic>.from(report['trend'] as Map? ?? {});
    final labels = (trend['labels'] as List? ?? []).map((e) => '$e').toList();
    final series = Map<String, dynamic>.from(trend['series'] as Map? ?? {});
    if (labels.isEmpty) return Text(context.l('لا يوجد نشاط في هذه الفترة', 'No activity in this period'));
    final stacks = List.generate(labels.length, (i) => {
          for (final c in _categories) c: ((series[c] as List?)?.elementAtOrNull(i) as num?)?.toDouble() ?? 0.0,
        });
    final maxV = stacks.map((s) => s.values.fold<double>(0, (a, b) => a + b)).fold<double>(1, math.max);
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      SizedBox(
        height: 150,
        child: Directionality(
          textDirection: TextDirection.ltr,
          child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
            for (var i = 0; i < stacks.length; i++)
              Expanded(
                child: Tooltip(
                  message: '${labels[i]} · ${stacks[i].values.fold<double>(0, (a, b) => a + b).round()}',
                  child: Padding(
                    padding: EdgeInsets.symmetric(horizontal: stacks.length > 40 ? 0.3 : 1.2),
                    child: Column(mainAxisAlignment: MainAxisAlignment.end, children: [
                      for (final c in _categories.reversed)
                        if (stacks[i][c]! > 0)
                          Container(height: 140 * stacks[i][c]! / maxV, color: _catColors[c]),
                    ]),
                  ),
                ),
              ),
          ]),
        ),
      ),
      const SizedBox(height: 6),
      Directionality(
        textDirection: TextDirection.ltr,
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Text(labels.first, style: const TextStyle(fontSize: 11)),
          Text(labels.last, style: const TextStyle(fontSize: 11)),
        ]),
      ),
      const SizedBox(height: 8),
      Wrap(spacing: 10, runSpacing: 4, children: [
        for (final c in _categories)
          Row(mainAxisSize: MainAxisSize.min, children: [
            Container(width: 10, height: 10, decoration: BoxDecoration(color: _catColors[c], borderRadius: BorderRadius.circular(3))),
            const SizedBox(width: 4),
            Text(_catLabel(context, c), style: const TextStyle(fontSize: 11.5)),
          ]),
      ]),
    ]);
  }

  Widget _byCategory(BuildContext context, Map<String, dynamic> kpis) {
    final values = {for (final c in _categories) c: ((kpis[c] as Map?)?['value'] as num?) ?? 0};
    final maxV = values.values.fold<num>(1, (a, b) => math.max(a, b));
    return Column(children: [
      for (final c in _categories)
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Row(children: [
            SizedBox(width: 96, child: Text(_catLabel(context, c), style: const TextStyle(fontSize: 13))),
            Expanded(
              child: ClipRRect(
                borderRadius: BorderRadius.circular(6),
                child: LinearProgressIndicator(
                  value: values[c]! / maxV,
                  minHeight: 12,
                  color: _catColors[c],
                  backgroundColor: _catColors[c]!.withValues(alpha: 0.12),
                ),
              ),
            ),
            SizedBox(width: 48, child: Text('${values[c]}', textAlign: TextAlign.end, style: const TextStyle(fontWeight: FontWeight.w700))),
          ]),
        ),
    ]);
  }

  Widget _staffTile(BuildContext context, Map<String, dynamic> s) {
    final scheme = Theme.of(context).colorScheme;
    final rank = (s['rank'] as num?)?.toInt() ?? 0;
    final total = (s['total'] as num?) ?? 0;
    final prev = (s['previous'] as num?) ?? 0;
    final index = ((s['index'] as num?) ?? 0).toDouble();
    final medal = rank == 1 ? const Color(0xFFF5B301) : rank == 2 ? const Color(0xFFA7B1BC) : rank == 3 ? const Color(0xFFCD7F32) : null;
    final quality = s['quality'] as num?;

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerLow,
        borderRadius: BorderRadius.circular(14),
        border: medal != null ? Border.all(color: medal.withValues(alpha: 0.6)) : null,
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          CircleAvatar(
            radius: 16,
            backgroundColor: medal ?? scheme.primary.withValues(alpha: 0.15),
            child: Text('$rank', style: TextStyle(fontWeight: FontWeight.w800, color: medal != null ? Colors.white : scheme.primary)),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${s['name']}', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700)),
              Text(
                [if (s['librarian'] == true) context.l('أمين مكتبة', 'Librarian'), '${s['role'] ?? ''}'].where((x) => x.isNotEmpty).join(' · '),
                style: TextStyle(fontSize: 11.5, color: scheme.onSurfaceVariant),
              ),
            ]),
          ),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text('$total', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
            Text(
              prev == 0 ? '—' : '${total >= prev ? '+' : ''}${total - prev}',
              style: TextStyle(fontSize: 11.5, color: total >= prev ? Colors.green : Colors.red),
            ),
          ]),
        ]),
        const SizedBox(height: 10),
        Row(children: [
          Text(context.l('مؤشر الأداء', 'Index'), style: TextStyle(fontSize: 11.5, color: scheme.onSurfaceVariant)),
          const SizedBox(width: 8),
          Expanded(
            child: ClipRRect(
              borderRadius: BorderRadius.circular(4),
              child: LinearProgressIndicator(value: index / 100, minHeight: 7),
            ),
          ),
          const SizedBox(width: 8),
          Text('${index.round()}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5)),
        ]),
        const SizedBox(height: 8),
        Wrap(spacing: 6, runSpacing: 6, children: [
          _pill(context, '${s['share']}% ${context.l('مساهمة', 'share')}'),
          _pill(context, '${s['files']} ${context.l('ملف', 'files')} · ${_mb((s['bytes'] as num?) ?? 0)}'),
          for (final c in _categories)
            if (((s[c] as num?) ?? 0) > 0) _pill(context, '${_catLabel(context, c)} ${s[c]}', color: _catColors[c]),
          _pill(context, '${s['active_days']} ${context.l('يوم نشاط', 'active days')}'),
          if (quality != null) _pill(context, '${context.l('الجودة', 'Quality')} $quality%'),
          if (s['last_at'] != null) _pill(context, '${context.l('آخر نشاط', 'Last')} ${s['last_at']}'),
        ]),
      ]),
    );
  }

  Widget _pill(BuildContext context, String text, {Color? color}) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: (color ?? scheme.onSurfaceVariant).withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(text, style: TextStyle(fontSize: 11.5, color: color ?? scheme.onSurfaceVariant)),
    );
  }

  Widget _bars(BuildContext context, List<num> values, String Function(int) label, {bool wide = false}) {
    if (values.isEmpty) return const SizedBox.shrink();
    final maxV = values.fold<num>(1, (a, b) => math.max(a, b));
    final peak = values.indexOf(values.reduce(math.max));
    final scheme = Theme.of(context).colorScheme;
    return SizedBox(
      height: 130,
      child: Directionality(
        textDirection: wide ? Directionality.of(context) : TextDirection.ltr,
        child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
          for (var i = 0; i < values.length; i++)
            Expanded(
              child: Tooltip(
                message: '${label(i)}: ${values[i]}',
                child: Column(mainAxisAlignment: MainAxisAlignment.end, children: [
                  Container(
                    height: math.max(2, 100 * values[i] / maxV).toDouble(),
                    margin: EdgeInsets.symmetric(horizontal: wide ? 6 : 1),
                    decoration: BoxDecoration(
                      color: i == peak ? scheme.primary : scheme.primary.withValues(alpha: 0.35),
                      borderRadius: const BorderRadius.vertical(top: Radius.circular(3)),
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(wide || i % 3 == 0 ? label(i) : '', style: const TextStyle(fontSize: 10), maxLines: 1),
                ]),
              ),
            ),
        ]),
      ),
    );
  }

  Widget _fileTypes(BuildContext context, Map<String, dynamic> report) {
    final types = (report['fileTypes'] as List? ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    if (types.isEmpty) return Text(context.l('لا توجد ملفات مرفوعة', 'No uploaded files'));
    final maxF = types.map((t) => (t['files'] as num?) ?? 0).fold<num>(1, math.max);
    return Column(children: [
      for (final t in types)
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Row(children: [
            SizedBox(width: 110, child: Text(_typeLabel(context, '${t['type']}'), style: const TextStyle(fontSize: 13))),
            Expanded(
              child: ClipRRect(
                borderRadius: BorderRadius.circular(6),
                child: LinearProgressIndicator(value: ((t['files'] as num?) ?? 0) / maxF, minHeight: 10),
              ),
            ),
            SizedBox(
              width: 92,
              child: Text('${t['files']} · ${_mb((t['bytes'] as num?) ?? 0)}', textAlign: TextAlign.end, style: const TextStyle(fontSize: 12)),
            ),
          ]),
        ),
    ]);
  }

  Widget _quality(BuildContext context, Map<String, dynamic> report) {
    final q = Map<String, dynamic>.from(report['quality'] as Map? ?? {});
    final score = q['score'] as num?;
    final missing = (q['missing'] as List? ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    if (score == null) return Text(context.l('لم تُدخل سجلات في هذه الفترة', 'No records were entered in this period'));
    final color = score >= 80 ? Colors.green : (score >= 50 ? Colors.orange : Colors.red);
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Row(children: [
        SizedBox(
          width: 64,
          height: 64,
          child: Stack(alignment: Alignment.center, children: [
            CircularProgressIndicator(value: score / 100, strokeWidth: 7, color: color, backgroundColor: color.withValues(alpha: 0.15)),
            Text('$score%', style: const TextStyle(fontWeight: FontWeight.w800)),
          ]),
        ),
        const SizedBox(width: 14),
        Expanded(
          child: Text(
            context.l('نسبة اكتمال حقول الرسائل والكتب التي أُدخلت أو عُدّلت في الفترة (${q['records']} سجل)',
                'How complete the fields are on theses and books entered or edited in the period (${q['records']} records)'),
            style: const TextStyle(fontSize: 12.5),
          ),
        ),
      ]),
      if (missing.isNotEmpty) ...[
        const SizedBox(height: 12),
        Text(context.l('أكثر الحقول نقصاً', 'Most often missing fields'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
        const SizedBox(height: 6),
        Wrap(spacing: 6, runSpacing: 6, children: [
          for (final m in missing) _pill(context, '${m['field']} · ${m['count']}', color: Colors.orange),
        ]),
      ],
    ]);
  }
}
