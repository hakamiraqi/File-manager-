import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../../../app/theme/app_colors.dart';
import '../../../core/network/api_client.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/ui_kit.dart';
import '../../library/presentation/manage/manage_widgets.dart';

typedef _Json = Map<String, dynamic>;

/// «التعرف على النصوص» (2026-11-10): the OCR queue, how the server is coping, and the
/// hours and limits it works within: the same page as the website's admin panel.
class OcrScreen extends StatefulWidget {
  const OcrScreen({super.key});

  @override
  State<OcrScreen> createState() => _OcrScreenState();
}

class _OcrScreenState extends State<OcrScreen> {
  Dio get _dio => ApiClient.instance.dio;

  _Json? _data;
  String? _error;
  String _status = 'pending';
  final _search = TextEditingController();
  int _page = 1;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load({int page = 1}) async {
    setState(() => _error = null);
    try {
      final r = await _dio.get('/admin/ocr', queryParameters: {'status': _status, 'page': page, if (_search.text.trim().isNotEmpty) 'q': _search.text.trim()});
      if (!mounted) return;
      setState(() {
        _data = Map<String, dynamic>.from(r.data as Map);
        _page = page;
      });
    } catch (e) {
      if (mounted) setState(() => _error = ApiClient.messageFor(e));
    }
  }

