import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../app/theme/app_colors.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/ui_kit.dart';
import '../../auth/state/auth_provider.dart';
import '../../site/site_api.dart';
import 'book_screen.dart';
import 'manage/library_manage_screen.dart';
import 'library_map_screen.dart';
import '../data/library_map_cache.dart';
import '../../../core/storage/offline_cache.dart';
import 'library_widgets.dart';
import 'my_loans_screen.dart';

/// «المكتبة»: search the catalogue, browse sections, see what is on the shelf now.
class LibraryScreen extends StatefulWidget {
  const LibraryScreen({super.key, this.guest = false});

  /// Opened from the login screen without an account.
  final bool guest;

  @override
  State<LibraryScreen> createState() => _LibraryScreenState();
}

class _LibraryScreenState extends State<LibraryScreen> {
  final _search = TextEditingController();
  final _scroll = ScrollController();
  final _books = Paged<Map<String, dynamic>>();
  List<Map<String, dynamic>> _sections = [];
  final List<Map<String, dynamic>> _trail = [];
  bool _availableOnly = false;
  String _sort = 'title';
  int _total = 0;

  @override
  void initState() {
    super.initState();
    // Keep the saved library map current while the internet is there.
    LibraryMapCache.instance.refreshQuietly();
    _scroll.addListener(() {
      if (_scroll.position.pixels > _scroll.position.maxScrollExtent - 400) _load();
    });
    _load(reset: true);
  }

  @override
  void dispose() {
    _search.dispose();
    _scroll.dispose();
    super.dispose();
  }

  int? get _sectionId => _trail.isEmpty ? null : _trail.last['id'] as int;

