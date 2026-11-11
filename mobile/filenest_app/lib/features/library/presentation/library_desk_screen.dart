import 'package:flutter/material.dart';

import '../../../core/network/api_client.dart';

import '../../../app/theme/app_colors.dart';
import '../../../core/widgets/empty_state.dart';
import '../../../core/widgets/ui_kit.dart';
import '../../site/site_api.dart';
import 'library_widgets.dart';

/// The librarian's desk in the app: scan or type a reader card or a book code to
/// lend and receive, plus loans, overdue, fines and the holds queue.
class LibraryDeskScreen extends StatefulWidget {
  const LibraryDeskScreen({super.key});

  @override
  State<LibraryDeskScreen> createState() => _LibraryDeskScreenState();
}

class _LibraryDeskScreenState extends State<LibraryDeskScreen> {
  Map<String, dynamic>? _stats;

  @override
  void initState() {
    super.initState();
    _loadStats();
  }

  Future<void> _loadStats() async {
    try {
      final s = await SiteApi.instance.libraryStats();
      if (mounted) setState(() => _stats = s);
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 3,
      child: Scaffold(
        appBar: AppBar(
          title: Text(context.l('مكتب الإعارة', 'Lending desk')),
          bottom: TabBar(tabs: [
            Tab(icon: const Icon(Icons.qr_code_scanner_rounded), text: context.l('الكاونتر', 'Counter')),
            Tab(icon: const Icon(Icons.swap_horiz_rounded), text: context.l('الإعارات', 'Loans')),
            Tab(icon: const Icon(Icons.bookmark_outline_rounded), text: context.l('الحجوزات', 'Holds')),
          ]),
        ),
        body: TabBarView(children: [
          _CounterTab(stats: _stats, onChanged: _loadStats),
          const _LoansTab(),
          const _HoldsTab(),
        ]),
      ),
    );
  }
}

class _CounterTab extends StatefulWidget {
  const _CounterTab({required this.stats, required this.onChanged});

  final Map<String, dynamic>? stats;
  final VoidCallback onChanged;

  @override
  State<_CounterTab> createState() => _CounterTabState();
}

class _CounterTabState extends State<_CounterTab> {
  final _code = TextEditingController();
  final _focus = FocusNode();
  Map<String, dynamic>? _member;
  bool _returnOnly = false;
  bool _busy = false;
  final List<(IconData, Color, String)> _log = [];

  @override
  void dispose() {
    _code.dispose();
    _focus.dispose();
    super.dispose();
  }

  void _addLog(IconData icon, Color color, String text) {
    setState(() => _log.insert(0, (icon, color, text)));
  }

  Future<void> _scan() async {
    final code = _code.text.trim();
    if (code.isEmpty) return;
    setState(() => _busy = true);
    try {
      final r = await SiteApi.instance.scan(code, memberId: _member?['id'] as int?, mode: _returnOnly ? 'return' : 'auto');
      if (!mounted) return;
      switch (r['kind']) {
        case 'member':
          setState(() => _member = Map<String, dynamic>.from(r['member'] as Map));
          _addLog(Icons.badge_outlined, AppColors.primary, context.l('القارئ: ${_member!['name']}', 'Reader: ${_member!['name']}'));
          break;
        case 'issued':
          setState(() => _member = Map<String, dynamic>.from(r['member'] as Map));
          final title = r['loan']?['copy']?['book']?['title'] ?? code;
          _addLog(Icons.north_east_rounded, AppColors.success, context.l('أُعير: $title', 'Lent: $title'));
          break;
        case 'returned':
          final held = r['held_for'];
          setState(() => _member = r['member'] == null ? _member : Map<String, dynamic>.from(r['member'] as Map));
          _addLog(Icons.south_west_rounded, AppColors.primary,
              context.l('أُرجع: $code${held != null ? ' — محجوز لـ ${held['name']}، ضعه جانباً' : ''}', 'Returned: $code${held != null ? ' — held for ${held['name']}' : ''}'));
          break;
      }
      widget.onChanged();
    } catch (e) {
      _addLog(Icons.error_outline_rounded, AppColors.danger, ApiClientMessage.of(e));
    } finally {
      _code.clear();
      if (mounted) setState(() => _busy = false);
      _focus.requestFocus();
    }
  }

