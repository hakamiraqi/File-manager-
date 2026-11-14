import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../../../app/theme/app_colors.dart';
import '../../../core/network/api_client.dart';
import '../../../core/widgets/authenticated_thumbnail.dart';
import '../../../core/widgets/ui_kit.dart';
import '../../library/presentation/manage/manage_widgets.dart';

typedef _Json = Map<String, dynamic>;

/// «أغلفة الكتب» (2026-11-14): the automatic cover search — how it stands, the covers it
/// found, and its hours and sources: the same page as the website's admin panel.
class CoversScreen extends StatefulWidget {
  const CoversScreen({super.key});

  @override
  State<CoversScreen> createState() => _CoversScreenState();
}

class _CoversScreenState extends State<CoversScreen> {
  Dio get _dio => ApiClient.instance.dio;

  _Json? _data;
  String? _error;
  String _show = 'covers';
  int _page = 1;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({int page = 1}) async {
    setState(() => _error = null);
    try {
      final r = await _dio.get('/admin/covers', queryParameters: {'show': _show, 'page': page});
      if (!mounted) return;
      setState(() {
        _data = Map<String, dynamic>.from(r.data as Map);
        _page = page;
      });
    } catch (e) {
      if (mounted) setState(() => _error = ApiClient.messageFor(e));
    }
  }