  Future<void> _load({bool reset = false}) async {
    if (_books.loading || (!reset && !_books.hasMore)) return;
    if (reset) _books.reset();
    setState(() => _books.loading = true);
    try {
      final r = await SiteApi.instance.books(
        q: _search.text.trim(),
        section: _sectionId,
        available: _availableOnly,
        sort: _sort == 'new' ? 'new' : null,
        page: _books.page + 1,
      );
      final meta = r['meta'] as Map;
      _offline = r['_offline'] == true;
      _books.items.addAll((r['data'] as List).map((e) => Map<String, dynamic>.from(e as Map)));
      _books.page = (meta['current_page'] as num).toInt();
      _books.lastPage = (meta['last_page'] as num).toInt();
      _total = (meta['total'] as num).toInt();
      if (_books.page == 1) _sections = (r['sections'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      final plain = _search.text.trim().isEmpty && _sectionId == null && !_availableOnly && _sort != 'new';
      if (plain && _books.page == 1 && !_offline) _saveCatalogue(r);
    } catch (e) {
      if (reset && OfflineCache.isOffline(e) && await _fromCatalogue()) {
        _offline = true;
      } else {
        _books.error = e.toString();
        if (mounted) context.toastError(e);
      }
    } finally {
      if (mounted) setState(() => _books.loading = false);
    }
  }

  /// Shown from what is saved on the device (no internet).
  bool _offline = false;

  /// The whole catalogue kept on the device (2026-11-11, by request), so the library opens,
  /// searches and filters without internet. Refreshed at most every 6 hours.
  Future<void> _saveCatalogue(Map<String, dynamic> first) async {
    final saved = await OfflineCache.instance.get('library_catalogue');
    if (saved is Map && DateTime.now().difference(DateTime.tryParse('${saved['at']}') ?? DateTime(2000)).inHours < 6) return;
    try {
      final api = SiteApi.instance;
      final all = [...(first['data'] as List)];
      final last = ((first['meta'] as Map)['last_page'] as num).toInt();
      for (var p = 2; p <= last && p <= 200; p++) {
        all.addAll((await api.books(page: p))['data'] as List);
      }
      // The sections, as a tree: each one's parent, name and own sub-sections page.
      final tree = <String, dynamic>{};
      final queue = <Map>[...(first['sections'] as List).cast<Map>()];
      for (final s in queue) {
        tree['${s['id']}'] = {'parent': null, ...s};
      }
      while (queue.isNotEmpty) {
        final s = queue.removeAt(0);
        final r = await api.books(section: s['id'] as int);
        for (final c in (r['sections'] as List).cast<Map>()) {
          tree['${c['id']}'] = {'parent': s['id'], ...c};
          queue.add(c);
        }
      }
      await OfflineCache.instance.put('library_catalogue', {'at': DateTime.now().toIso8601String(), 'data': all, 'tree': tree});
    } catch (_) {}
  }

  /// The saved catalogue, searched and filtered here.
  Future<bool> _fromCatalogue() async {
    final saved = await OfflineCache.instance.get('library_catalogue');
    if (saved is! Map) return false;
    final tree = Map<String, dynamic>.from(saved['tree'] as Map);
    final current = _sectionId;
    Set<int>? within;
    if (current != null) {
      within = {current};
      var grew = true;
      while (grew) {
        grew = false;
        for (final e in tree.entries) {
          final parent = (e.value as Map)['parent'];
          if (parent != null && within.contains(parent) && within.add(int.parse(e.key))) grew = true;
        }
      }
    }
    final q = _search.text.trim().toLowerCase();
    var list = ((saved['data'] as List?) ?? []).map((e) => Map<String, dynamic>.from(e as Map)).where((b) {
      if (within != null && !within.contains((b['section'] as Map?)?['id'])) return false;
      if (_availableOnly && ((b['available_count'] as num?) ?? 0) == 0) return false;
      if (q.isEmpty) return true;
      return '${b['title']} ${b['authors'] ?? ''} ${b['isbn'] ?? ''}'.toLowerCase().contains(q);
    }).toList();
    if (_sort == 'new') list.sort((a, b) => ((b['id'] as num?) ?? 0).compareTo((a['id'] as num?) ?? 0));
    _books.items.addAll(list);
    _books.page = 1;
    _books.lastPage = 1;
    _total = list.length;
    _sections = tree.values.map((e) => Map<String, dynamic>.from(e as Map)).where((s) => s['parent'] == current).toList();
    return true;
  }

  void _openSection(Map<String, dynamic> s) {
    _trail.add(s);
    _load(reset: true);
  }

  void _upTo(int index) {
    _trail.removeRange(index, _trail.length);
    _load(reset: true);
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user;
    final staff = user?.manageLibrary ?? false;
    final width = MediaQuery.of(context).size.width;
    final columns = width > 900 ? 6 : width > 600 ? 4 : 3;

    return Scaffold(
      appBar: AppBar(
        title: Text(context.l('المكتبة', 'Library')),
        actions: [
          IconButton(
            tooltip: context.l('خريطة المكتبة', 'Library map'),
            icon: const Icon(Icons.map_outlined),
            onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const LibraryMapScreen())),
          ),
          if (user != null)
            IconButton(
              tooltip: context.l('استعاراتي', 'My loans'),
              icon: const Icon(Icons.bookmark_added_outlined),
              onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const MyLoansScreen())),
            ),
          if (staff)
            IconButton(
              tooltip: context.l('إدارة المكتبة', 'Library management'),
              icon: const Icon(Icons.admin_panel_settings_outlined),
              onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const LibraryManageScreen())),
            ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => _load(reset: true),
        child: CustomScrollView(
          controller: _scroll,
          slivers: [
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(12, 4, 12, 8),
                child: SearchField(
                  controller: _search,
                  hint: context.l('ابحث بالعنوان أو المؤلف أو ISBN...', 'Title, author or ISBN...'),
                  onSubmitted: (_) => _load(reset: true),
                ),
              ),
            ),
            SliverToBoxAdapter(
              child: SizedBox(
                height: 40,
                child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 12), children: [
                  FilterChip(
                    avatar: Icon(Icons.check_circle_outline_rounded, size: 18, color: _availableOnly ? Colors.white : null),
                    label: Text(context.l('المتوفر الآن', 'Available now')),
                    selected: _availableOnly,
                    showCheckmark: false,
                    selectedColor: Theme.of(context).colorScheme.primary,
                    labelStyle: TextStyle(color: _availableOnly ? Colors.white : null),
                    onSelected: (v) {
                      _availableOnly = v;
                      _load(reset: true);
                    },
                  ),
                  const SizedBox(width: 6),
                  ChoiceChip(
                    label: Text(_sort == 'new' ? context.l('الأحدث إضافة', 'Newest') : context.l('أبجدياً', 'A to Z')),
                    avatar: const Icon(Icons.sort_rounded, size: 18),
                    selected: false,
                    onSelected: (_) {
                      _sort = _sort == 'new' ? 'title' : 'new';
                      _load(reset: true);
                    },
                  ),
                  const SizedBox(width: 10),
                  Center(
                    child: Text(
                      _offline ? context.l('$_total كتاب · بدون إنترنت (المحفوظ على الجهاز)', '$_total books · offline (saved on this device)') : context.l('$_total كتاب', '$_total books'),
                      style: TextStyle(color: _offline ? AppColors.warning : Theme.of(context).colorScheme.onSurfaceVariant),
                    ),
                  ),
                ]),
              ),
            ),
            if (_trail.isNotEmpty)
              SliverToBoxAdapter(
                child: SizedBox(
                  height: 40,
                  child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 12), children: [
                    ActionChip(avatar: const Icon(Icons.home_outlined, size: 18), label: Text(context.l('كل الأقسام', 'All sections')), onPressed: () => _upTo(0)),
                    for (var i = 0; i < _trail.length; i++) ...[
                      const Icon(Icons.chevron_left_rounded, size: 18),
                      ActionChip(label: Text(_trail[i]['name'] ?? ''), onPressed: i == _trail.length - 1 ? null : () => _upTo(i + 1)),
                    ],
                  ]),
                ),
              ),
            if (_sections.isNotEmpty)
              SliverToBoxAdapter(
                child: SizedBox(
                  height: 84,
                  child: ListView.separated(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
                    itemCount: _sections.length,
                    separatorBuilder: (_, __) => const SizedBox(width: 8),
                    itemBuilder: (_, i) {
                      final s = _sections[i];
                      return Material(
                        color: Theme.of(context).colorScheme.surface,
                        borderRadius: BorderRadius.circular(14),
                        child: InkWell(
                          borderRadius: BorderRadius.circular(14),
                          onTap: () => _openSection(s),
                          child: Container(
                            width: 170,
                            padding: const EdgeInsets.all(10),
                            child: Row(children: [
                              const IconTile(icon: Icons.folder_rounded, color: Color(0xFFF5A623), size: 40),
                              const SizedBox(width: 10),
                              Expanded(
                                child: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.start, children: [
                                  Text(s['name'] ?? '', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600)),
                                  Text(context.l('${s['books_count']} كتاب', '${s['books_count']} books'), style: TextStyle(fontSize: 12, color: Theme.of(context).colorScheme.onSurfaceVariant)),
                                ]),
                              ),
                            ]),
                          ),
                        ),
                      );
                    },
                  ),
                ),
              ),
            if (_books.items.isEmpty && !_books.loading)
              SliverFillRemaining(
                hasScrollBody: false,
                child: EmptyState(icon: Icons.menu_book_outlined, message: context.l('لا توجد كتب مطابقة.', 'No matching books.')),
              )
            else
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(12, 8, 12, 24),
                sliver: SliverGrid(
                  gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: columns,
                    mainAxisSpacing: 14,
                    crossAxisSpacing: 10,
                    childAspectRatio: 0.44,
                  ),
                  delegate: SliverChildBuilderDelegate(
                    (_, i) => BookCard(
                      book: _books.items[i],
                      onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => BookScreen(bookId: _books.items[i]['id'] as int))),
                    ),
                    childCount: _books.items.length,
                  ),
                ),
              ),
            if (_books.loading)
              const SliverToBoxAdapter(child: Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator()))),
          ],
        ),
      ),
    );
  }
}