  Future<void> _pickMember() async {
    final picked = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      useRootNavigator: true,
      builder: (_) => const _MemberSearchSheet(),
    );
    if (picked == null) return;
    try {
      final r = await SiteApi.instance.pickMember(memberId: picked['id'] as int?, userId: picked['user_id'] as int?);
      setState(() => _member = Map<String, dynamic>.from(r['member'] as Map));
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.stats;
    final tiles = s == null
        ? <(String, Object?, Color, IconData)>[]
        : [
            (context.l('مُعار الآن', 'On loan'), s['active_loans'], AppColors.primary, Icons.swap_horiz_rounded),
            (context.l('متأخر', 'Overdue'), s['overdue'], AppColors.danger, Icons.warning_amber_rounded),
            (context.l('جاهز للاستلام', 'Holds ready'), s['holds_ready'], AppColors.success, Icons.bookmark_added_rounded),
            (context.l('متاح على الرف', 'On shelf'), s['available'], const Color(0xFF7E57C2), Icons.menu_book_rounded),
            (context.l('أُعير اليوم', 'Lent today'), s['issued_today'], const Color(0xFF00897B), Icons.north_east_rounded),
            (context.l('أُرجع اليوم', 'Returned today'), s['returned_today'], const Color(0xFFEF6C00), Icons.south_west_rounded),
          ];

    return ListView(padding: const EdgeInsets.only(bottom: 24), children: [
      if (tiles.isNotEmpty)
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 12, 12, 0),
          child: GridView.count(
            crossAxisCount: 3,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            mainAxisSpacing: 8,
            crossAxisSpacing: 8,
            childAspectRatio: 1.35,
            children: tiles
                .map((t) => Container(
                      padding: const EdgeInsets.all(10),
                      decoration: BoxDecoration(color: Theme.of(context).colorScheme.surface, borderRadius: BorderRadius.circular(14)),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                        Icon(t.$4, color: t.$3, size: 20),
                        Text('${t.$2 ?? 0}', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                        Text(t.$1, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 11.5, color: Theme.of(context).colorScheme.onSurfaceVariant)),
                      ]),
                    ))
                .toList(),
          ),
        ),
      GroupCard(
        title: context.l('القارئ', 'Reader'),
        children: [
          _member == null
              ? ListTile(
                  leading: const IconTile(icon: Icons.person_search_rounded, color: AppColors.primary),
                  title: Text(context.l('امسح بطاقة القارئ أو ابحث عنه', 'Scan a reader card or search')),
                  onTap: _pickMember,
                )
              : ListTile(
                  leading: Avatar(name: _member!['name'] ?? ''),
                  title: Text(_member!['name'] ?? '', style: const TextStyle(fontWeight: FontWeight.w600)),
                  subtitle: Text([
                    _member!['card_number'],
                    context.l('${(_member!['loans'] as List?)?.length ?? 0} كتاب معه', '${(_member!['loans'] as List?)?.length ?? 0} books'),
                    if (((_member!['fines_due'] as num?) ?? 0) > 0) context.l('غرامات ${_member!['fines_due']} د.ع', 'fines ${_member!['fines_due']}'),
                  ].whereType<Object>().join(' · ')),
                  trailing: IconButton(icon: const Icon(Icons.close_rounded), onPressed: () => setState(() => _member = null)),
                ),
          if (_member != null)
            for (final l in ((_member!['loans'] as List?) ?? []).cast<Map>())
              ListTile(
                dense: true,
                leading: Icon(Icons.menu_book_rounded, color: l['is_overdue'] == true ? AppColors.danger : null),
                title: Text(l['title'] ?? ''),
                subtitle: Text('${l['barcode'] ?? ''} · ${context.l('يُرجع', 'due')} ${l['due_at'] ?? ''}'),
              ),
        ],
      ),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          SegmentedButton<bool>(
            segments: [
              ButtonSegment(value: false, label: Text(context.l('إعارة وإرجاع', 'Lend & return')), icon: const Icon(Icons.swap_horiz_rounded)),
              ButtonSegment(value: true, label: Text(context.l('إرجاع فقط', 'Return only')), icon: const Icon(Icons.south_west_rounded)),
            ],
            selected: {_returnOnly},
            onSelectionChanged: (v) => setState(() => _returnOnly = v.first),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _code,
            focusNode: _focus,
            autofocus: true,
            textInputAction: TextInputAction.done,
            onSubmitted: (_) => _scan(),
            decoration: InputDecoration(
              hintText: context.l('امسح الباركود أو RFID أو اكتب الرمز', 'Scan barcode / RFID or type a code'),
              prefixIcon: const Icon(Icons.qr_code_2_rounded),
              suffixIcon: _busy
                  ? const Padding(padding: EdgeInsets.all(12), child: SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)))
                  : IconButton(icon: const Icon(Icons.send_rounded), onPressed: _scan),
            ),
          ),
          const SizedBox(height: 6),
          Text(
            context.l('ماسح الباركود أو قارئ RFID المتصل بالجهاز يكتب هنا مباشرة. بطاقة القارئ تختاره، والكتاب المُعار يُرجع، والكتاب المتاح يُعار للقارئ المختار.',
                'A connected barcode/RFID reader types here. A reader card selects the reader; a lent copy is returned; an available copy is lent.'),
            style: TextStyle(fontSize: 12, color: Theme.of(context).colorScheme.onSurfaceVariant),
          ),
        ]),
      ),
      if (_log.isNotEmpty)
        GroupCard(
          title: context.l('آخر العمليات', 'Recent'),
          children: _log.take(15).map((e) => ListTile(dense: true, leading: Icon(e.$1, color: e.$2), title: Text(e.$3))).toList(),
        ),
    ]);
  }
}