  Future<void> _send(Future<Response<dynamic>> Function() call) async {
    try {
      final r = await call();
      final msg = r.data is Map ? (r.data as Map)['message'] : null;
      if (mounted && msg is String) context.toast(msg);
      await _load(page: _page);
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  String _showLabel(String s) => switch (s) {
        'found' => context.l('وُجدت تلقائياً', 'Found'),
        'manual' => context.l('اختيرت يدوياً', 'Chosen by hand'),
        'none' => context.l('لم توجد', 'Not found'),
        'small' => context.l('قصيرة', 'Short'),
        'removed' => context.l('أزيلت', 'Removed'),
        'error' => context.l('خطأ', 'Error'),
        _ => context.l('كل الأغلفة', 'All covers'),
      };

  String _sourceLabel(Object? s) => switch (s) {
        'google' => 'Google Books',
        'openlibrary' => 'Open Library',
        'upload' => context.l('صورة مرفوعة', 'Uploaded'),
        'page' => context.l('صفحة من الملف', 'A page'),
        _ => '',
      };

  @override
  Widget build(BuildContext context) {
    final d = _data;
    return DefaultTabController(
      length: 3,
      child: Scaffold(
        appBar: AppBar(
          title: Text(context.l('أغلفة الكتب', 'Book covers')),
          actions: [IconButton(tooltip: context.l('تحديث', 'Refresh'), icon: const Icon(Icons.refresh_rounded), onPressed: () => _load(page: _page))],
          bottom: TabBar(tabs: [
            Tab(text: context.l('الحالة', 'Status')),
            Tab(text: context.l('الأغلفة', 'Covers')),
            Tab(text: context.l('الإعدادات', 'Settings')),
          ]),
        ),
        body: d == null
            ? LoadingOrError(error: _error, onRetry: _load)
            : TabBarView(children: [
                RefreshIndicator(onRefresh: () => _load(page: _page), child: _statusTab(d)),
                _filesTab(d),
                _CoverSettingsForm(
                  settings: Map<String, dynamic>.from(d['settings'] as Map),
                  googleKeySet: d['googleKeySet'] == true,
                  onSave: (data) => _send(() => _dio.post('/admin/covers', data: data)),
                ),
              ]),
      ),
    );
  }

  Widget _statusTab(_Json d) {
    final counts = Map<String, dynamic>.from(d['counts'] as Map);
    final books = Map<String, dynamic>.from((d['books'] as Map?) ?? {});
    final state = Map<String, dynamic>.from(d['state'] as Map);
    final last = state['last'] as Map?;
    return ListView(padding: const EdgeInsets.only(bottom: 32), children: [
      StatGrid(tiles: [
        StatTile(label: context.l('وُجدت تلقائياً', 'Found'), value: counts['found'], color: AppColors.success, icon: Icons.auto_stories_outlined),
        StatTile(label: context.l('اختيرت يدوياً', 'By hand'), value: counts['manual'], color: AppColors.primary, icon: Icons.touch_app_outlined),
        StatTile(label: context.l('بانتظار البحث', 'Waiting'), value: counts['due'], color: AppColors.warning, icon: Icons.hourglass_top_rounded),
        StatTile(label: context.l('لم توجد', 'Not found'), value: counts['none'], color: Colors.blueGrey, icon: Icons.search_off_rounded),
        StatTile(label: context.l('قصيرة (متجاوزة)', 'Short (skipped)'), value: counts['small'], color: Colors.blueGrey, icon: Icons.short_text_rounded),
        StatTile(label: context.l('خطأ', 'Error'), value: counts['error'], color: AppColors.danger, icon: Icons.error_outline_rounded),
      ]),
      GroupCard(title: context.l('الآن', 'Now'), children: [
        ListTile(
          leading: Icon(state['in_window'] == true ? Icons.play_circle_outline_rounded : Icons.pause_circle_outline_rounded,
              color: state['in_window'] == true ? AppColors.success : Colors.blueGrey),
          title: Text(state['in_window'] == true ? context.l('ضمن وقت البحث', 'Within search hours') : context.l('خارج وقت البحث', 'Outside search hours')),
        ),
        if (last != null)
          ListTile(
            leading: const Icon(Icons.history_rounded),
            title: Text(context.l('آخر تشغيل: فُحص ${last['checked'] ?? 0} ووُجد ${last['found'] ?? 0}', 'Last run: checked ${last['checked'] ?? 0}, found ${last['found'] ?? 0}')),
            subtitle: Text([
              if (last['books_checked'] != null) context.l('كتب الرفوف: ${last['books_found'] ?? 0} من ${last['books_checked']}', 'Shelf books: ${last['books_found'] ?? 0} of ${last['books_checked']}'),
              if (last['error'] != null) '${last['error']}',
              timeAgo(context, last['at'] as String?),
            ].join(' · ')),
          ),
        ListTile(
          leading: const Icon(Icons.shelves),
          title: Text(context.l('كتب الرفوف: لها غلاف ${books['with'] ?? 0}، بلا غلاف ${books['without'] ?? 0}', 'Shelf books: ${books['with'] ?? 0} with a cover, ${books['without'] ?? 0} without')),
          subtitle: Text(context.l('بانتظار البحث ${books['due'] ?? 0}', '${books['due'] ?? 0} waiting')),
        ),
      ]),
      GroupCard(title: context.l('إجراءات', 'Actions'), children: [
        NavRow(icon: Icons.play_arrow_rounded, color: AppColors.primary, label: context.l('ابحث الآن', 'Search now'), onTap: () => _send(() => _dio.post('/admin/covers/run'))),
        NavRow(icon: Icons.replay_rounded, color: AppColors.warning, label: context.l('أعد البحث عمّا لم يوجد', 'Look again for those not found'), onTap: () => _send(() => _dio.post('/admin/covers/retry'))),
      ]),
    ]);
  }

  Widget _filesTab(_Json d) {
    final files = Map<String, dynamic>.from(d['files'] as Map);
    final rows = ((files['data'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    final lastPage = (files['last_page'] as num?)?.toInt() ?? 1;
    final base = ApiClient.instance.baseUrl.replaceFirst(RegExp(r'/+$'), '');
    return Column(children: [
      SizedBox(
        height: 46,
        child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 10), children: [
          for (final s in const ['covers', 'found', 'manual', 'none', 'small', 'removed', 'error'])
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 3),
              child: ChoiceChip(
                label: Text(_showLabel(s)),
                selected: _show == s,
                onSelected: (_) {
                  setState(() => _show = s);
                  _load();
                },
              ),
            ),
        ]),
      ),
      Expanded(
        child: rows.isEmpty
            ? Center(child: Text(context.l('لا شيء هنا.', 'Nothing here.')))
            : ListView.separated(
                itemCount: rows.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (context, i) {
                  final f = rows[i];
                  final hasCover = f['source'] != null;
                  return ListTile(
                    leading: hasCover
                        ? AuthenticatedThumbnail(url: '$base/files/${f['id']}/thumbnail?v=${'${f['thumbnail_path']}'.hashCode}', fallbackIcon: Icons.menu_book_outlined, size: 44)
                        : const Icon(Icons.menu_book_outlined),
                    title: Text('${f['name']}', maxLines: 2, overflow: TextOverflow.ellipsis),
                    subtitle: Text([
                      if ('${f['folder'] ?? ''}'.isNotEmpty) '${f['folder']}',
                      if (_sourceLabel(f['source']).isNotEmpty) _sourceLabel(f['source']),
                      if (f['isbn'] != null) 'ISBN ${f['isbn']}',
                    ].join(' · ')),
                    trailing: hasCover
                        ? IconButton(
                            tooltip: context.l('إزالة الغلاف', 'Remove cover'),
                            icon: const Icon(Icons.hide_image_outlined),
                            onPressed: () async {
                              if (await confirmAction(context, context.l('إزالة غلاف هذا الملف؟', 'Remove this file\'s cover?'))) {
                                _send(() => _dio.delete('/admin/covers/files/${f['id']}'));
                              }
                            },
                          )
                        : null,
                  );
                },
              ),
      ),
      if (lastPage > 1)
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            IconButton(icon: const Icon(Icons.chevron_left_rounded), onPressed: _page > 1 ? () => _load(page: _page - 1) : null),
            Text('$_page / $lastPage', textDirection: TextDirection.ltr),
            IconButton(icon: const Icon(Icons.chevron_right_rounded), onPressed: _page < lastPage ? () => _load(page: _page + 1) : null),
          ]),
        ),
    ]);
  }
}