  Future<void> _post(String path, [Object? data]) async {
    try {
      final r = await _dio.post('/admin/ocr$path', data: data);
      final msg = r.data is Map ? (r.data as Map)['message'] : null;
      if (mounted && msg is String) context.toast(msg);
      await _load(page: _page);
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  String _statusLabel(String s) => switch (s) {
        'pending' => context.l('بالانتظار', 'Waiting'),
        'processing' => context.l('قيد القراءة', 'Reading'),
        'done' => context.l('تمّت', 'Done'),
        'failed' => context.l('فشلت', 'Failed'),
        'skipped' => context.l('متروكة', 'Skipped'),
        _ => context.l('الكل', 'All'),
      };

  Color _statusColor(String s) => switch (s) {
        'done' => AppColors.success,
        'failed' => AppColors.danger,
        'processing' => AppColors.primary,
        'skipped' => Colors.blueGrey,
        _ => AppColors.warning,
      };

  @override
  Widget build(BuildContext context) {
    final d = _data;
    return DefaultTabController(
      length: 3,
      child: Scaffold(
        appBar: AppBar(
          title: Text(context.l('التعرف على النصوص', 'Text recognition')),
          actions: [IconButton(tooltip: context.l('تحديث', 'Refresh'), icon: const Icon(Icons.refresh_rounded), onPressed: () => _load(page: _page))],
          bottom: TabBar(tabs: [
            Tab(text: context.l('الحالة', 'Status')),
            Tab(text: context.l('الملفات', 'Files')),
            Tab(text: context.l('الإعدادات', 'Settings')),
          ]),
        ),
        body: d == null
            ? LoadingOrError(error: _error, onRetry: _load)
            : TabBarView(children: [
                RefreshIndicator(onRefresh: () => _load(page: _page), child: _statusTab(d)),
                _filesTab(d),
                _OcrSettingsForm(
                  settings: Map<String, dynamic>.from(d['settings'] as Map),
                  languages: (((d['engine'] as Map?)?['languages'] as List?) ?? []).map((e) => '$e').toList(),
                  onSave: (data) => _post('', data),
                ),
              ]),
      ),
    );
  }

  Widget _statusTab(_Json d) {
    final counts = Map<String, dynamic>.from(d['counts'] as Map);
    final state = Map<String, dynamic>.from(d['state'] as Map);
    final engine = Map<String, dynamic>.from((d['engine'] as Map?) ?? {});
    final load = Map<String, dynamic>.from((state['load'] as Map?) ?? {});
    final last = state['last'] as Map?;
    final week = ((d['week'] as List?) ?? []).cast<Map>();
    final pending = ((counts['pending'] as num?) ?? 0) + ((counts['new'] as num?) ?? 0);
    final spp = state['seconds_per_page'] as num?;
    final weekMax = week.fold<num>(1, (m, w) => ((w['done'] as num?) ?? 0) > m ? (w['done'] as num) : m);

    return ListView(padding: const EdgeInsets.only(bottom: 32), children: [
      StatGrid(tiles: [
        StatTile(label: context.l('بالانتظار', 'Waiting'), value: counts['pending'], color: AppColors.warning, icon: Icons.hourglass_top_rounded),
        StatTile(label: context.l('لم تُضف بعد', 'Not queued yet'), value: counts['new'], color: Colors.blueGrey, icon: Icons.playlist_add_rounded),
        StatTile(label: context.l('قيد القراءة', 'Reading'), value: counts['processing'], color: AppColors.primary, icon: Icons.document_scanner_outlined),
        StatTile(label: context.l('تمّت', 'Done'), value: counts['done'], color: AppColors.success, icon: Icons.check_circle_outline_rounded),
        StatTile(label: context.l('فشلت', 'Failed'), value: counts['failed'], color: AppColors.danger, icon: Icons.error_outline_rounded),
        StatTile(label: context.l('متروكة', 'Skipped'), value: counts['skipped'], color: Colors.blueGrey, icon: Icons.block_rounded),
      ]),
      GroupCard(title: context.l('الآن', 'Now'), children: [
        ListTile(
          leading: Icon(state['in_window'] == true ? Icons.play_circle_outline_rounded : Icons.pause_circle_outline_rounded,
              color: state['in_window'] == true ? AppColors.success : Colors.blueGrey),
          title: Text(state['in_window'] == true ? context.l('ضمن وقت العمل', 'Within working hours') : context.l('خارج وقت العمل', 'Outside working hours')),
          subtitle: state['in_window'] == true || state['next_start'] == null
              ? null
              : Text(context.l('يبدأ ${timeAgoOrDate(state['next_start'] as String)}', 'Starts ${timeAgoOrDate(state['next_start'] as String)}')),
        ),
        if (state['busy_reason'] != null)
          ListTile(leading: const Icon(Icons.speed_rounded, color: AppColors.warning), title: Text('${state['busy_reason']}')),
        ListTile(
          leading: const Icon(Icons.memory_rounded),
          title: Text(context.l('المعالج ${load['cpu'] ?? '—'}٪ · ذاكرة حرة ${load['ram_free_mb'] ?? '—'} م.ب', 'CPU ${load['cpu'] ?? '—'}% · free RAM ${load['ram_free_mb'] ?? '—'} MB')),
          subtitle: Text(context.l('قرص حر ${load['disk_free_gb'] ?? '—'} غ.ب · مستخدمون نشطون ${load['active_users'] ?? 0}', 'Free disk ${load['disk_free_gb'] ?? '—'} GB · active users ${load['active_users'] ?? 0}')),
        ),
        if (last != null)
          ListTile(
            leading: const Icon(Icons.history_rounded),
            title: Text(context.l('آخر دورة: ${last['state'] ?? ''}', 'Last round: ${last['state'] ?? ''}')),
            subtitle: Text([
              if (last['reason'] != null) '${last['reason']}',
              context.l('عولج ${last['processed'] ?? 0}', 'processed ${last['processed'] ?? 0}'),
              timeAgo(context, last['at'] as String?),
            ].join(' · ')),
          ),
        if (spp != null)
          ListTile(
            leading: const Icon(Icons.timer_outlined),
            title: Text(context.l('$spp ثانية للصفحة', '$spp s per page')),
            subtitle: pending > 0 ? Text(context.l('$pending ملف متبقٍ', '$pending files left')) : null,
          ),
        ListTile(
          leading: Icon(engine['available'] == true ? Icons.verified_outlined : Icons.report_problem_outlined, color: engine['available'] == true ? AppColors.success : AppColors.danger),
          title: Text(engine['available'] == true ? 'Tesseract ${engine['version'] ?? ''}' : context.l('محرك القراءة غير مثبت', 'OCR engine missing')),
          subtitle: Text([
            context.l('اللغات: ${((engine['languages'] as List?) ?? []).join('، ')}', 'Languages: ${((engine['languages'] as List?) ?? []).join(', ')}'),
            engine['pdf'] == true ? 'PDF ✓' : 'PDF ✗',
          ].join(' · ')),
        ),
      ]),
      GroupCard(title: context.l('المنجز في آخر 7 أيام', 'Done in the last 7 days'), children: [
        for (final w in week)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
            child: Row(children: [
              SizedBox(width: 92, child: Text('${w['day']}', style: const TextStyle(fontSize: 12.5))),
              Expanded(
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(6),
                  child: LinearProgressIndicator(value: ((w['done'] as num?) ?? 0) / weekMax, minHeight: 10, color: AppColors.success, backgroundColor: AppColors.success.withOpacity(0.12)),
                ),
              ),
              SizedBox(width: 44, child: Text('${w['done'] ?? 0}', textAlign: TextAlign.end, style: const TextStyle(fontWeight: FontWeight.w700))),
            ]),
          ),
      ]),
      GroupCard(title: context.l('إجراءات', 'Actions'), children: [
        NavRow(
          icon: Icons.playlist_add_rounded,
          color: AppColors.primary,
          label: context.l('أضف كل الملفات الجديدة للقائمة', 'Queue every new file'),
          onTap: () => _post('/queue'),
        ),
        NavRow(
          icon: Icons.play_arrow_rounded,
          color: AppColors.success,
          label: context.l('شغّل دفعة قصيرة الآن', 'Run a short batch now'),
          subtitle: context.l('5 ملفات، وتتوقف إذا انشغل الخادم', '5 files; stops if the server gets busy'),
          onTap: () => _post('/run'),
        ),
        NavRow(
          icon: Icons.replay_rounded,
          color: AppColors.warning,
          label: context.l('أعد محاولة الملفات الفاشلة', 'Retry failed files'),
          badge: (counts['failed'] as num?)?.toInt() ?? 0,
          onTap: () => _post('/retry-failed'),
        ),
      ]),
    ]);
  }