/// Error text without importing the network layer here.
abstract class ApiClientMessage {
  static String of(Object e) {
    try {
      // ignore: avoid_dynamic_calls
      final data = (e as dynamic).response?.data;
      if (data is Map && data['message'] is String) {
        final errors = data['errors'];
        if (errors is Map && errors.isNotEmpty && errors.values.first is List) return (errors.values.first as List).first.toString();
        return data['message'] as String;
      }
    } catch (_) {}
    return ApiClient.messageFor(e);
  }
}

class _MemberSearchSheet extends StatefulWidget {
  const _MemberSearchSheet();

  @override
  State<_MemberSearchSheet> createState() => _MemberSearchSheetState();
}

class _MemberSearchSheetState extends State<_MemberSearchSheet> {
  final _q = TextEditingController();
  List<Map<String, dynamic>> _results = [];

  Future<void> _search(String q) async {
    if (q.trim().length < 2) return;
    try {
      final r = await SiteApi.instance.findMembers(q.trim());
      if (mounted) setState(() => _results = r.cast<Map<String, dynamic>>());
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.7,
        child: Column(children: [
          Padding(
            padding: const EdgeInsets.all(16),
            child: SearchField(controller: _q, hint: context.l('الاسم، رقم البطاقة، البريد أو الهاتف', 'Name, card, e-mail or phone'), onSubmitted: _search),
          ),
          Expanded(
            child: ListView(
              children: _results
                  .map((m) => ListTile(
                        leading: Avatar(name: m['name'] ?? ''),
                        title: Text(m['name'] ?? ''),
                        subtitle: Text(m['card_number'] ?? m['email'] ?? ''),
                        trailing: m['id'] == null ? Pill(text: context.l('مستخدم', 'User'), color: AppColors.primary) : null,
                        onTap: () => Navigator.of(context).pop(m),
                      ))
                  .toList(),
            ),
          ),
        ]),
      ),
    );
  }
}

class _LoansTab extends StatefulWidget {
  const _LoansTab();

  @override
  State<_LoansTab> createState() => _LoansTabState();
}

class _LoansTabState extends State<_LoansTab> {
  final _paged = Paged<Map<String, dynamic>>();
  final _search = TextEditingController();
  String _filter = 'active';
  Map<String, dynamic> _counts = {};

  @override
  void initState() {
    super.initState();
    _load(reset: true);
  }

  Future<void> _load({bool reset = false}) async {
    if (_paged.loading || (!reset && !_paged.hasMore)) return;
    if (reset) _paged.reset();
    setState(() => _paged.loading = true);
    try {
      final r = await SiteApi.instance.loans(filter: _filter, q: _search.text.trim(), page: _paged.page + 1);
      _paged.items.addAll((r['data'] as List).cast<Map<String, dynamic>>());
      _paged.page = (r['meta']['current_page'] as num).toInt();
      _paged.lastPage = (r['meta']['last_page'] as num).toInt();
      _counts = Map<String, dynamic>.from(r['counts'] as Map);
    } catch (e) {
      if (mounted) context.toastError(e);
    } finally {
      if (mounted) setState(() => _paged.loading = false);
    }
  }

