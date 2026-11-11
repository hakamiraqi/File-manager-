import 'package:flutter/material.dart';

import '../../../core/network/api_client.dart';
import 'package:provider/provider.dart';

import '../../../app/theme/app_colors.dart';
import '../../../core/models/folder_item.dart';
import '../../../core/storage/offline_cache.dart';
import '../../downloads/data/offline_download_service.dart';
import '../../downloads/state/downloads_provider.dart';
import '../../files/data/file_repository.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/ui_kit.dart';
import '../../auth/state/auth_provider.dart';
import '../../contact/presentation/contact_screen.dart';
import '../../files/presentation/browse_screen.dart';
import '../../site/site_api.dart';

/// «الرسائل والأطاريح»: search the catalogue by title, researcher, degree, year.
class ThesesScreen extends StatefulWidget {
  const ThesesScreen({super.key, this.contactEnabled = true});

  final bool contactEnabled;

  @override
  State<ThesesScreen> createState() => _ThesesScreenState();
}

class _ThesesScreenState extends State<ThesesScreen> {
  final _search = TextEditingController();
  final _paged = Paged<Map<String, dynamic>>();
  final Map<String, String> _filters = {};
  Map<String, dynamic> _facets = {};
  Map<String, dynamic> _degreeLabels = {};
  int _total = 0;