class _CoverSettingsForm extends StatefulWidget {
  const _CoverSettingsForm({required this.settings, required this.googleKeySet, required this.onSave});
  final _Json settings;
  final bool googleKeySet;
  final Future<void> Function(_Json data) onSave;

  @override
  State<_CoverSettingsForm> createState() => _CoverSettingsFormState();
}

class _CoverSettingsFormState extends State<_CoverSettingsForm> with AutomaticKeepAliveClientMixin {
  static const _numbers = [
    ('covers_batch', 'ملفات في كل دورة', 'Files per round'),
    ('covers_min_pages', 'أقل عدد صفحات للكتاب', 'Min pages for a book'),
    ('covers_retry_days', 'إعادة البحث بعد (يوم)', 'Look again after (days)'),
  ];
  static const _allKinds = ['pdf', 'epub', 'djvu', 'doc', 'docx'];

  bool _on(String k) => '${widget.settings[k]}' == '1';

  late final Map<String, TextEditingController> _c = {
    for (final n in _numbers) n.$1: TextEditingController(text: '${widget.settings[n.$1] ?? ''}'),
  };
  final _key = TextEditingController();
  late bool _enabled = _on('covers_enabled');
  late bool _library = _on('covers_library');
  late bool _title = _on('covers_title_search');
  late bool _openLibrary = _on('covers_openlibrary');
  late bool _google = _on('covers_google');
  late String _start = '${widget.settings['covers_start'] ?? '20:00'}';
  late String _end = '${widget.settings['covers_end'] ?? '06:00'}';
  late Set<int> _days = _parseDays(widget.settings['covers_days']);
  late Set<String> _kinds = '${widget.settings['covers_kinds'] ?? 'pdf,epub'}'.split(',').map((e) => e.trim()).where(_allKinds.contains).toSet();
  bool _clearKey = false;
  bool _busy = false;

  @override
  bool get wantKeepAlive => true;