  Future<void> _fine(Map<String, dynamic> l, bool pay) async {
    try {
      final r = pay ? await SiteApi.instance.payFine(l['id'] as int) : await SiteApi.instance.waiveFine(l['id'] as int);
      if (mounted) context.toast(r['message'] ?? '');
      _load(reset: true);
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 6),
        child: SearchField(controller: _search, hint: context.l('القارئ، رقم البطاقة، الكتاب أو الباركود', 'Reader, card, book or barcode'), onSubmitted: (_) => _load(reset: true)),
      ),
      FilterStrip(
        items: {
          'active': context.l('الحالية ${_counts['active'] ?? ''}', 'Active ${_counts['active'] ?? ''}'),
          'overdue': context.l('المتأخرة ${_counts['overdue'] ?? ''}', 'Overdue ${_counts['overdue'] ?? ''}'),
          'fines': context.l('غرامات ${_counts['fines'] ?? ''}', 'Fines ${_counts['fines'] ?? ''}'),
          'returned': context.l('المُرجعة', 'Returned'),
        },
        selected: _filter,
        onSelected: (k) {
          _filter = k;
          _load(reset: true);
        },
      ),
      Expanded(
        child: NotificationListener<ScrollNotification>(
          onNotification: (n) {
            if (n.metrics.pixels > n.metrics.maxScrollExtent - 300) _load();
            return false;
          },
          child: RefreshIndicator(
            onRefresh: () => _load(reset: true),
            child: _paged.items.isEmpty && !_paged.loading
                ? ListView(children: [const SizedBox(height: 60), EmptyState(icon: Icons.inbox_outlined, message: context.l('لا توجد إعارات هنا.', 'Nothing here.'))])
                : ListView.builder(
                    padding: const EdgeInsets.all(12),
                    itemCount: _paged.items.length,
                    itemBuilder: (_, i) {
                      final l = _paged.items[i];
                      final book = Map<String, dynamic>.from(l['book'] as Map);
                      final member = l['member'] as Map?;
                      final overdue = l['is_overdue'] == true;
                      final owes = ((l['fine'] as num?) ?? 0) > 0 && l['fine_paid'] != true && l['fine_waived'] != true;
                      return Card(
                        margin: const EdgeInsets.only(bottom: 8),
                        child: Padding(
                          padding: const EdgeInsets.all(10),
                          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            BookCover(title: book['title'] ?? '', url: book['cover_url'] as String?, width: 44, radius: 6),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                Text(book['title'] ?? '', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600)),
                                Text('${member?['name'] ?? ''} · ${member?['card_number'] ?? ''}', style: const TextStyle(fontSize: 12.5)),
                                const SizedBox(height: 4),
                                Wrap(spacing: 6, runSpacing: 4, children: [
                                  if (l['returned_at'] == null)
                                    Pill(
                                      text: overdue ? context.l('متأخر ${l['days_overdue']} يوم', '${l['days_overdue']}d overdue') : context.l('يُرجع ${shortDate(l['due_at'])}', 'Due ${shortDate(l['due_at'])}'),
                                      color: overdue ? AppColors.danger : AppColors.primary,
                                    )
                                  else
                                    Pill(text: context.l('أُرجع ${shortDate(l['returned_at'])}', 'Returned ${shortDate(l['returned_at'])}'), color: Colors.grey),
                                  if (owes) Pill(text: context.l('غرامة ${l['fine']} د.ع', 'Fine ${l['fine']}'), color: AppColors.danger),
                                ]),
                                if (owes && l['returned_at'] != null)
                                  Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                                    TextButton(onPressed: () => _fine(l, false), child: Text(context.l('إعفاء', 'Waive'))),
                                    TextButton(onPressed: () => _fine(l, true), child: Text(context.l('استيفاء', 'Paid'))),
                                  ]),
                              ]),
                            ),
                          ]),
                        ),
                      );
                    },
                  ),
          ),
        ),
      ),
    ]);
  }
}

class _HoldsTab extends StatefulWidget {
  const _HoldsTab();

  @override
  State<_HoldsTab> createState() => _HoldsTabState();
}

class _HoldsTabState extends State<_HoldsTab> {
  List<Map<String, dynamic>>? _holds;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await SiteApi.instance.holdsQueue();
      if (mounted) setState(() => _holds = r.cast<Map<String, dynamic>>());
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  Future<void> _cancel(int id) async {
    try {
      await SiteApi.instance.cancelHold(id);
      _load();
    } catch (e) {
      if (mounted) context.toastError(e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final holds = _holds;
    if (holds == null) return const Center(child: CircularProgressIndicator());
    return RefreshIndicator(
      onRefresh: _load,
      child: holds.isEmpty
          ? ListView(children: [const SizedBox(height: 60), EmptyState(icon: Icons.bookmark_border_rounded, message: context.l('لا توجد حجوزات مفتوحة.', 'No open holds.'))])
          : ListView(
              padding: const EdgeInsets.all(12),
              children: holds.map((h) {
                final ready = h['status'] == 'ready';
                final book = Map<String, dynamic>.from(h['book'] as Map);
                return Card(
                  margin: const EdgeInsets.only(bottom: 8),
                  child: ListTile(
                    leading: BookCover(title: book['title'] ?? '', url: book['cover_url'] as String?, width: 36, radius: 5),
                    title: Text(book['title'] ?? ''),
                    subtitle: Text('${h['member']?['name'] ?? ''}${h['barcode'] != null ? ' · ${h['barcode']}' : ''}'),
                    trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                      Pill(text: ready ? context.l('جاهز', 'Ready') : context.l('بالانتظار', 'Waiting'), color: ready ? AppColors.success : AppColors.primary),
                      IconButton(icon: const Icon(Icons.close_rounded), onPressed: () => _cancel(h['id'] as int)),
                    ]),
                  ),
                );
              }).toList(),
            ),
    );
  }
}
