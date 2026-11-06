import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/ui_kit.dart';
import '../../auth/state/auth_provider.dart';
import '../../site/site_api.dart';
import 'book_screen.dart';
import 'library_desk_screen.dart';
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
      _books.items.addAll((r['data'] as List).cast<Map<String, dynamic>>());
      _books.page = (meta['current_page'] as num).toInt();
      _books.lastPage = (meta['last_page'] as num).toInt();
      _total = (meta['total'] as num).toInt();
      if (_books.page == 1) _sections = (r['sections'] as List).cast<Map<String, dynamic>>();
    } catch (e) {
      _books.error = e.toString();
      if (mounted) context.toastError(e);
    } finally {
      if (mounted) setState(() => _books.loading = false);
    }
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
          if (user != null)
            IconButton(
              tooltip: context.l('استعاراتي', 'My loans'),
              icon: const Icon(Icons.bookmark_added_outlined),
              onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const MyLoansScreen())),
            ),
          if (staff)
            IconButton(
              tooltip: context.l('مكتب الإعارة', 'Lending desk'),
              icon: const Icon(Icons.qr_code_scanner_rounded),
              onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const LibraryDeskScreen())),
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
                  Center(child: Text(context.l('$_total كتاب', '$_total books'), style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant))),
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