  /// The server sends the days as a list (all seven when none is set).
  static Set<int> _parseDays(Object? v) {
    final list = v is List ? v : '$v'.split(',');
    final set = list.map((e) => int.tryParse('$e'.trim())).whereType<int>().toSet();
    return set.isEmpty ? {0, 1, 2, 3, 4, 5, 6} : set;
  }

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    _key.dispose();
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
    if (_kinds.isEmpty) {
      context.toast(context.l('اختر نوع ملف واحداً على الأقل.', 'Choose at least one file type.'), error: true);
      return;
    }
    setState(() => _busy = true);
    await widget.onSave({
      'covers_enabled': _enabled,
      'covers_library': _library,
      'covers_title_search': _title,
      'covers_openlibrary': _openLibrary,
      'covers_google': _google,
      'covers_days': _days.toList()..sort(),
      'covers_start': _start,
      'covers_end': _end,
      'covers_kinds': _kinds.toList(),
      for (final n in _numbers) n.$1: int.tryParse(_c[n.$1]!.text.trim()) ?? 0,
      if (_key.text.trim().isNotEmpty) 'covers_google_key': _key.text.trim(),
      if (_clearKey) 'covers_google_clear': true,
    });
    if (mounted) {
      setState(() {
        _busy = false;
        _key.clear();
        _clearKey = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    super.build(context);
    final names = [
      context.l('الأحد', 'Sun'),
      context.l('الاثنين', 'Mon'),
      context.l('الثلاثاء', 'Tue'),
      context.l('الأربعاء', 'Wed'),
      context.l('الخميس', 'Thu'),
      context.l('الجمعة', 'Fri'),
      context.l('السبت', 'Sat'),
    ];
    return ListView(padding: const EdgeInsets.fromLTRB(0, 4, 0, 32), children: [
      GroupCard(children: [
        SwitchListTile(value: _enabled, onChanged: (v) => setState(() => _enabled = v), title: Text(context.l('البحث التلقائي عن الأغلفة', 'Automatic cover search'))),
        SwitchListTile(
          value: _library,
          onChanged: (v) => setState(() => _library = v),
          title: Text(context.l('أغلفة كتب الرفوف أيضاً', 'Shelf books too')),
          subtitle: Text(context.l('تأخذ صورة ملف PDF المطابق أولاً (بدون إنترنت)، ثم يُبحث على الإنترنت.', 'The matching PDF\'s picture first (offline), then online.')),
        ),
        SwitchListTile(value: _title, onChanged: (v) => setState(() => _title = v), title: Text(context.l('البحث بالعنوان عند غياب ISBN', 'Search by title when there is no ISBN'))),
      ]),
      GroupCard(title: context.l('وقت البحث (بتوقيت الخادم)', 'Search hours (server time)'), children: [
        ListTile(title: Text(context.l('من', 'From')), trailing: TextButton(onPressed: () => _pickTime(true), child: Text(_start, textDirection: TextDirection.ltr))),
        ListTile(title: Text(context.l('إلى', 'To')), trailing: TextButton(onPressed: () => _pickTime(false), child: Text(_end, textDirection: TextDirection.ltr))),
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 6, 14, 10),
          child: Wrap(spacing: 6, runSpacing: 6, children: [
            for (var i = 0; i < 7; i++)
              FilterChip(label: Text(names[i]), selected: _days.contains(i), onSelected: (on) => setState(() => on ? _days.add(i) : _days.remove(i))),
          ]),
        ),
      ]),
      GroupCard(
        title: context.l('المصادر', 'Sources'),
        footer: context.l('المفتاح يُحفظ مشفّراً على الخادم ولا يُعرض بعد الحفظ.', 'The key is stored encrypted on the server and never shown again.'),
        children: [
          SwitchListTile(value: _openLibrary, onChanged: (v) => setState(() => _openLibrary = v), title: const Text('Open Library')),
          SwitchListTile(value: _google, onChanged: (v) => setState(() => _google = v), title: const Text('Google Books')),
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 4, 14, 12),
            child: TextField(
              controller: _key,
              obscureText: true,
              decoration: InputDecoration(
                isDense: true,
                labelText: context.l('مفتاح Google Books', 'Google Books key'),
                helperText: widget.googleKeySet ? context.l('يوجد مفتاح محفوظ. اكتب مفتاحاً جديداً لاستبداله.', 'A key is saved. Type a new one to replace it.') : null,
              ),
            ),
          ),
          if (widget.googleKeySet)
            CheckboxListTile(value: _clearKey, onChanged: (v) => setState(() => _clearKey = v == true), title: Text(context.l('حذف المفتاح المحفوظ', 'Delete the saved key'))),
        ],
      ),
      GroupCard(title: context.l('الملفات والحدود', 'Files and limits'), children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Wrap(spacing: 6, runSpacing: 6, children: [
              for (final k in _allKinds)
                FilterChip(label: Text(k.toUpperCase()), selected: _kinds.contains(k), onSelected: (on) => setState(() => on ? _kinds.add(k) : _kinds.remove(k))),
            ]),
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
        child: FilledButton.icon(onPressed: _busy ? null : _save, icon: const Icon(Icons.save_outlined), label: Text(context.l('حفظ الإعدادات', 'Save settings'))),
      ),
    ]);
  }
}