  @override
  void initState() {
    super.initState();
    _load(reset: true);
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  /// Shown from the list saved on the device (no internet).
  bool _offline = false;

  Future<void> _load({bool reset = false}) async {
    if (_paged.loading || (!reset && !_paged.hasMore)) return;
    if (reset) _paged.reset();
    setState(() => _paged.loading = true);
    final plain = _filters.isEmpty && _search.text.trim().isEmpty;
    try {
      final r = await SiteApi.instance.theses(filters: {..._filters, 'q': _search.text.trim()}, page: _paged.page + 1);
      _offline = false;
      _paged.items.addAll((r['data'] as List).cast<Map<String, dynamic>>());
      _paged.page = (r['meta']['current_page'] as num).toInt();
      _paged.lastPage = (r['meta']['last_page'] as num).toInt();
      _total = (r['meta']['total'] as num).toInt();
      if (r['facets'] is Map) _facets = Map<String, dynamic>.from(r['facets'] as Map);
      if (r['degree_labels'] is Map) _degreeLabels = Map<String, dynamic>.from(r['degree_labels'] as Map);
      // The whole list is kept for reading without internet (2026-11-11, by request).
      if (plain && _paged.page == 1) _saveAll(r);
    } catch (e) {
      if (reset && OfflineCache.isOffline(e) && await _fromSaved()) {
        _offline = true;
      } else {
        _paged.error = ApiErrorText.of(e);
      }
    } finally {
      if (mounted) setState(() => _paged.loading = false);
    }
  }

  /// Saves every page of the plain list (the first one is already here).
  Future<void> _saveAll(Map<String, dynamic> first) async {
    final all = [...(first['data'] as List)];
    final last = (first['meta']['last_page'] as num).toInt();
    try {
      for (var p = 2; p <= last && p <= 40; p++) {
        final r = await SiteApi.instance.theses(page: p);
        all.addAll(r['data'] as List);
      }
    } catch (_) {
      return;
    }
    await OfflineCache.instance.put('theses', {'data': all, 'facets': first['facets'], 'degree_labels': first['degree_labels']});
  }

  /// The saved list, searched and filtered here.
  Future<bool> _fromSaved() async {
    final saved = await OfflineCache.instance.get('theses');
    if (saved is! Map) return false;
    final q = _search.text.trim().toLowerCase();
    final list = ((saved['data'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).where((t) {
      for (final f in _filters.entries) {
        if ('${t[f.key] ?? ''}' != f.value) return false;
      }
      if (q.isEmpty) return true;
      return [t['title'], t['researcher'], t['supervisor'], t['keywords'], t['subject'], t['department']]
          .any((v) => v != null && '$v'.toLowerCase().contains(q));
    }).toList();
    _paged.items.addAll(list);
    _paged.page = 1;
    _paged.lastPage = 1;
    _total = list.length;
    if (saved['facets'] is Map) _facets = Map<String, dynamic>.from(saved['facets'] as Map);
    if (saved['degree_labels'] is Map) _degreeLabels = Map<String, dynamic>.from(saved['degree_labels'] as Map);
    return true;
  }

  /// «حفظ الرسالة للقراءة بدون إنترنت»: its folder list and every file in it.
  Future<void> _saveThesis(Map<String, dynamic> t) async {
    final offline = OfflineDownloadService.instance;
    final downloads = context.read<DownloadsProvider>();
    context.toast(context.l('جارٍ حفظ الرسالة على الجهاز…', 'Saving the thesis on this device…'));
    try {
      await offline.ensureLoaded();
      final result = await FileRepository().browse(folderId: t['id'] as int);
      var saved = 0;
      for (final f in result.files) {
        if (!offline.isDownloaded(f.id)) {
          await offline.download(f);
          saved++;
        }
      }
      downloads.load();
      if (mounted) {
        context.toast(saved == 0
            ? context.l('الرسالة محفوظة على الجهاز من قبل.', 'The thesis was already saved.')
            : context.l('حُفظت الرسالة ($saved ملف)، وتُفتح بدون إنترنت.', 'Saved ($saved files); it opens without internet.'));
      }
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  Map<String, dynamic> _facet(String key) => _facets[key] is Map ? Map<String, dynamic>.from(_facets[key] as Map) : {};

  void _setFilter(String key, String? value) {
    if (value == null || _filters[key] == value) {
      _filters.remove(key);
    } else {
      _filters[key] = value;
    }
    _load(reset: true);
  }

  Future<void> _pickFacet(String key, String title) async {
    final options = _facet(key);
    final picked = await showModalBottomSheet<String>(
      context: context,
      useRootNavigator: true,
      isScrollControlled: true,
      builder: (_) => SafeArea(
        child: ConstrainedBox(
          constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.7),
          child: ListView(shrinkWrap: true, children: [
            ListTile(title: Text(title, style: const TextStyle(fontWeight: FontWeight.w700))),
            ListTile(leading: const Icon(Icons.clear_all_rounded), title: Text(context.l('الكل', 'All')), onTap: () => Navigator.of(context).pop('')),
            ...options.entries.map((e) => ListTile(
                  title: Text(key == 'degree' ? '${_degreeLabels[e.key] ?? e.key}' : e.key),
                  trailing: CountBadge(count: (e.value as num).toInt(), color: Colors.grey),
                  selected: _filters[key] == e.key,
                  onTap: () => Navigator.of(context).pop(e.key),
                )),
          ]),
        ),
      ),
    );
    if (picked == null) return;
    _setFilter(key, picked.isEmpty ? null : picked);
  }

  void _open(Map<String, dynamic> t) {
    final signedIn = context.read<AuthProvider>().user != null;
    showModalBottomSheet<void>(
      context: context,
      useRootNavigator: true,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (sheet) => DraggableScrollableSheet(
        expand: false,
        initialChildSize: 0.75,
        maxChildSize: 0.95,
        builder: (_, scroll) => ListView(controller: scroll, padding: const EdgeInsets.fromLTRB(20, 0, 20, 24), children: [
          Text(t['title'] ?? '', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700, height: 1.4)),
          const SizedBox(height: 10),
          Wrap(spacing: 6, runSpacing: 6, children: [
            if (t['degree'] != null) Pill(text: '${_degreeLabels[t['degree']] ?? t['degree']}', color: const Color(0xFF7E57C2), icon: Icons.school_rounded),
            if (t['year'] != null) Pill(text: '${t['year']}', color: AppColors.primary, icon: Icons.event_rounded),
            if (t['department'] != null) Pill(text: '${t['department']}', color: const Color(0xFF00897B)),
          ]),
          const SizedBox(height: 12),
          for (final row in [
            (context.l('الباحث', 'Researcher'), t['researcher']),
            (context.l('المشرف', 'Supervisor'), t['supervisor']),
            (context.l('الجامعة', 'University'), t['university']),
            (context.l('الكلية', 'College'), t['college']),
            (context.l('الموضوع', 'Subject'), t['subject']),
            (context.l('عدد الصفحات', 'Pages'), t['pages']),
            (context.l('الكلمات المفتاحية', 'Keywords'), t['keywords']),
          ])
            if (row.$2 != null && '${row.$2}'.isNotEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 5),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  SizedBox(width: 110, child: Text(row.$1, style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant))),
                  Expanded(child: Text('${row.$2}', style: const TextStyle(fontWeight: FontWeight.w500))),
                ]),
              ),
          if ((t['abstract'] ?? '').toString().isNotEmpty) ...[
            const SizedBox(height: 10),
            Text(context.l('المستخلص', 'Abstract'), style: const TextStyle(fontWeight: FontWeight.w700)),
            const SizedBox(height: 4),
            Text(t['abstract'], style: const TextStyle(height: 1.6)),
          ],
          const SizedBox(height: 16),
          if (signedIn)
            OutlinedButton.icon(
              onPressed: () {
                Navigator.of(sheet).pop();
                Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) => BrowseScreen(folder: FolderItem(id: t['id'] as int, name: t['title'] ?? '', parentId: null, color: null, isPublic: false, folderType: 'thesis')),
                ));
              },
              icon: const Icon(Icons.folder_open_rounded),
              label: Text(context.l('فتح ملفات الرسالة', 'Open the files')),
            ),
          if (signedIn)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: OutlinedButton.icon(
                onPressed: () {
                  Navigator.of(sheet).pop();
                  _saveThesis(t);
                },
                icon: const Icon(Icons.download_for_offline_outlined),
                label: Text(context.l('حفظ الرسالة للقراءة بدون إنترنت', 'Save for reading offline')),
              ),
            ),
          if (widget.contactEnabled)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: FilledButton.tonalIcon(
                onPressed: () {
                  Navigator.of(sheet).pop();
                  Navigator.of(context).push(MaterialPageRoute(
                    builder: (_) => ContactScreen(aboutType: 'thesis', aboutId: t['id'] as int, aboutTitle: t['title'], kind: 'thesis_request'),
                  ));
                },
                icon: const Icon(Icons.forum_outlined),
                label: Text(context.l('اطلب هذه الرسالة', 'Request this thesis')),
              ),
            ),
        ]),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    String chipLabel(String key, String fallback) {
      final v = _filters[key];
      if (v == null) return fallback;
      return key == 'degree' ? '${_degreeLabels[v] ?? v}' : v;
    }

    return Scaffold(
      appBar: AppBar(title: Text(context.l('الرسائل والأطاريح', 'Theses'))),
      body: NotificationListener<ScrollNotification>(
        onNotification: (n) {
          if (n.metrics.axis == Axis.vertical && n.metrics.pixels > n.metrics.maxScrollExtent - 300) _load();
          return false;
        },
        child: RefreshIndicator(
          onRefresh: () => _load(reset: true),
          child: ListView(padding: const EdgeInsets.only(bottom: 24), children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 4, 12, 8),
              child: SearchField(controller: _search, hint: context.l('العنوان، الباحث، المشرف أو كلمة مفتاحية', 'Title, researcher, supervisor or keyword'), onSubmitted: (_) => _load(reset: true)),
            ),
            SizedBox(
              height: 40,
              child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 12), children: [
                for (final f in [
                  ('degree', context.l('الدرجة', 'Degree'), Icons.school_outlined),
                  ('year', context.l('السنة', 'Year'), Icons.event_outlined),
                  ('department', context.l('القسم', 'Department'), Icons.account_tree_outlined),
                  ('subject', context.l('الموضوع', 'Subject'), Icons.topic_outlined),
                ])
                  if (_facet(f.$1).isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 3),
                      child: FilterChip(
                        avatar: Icon(f.$3, size: 18, color: _filters.containsKey(f.$1) ? Colors.white : null),
                        label: Text(chipLabel(f.$1, f.$2)),
                        selected: _filters.containsKey(f.$1),
                        showCheckmark: false,
                        selectedColor: AppColors.primary,
                        labelStyle: TextStyle(color: _filters.containsKey(f.$1) ? Colors.white : null),
                        onSelected: (_) => _pickFacet(f.$1, f.$2),
                      ),
                    ),
                const SizedBox(width: 8),
                Center(child: Text(context.l('$_total رسالة', '$_total theses'), style: TextStyle(color: scheme.onSurfaceVariant))),
              ]),
            ),
            if (_offline)
              Container(
                margin: const EdgeInsets.fromLTRB(12, 8, 12, 0),
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(color: AppColors.warning.withOpacity(0.15), borderRadius: BorderRadius.circular(10)),
                child: Text(context.l('بدون إنترنت: قائمة الرسائل المحفوظة على الجهاز.', 'Offline: the theses list saved on this device.'), style: const TextStyle(fontSize: 12.5)),
              ),
            if (_paged.items.isEmpty && !_paged.loading)
              Padding(
                padding: const EdgeInsets.only(top: 60),
                child: EmptyState(icon: Icons.school_outlined, message: _paged.error ?? context.l('لا توجد رسائل مطابقة.', 'No matching theses.')),
              ),
            ..._paged.items.map((t) => Padding(
                  padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
                  child: Material(
                    color: scheme.surface,
                    borderRadius: BorderRadius.circular(16),
                    child: InkWell(
                      borderRadius: BorderRadius.circular(16),
                      onTap: () => _open(t),
                      child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          const IconTile(icon: Icons.school_rounded, color: Color(0xFF7E57C2), size: 44),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Text(t['title'] ?? '', maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600, height: 1.35)),
                              const SizedBox(height: 3),
                              Text([t['researcher'], t['department']].where((v) => v != null && '$v'.isNotEmpty).join(' · '),
                                  maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12.5, color: scheme.onSurfaceVariant)),
                              const SizedBox(height: 6),
                              Wrap(spacing: 6, children: [
                                if (t['degree'] != null) Pill(text: '${_degreeLabels[t['degree']] ?? t['degree']}', color: const Color(0xFF7E57C2)),
                                if (t['year'] != null) Pill(text: '${t['year']}', color: AppColors.primary),
                              ]),
                            ]),
                          ),
                        ]),
                      ),
                    ),
                  ),
                )),
            if (_paged.loading) const Padding(padding: EdgeInsets.all(20), child: Center(child: CircularProgressIndicator())),
          ]),
        ),
      ),
    );
  }
}

/// Server message for an error, or its text.
abstract class ApiErrorText {
  static String of(Object e) {
    try {
      final data = (e as dynamic).response?.data;
      if (data is Map && data['message'] is String) return data['message'] as String;
    } catch (_) {}
    return ApiClient.messageFor(e);
  }
}