  String timeAgoOrDate(String iso) {
    final t = DateTime.tryParse(iso)?.toLocal();
    if (t == null) return iso;
    String two(int n) => n.toString().padLeft(2, '0');
    return '${t.year}/${two(t.month)}/${two(t.day)} ${two(t.hour)}:${two(t.minute)}';
  }

  Widget _filesTab(_Json d) {
    final files = Map<String, dynamic>.from(d['files'] as Map);
    final list = ((files['data'] as List?) ?? []).cast<Map>();
    final last = (files['last_page'] as num?)?.toInt() ?? 1;
    return RefreshIndicator(
      onRefresh: () => _load(page: _page),
      child: ListView(padding: const EdgeInsets.only(bottom: 32), children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 4),
          child: SearchField(controller: _search, hint: context.l('اسم الملف', 'File name'), onSubmitted: (_) => _load(), onClear: _load),
        ),
        FilterStrip(
          items: {for (final s in ['pending', 'processing', 'done', 'failed', 'skipped', 'all']) s: _statusLabel(s)},
          selected: _status,
          onSelected: (s) {
            _status = s;
            _load();
          },
        ),
        if (list.isEmpty)
          SizedBox(height: 260, child: EmptyState(icon: Icons.document_scanner_outlined, message: context.l('لا توجد ملفات هنا', 'No files here')))
        else
          GroupCard(children: [
            for (final f in list)
              ListTile(
                leading: Icon(Icons.description_outlined, color: _statusColor('${f['status']}')),
                title: Text('${f['name']}', maxLines: 2, overflow: TextOverflow.ellipsis),
                subtitle: Text(
                  [
                    _statusLabel('${f['status']}'),
                    if (f['folder'] != null) '${f['folder']}',
                    '${f['size'] ?? ''}',
                    if (f['pages'] != null) context.l('${f['pages']} صفحة', '${f['pages']} pages'),
                    if (f['chars'] != null) context.l('${f['chars']} حرف', '${f['chars']} chars'),
                    if ((f['priority'] as num? ?? 0) > 0) context.l('أولوية', 'priority'),
                    if (f['error'] != null) '${f['error']}',
                  ].where((s) => s.isNotEmpty).join(' · '),
                  maxLines: 3,
                  overflow: TextOverflow.ellipsis,
                ),
                trailing: PopupMenuButton<String>(
                  onSelected: (a) => _post('/files/${f['id']}/$a'),
                  itemBuilder: (_) => [
                    PopupMenuItem(value: 'prioritize', child: Text(context.l('اقرأه أولاً', 'Read it first'))),
                    PopupMenuItem(value: 'retry', child: Text(context.l('أعد المحاولة', 'Retry'))),
                    PopupMenuItem(value: 'skip', child: Text(context.l('اتركه', 'Skip it'))),
                  ],
                ),
              ),
          ]),
        if (last > 1)
          Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            IconButton(onPressed: _page > 1 ? () => _load(page: _page - 1) : null, icon: const Icon(Icons.chevron_left_rounded)),
            Text('$_page / $last'),
            IconButton(onPressed: _page < last ? () => _load(page: _page + 1) : null, icon: const Icon(Icons.chevron_right_rounded)),
          ]),
      ]),
    );
  }
}

/// The OCR hours and limits, as on the website.
class _OcrSettingsForm extends StatefulWidget {
  const _OcrSettingsForm({required this.settings, required this.languages, required this.onSave});

  final _Json settings;
  final List<String> languages;
  final Future<void> Function(_Json data) onSave;

  @override
  State<_OcrSettingsForm> createState() => _OcrSettingsFormState();
}

class _OcrSettingsFormState extends State<_OcrSettingsForm> with AutomaticKeepAliveClientMixin {
  static const _numbers = [
    ('ocr_batch', 'ملفات في كل دورة', 'Files per round'),
    ('ocr_timeout_minutes', 'أقصى وقت للملف (دقيقة)', 'Max time per file (min)'),
    ('ocr_max_pages', 'أقصى صفحات للملف', 'Max pages per file'),
    ('ocr_max_mb', 'أقصى حجم للملف (م.ب)', 'Max file size (MB)'),
    ('ocr_max_cpu', 'يتوقف إذا تجاوز المعالج (٪)', 'Pause above CPU (%)'),
    ('ocr_min_ram_mb', 'أقل ذاكرة حرة (م.ب)', 'Min free RAM (MB)'),
    ('ocr_min_disk_gb', 'أقل مساحة قرص حرة (غ.ب)', 'Min free disk (GB)'),
    ('ocr_max_users', 'يتوقف إذا تجاوز المستخدمون', 'Pause above active users'),
  ];

  late final Map<String, TextEditingController> _c = {
    for (final n in _numbers) n.$1: TextEditingController(text: '${widget.settings[n.$1] ?? ''}'),
  };
  late bool _enabled = '${widget.settings['ocr_enabled']}' == '1';
  late bool _report = '${widget.settings['ocr_report']}' == '1';
  late String _start = '${widget.settings['ocr_start'] ?? '15:00'}';
  late String _end = '${widget.settings['ocr_end'] ?? '07:00'}';
  late String _languages = '${widget.settings['ocr_languages'] ?? 'ara+eng'}';
  late Set<int> _days = _parseDays('${widget.settings['ocr_days'] ?? ''}');
  late Set<int> _allDay = _parseDays('${widget.settings['ocr_all_day_days'] ?? ''}');
  bool _busy = false;

  @override
  bool get wantKeepAlive => true;

  static Set<int> _parseDays(String s) => s.split(',').map((e) => int.tryParse(e.trim())).whereType<int>().toSet();

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _pickTime(bool start) async {
    final parts = (start ? _start : _end).split(':');
    final t = await showTimePicker(
      context: context,
      initialTime: TimeOfDay(hour: int.tryParse(parts.first) ?? 0, minute: int.tryParse(parts.last) ?? 0),
      builder: (context, child) => MediaQuery(data: MediaQuery.of(context).copyWith(alwaysUse24HourFormat: true), child: child!),
    );
    if (t == null) return;
    final v = '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';
    setState(() => start ? _start = v : _end = v);
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    await widget.onSave({
      'ocr_enabled': _enabled,
      'ocr_report': _report,
      'ocr_days': _days.toList()..sort(),
      'ocr_all_day_days': _allDay.toList()..sort(),
      'ocr_start': _start,
      'ocr_end': _end,
      'ocr_languages': _languages,
      for (final n in _numbers) n.$1: int.tryParse(_c[n.$1]!.text.trim()) ?? 0,
    });
    if (mounted) setState(() => _busy = false);
  }

  Widget _dayChips(Set<int> set) {
    final names = [
      context.l('الأحد', 'Sun'),
      context.l('الاثنين', 'Mon'),
      context.l('الثلاثاء', 'Tue'),
      context.l('الأربعاء', 'Wed'),
      context.l('الخميس', 'Thu'),
      context.l('الجمعة', 'Fri'),
      context.l('السبت', 'Sat'),
    ];
    return Wrap(spacing: 6, runSpacing: 6, children: [
      for (var i = 0; i < 7; i++)
        FilterChip(
          label: Text(names[i]),
          selected: set.contains(i),
          onSelected: (on) => setState(() => on ? set.add(i) : set.remove(i)),
        ),
    ]);
  }

  @override
  Widget build(BuildContext context) {
    super.build(context);
    return ListView(padding: const EdgeInsets.fromLTRB(0, 4, 0, 32), children: [
      GroupCard(children: [
        SwitchListTile(value: _enabled, onChanged: (v) => setState(() => _enabled = v), title: Text(context.l('تشغيل التعرف على النصوص', 'Text recognition on'))),
        SwitchListTile(value: _report, onChanged: (v) => setState(() => _report = v), title: Text(context.l('تقرير يومي للمشرف', 'Daily report to the admin'))),
      ]),
      GroupCard(
        title: context.l('وقت العمل (بتوقيت الخادم)', 'Working hours (server time)'),
        footer: context.l('أيام «كل اليوم» يعمل فيها طوال اليوم بغض النظر عن الساعات.', '"All day" days run around the clock regardless of the hours.'),
        children: [
          ListTile(
            title: Text(context.l('من', 'From')),
            trailing: TextButton(onPressed: () => _pickTime(true), child: Text(_start, textDirection: TextDirection.ltr)),
          ),
          ListTile(
            title: Text(context.l('إلى', 'To')),
            trailing: TextButton(onPressed: () => _pickTime(false), child: Text(_end, textDirection: TextDirection.ltr)),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 6, 14, 10),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(context.l('أيام العمل', 'Working days'), style: const TextStyle(fontWeight: FontWeight.w600)),
              const SizedBox(height: 6),
              _dayChips(_days),
              const SizedBox(height: 12),
              Text(context.l('كل اليوم', 'All day'), style: const TextStyle(fontWeight: FontWeight.w600)),
              const SizedBox(height: 6),
              _dayChips(_allDay),
            ]),
          ),
        ],
      ),
      GroupCard(title: context.l('اللغة والحدود', 'Language and limits'), children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
          child: Column(children: [
            DropdownButtonFormField<String>(
              value: const ['ara+eng', 'ara', 'eng'].contains(_languages) ? _languages : 'ara+eng',
              decoration: InputDecoration(labelText: context.l('لغة القراءة', 'Language')),
              items: [
                DropdownMenuItem(value: 'ara+eng', child: Text(context.l('العربية والإنجليزية', 'Arabic and English'))),
                DropdownMenuItem(value: 'ara', child: Text(context.l('العربية', 'Arabic'))),
                DropdownMenuItem(value: 'eng', child: Text(context.l('الإنجليزية', 'English'))),
              ],
              onChanged: (v) => setState(() => _languages = v ?? _languages),
            ),
            for (final n in _numbers)
              Padding(
                padding: const EdgeInsets.only(top: 10),
                child: TextField(controller: _c[n.$1], keyboardType: TextInputType.number, decoration: InputDecoration(labelText: context.l(n.$2, n.$3), isDense: true)),
              ),
          ]),
        ),
      ]),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: FilledButton.icon(
          onPressed: _busy ? null : _save,
          icon: const Icon(Icons.save_outlined),
          label: Text(context.l('حفظ الإعدادات', 'Save settings')),
        ),
      ),
    ]);
  }
}
