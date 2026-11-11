import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../app/theme/app_colors.dart';
import '../../../core/widgets/ui_kit.dart';
import '../../auth/state/auth_provider.dart';
import '../../contact/presentation/contact_screen.dart';
import '../../site/site_api.dart';
import 'library_map_screen.dart';
import '../data/library_map_cache.dart';
import '../../../core/storage/offline_cache.dart';
import 'library_widgets.dart';

/// One book: is it on the shelf, hold it, its copies, ask for it.
class BookScreen extends StatefulWidget {
  const BookScreen({super.key, required this.bookId});

  final int bookId;

  @override
  State<BookScreen> createState() => _BookScreenState();
}

class _BookScreenState extends State<BookScreen> {
  Map<String, dynamic>? _data;
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final d = await SiteApi.instance.book(widget.bookId);
      if (mounted) setState(() => _data = d);
    } catch (e) {
      // Never opened before and no internet: built from the saved catalogue and map.
      final d = OfflineCache.isOffline(e) ? await _fromSaved() : null;
      if (mounted) setState(() => d != null ? _data = d : _error = e.toString());
    }
  }

  Future<Map<String, dynamic>?> _fromSaved() async {
    final cat = await OfflineCache.instance.get('library_catalogue');
    Map<String, dynamic>? card;
    for (final b in (cat is Map ? (cat['data'] as List? ?? []) : [])) {
      if (b is Map && b['id'] == widget.bookId) card = Map<String, dynamic>.from(b);
    }
    if (card == null) return null;
    Map? spot;
    final map = await LibraryMapCache.instance.saved();
    for (final b in (map?['books'] as List? ?? [])) {
      if (b is Map && b['id'] == widget.bookId && (b['spots'] as List? ?? []).isNotEmpty) spot = (b['spots'] as List).first as Map;
    }
    final total = ((card['copies_count'] as num?) ?? 0).toInt();
    final free = ((card['available_count'] as num?) ?? 0).toInt();
    final barcodes = ((spot?['barcodes'] as List?) ?? []).map((e) => '$e').toList();
    return {
      'book': {...card, 'section_path': (card['section'] as Map?)?['name']},
      'copies': [
        for (var i = 0; i < total; i++)
          {'barcode': i < barcodes.length ? barcodes[i] : '', 'status': i < free ? 'available' : 'loaned', 'shelf': spot?['code']},
      ],
      'map_spot': spot == null ? null : {'code': spot['code'], 'level': spot['level']},
      'my_hold': null,
      'queue_length': 0,
      'contact_enabled': false,
      '_offline': true,
    };
  }

  Future<void> _hold() async {
    setState(() => _busy = true);
    try {
      final r = await SiteApi.instance.hold(widget.bookId);
      if (mounted) context.toast(r['message'] ?? '');
      await _load();
    } catch (e) {
      if (mounted) context.toastError(e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _cancel(int holdId) async {
    setState(() => _busy = true);
    try {
      await SiteApi.instance.cancelHold(holdId);
      if (mounted) context.toast(context.l('تم إلغاء الحجز.', 'Hold cancelled.'));
      await _load();
    } catch (e) {
      if (mounted) context.toastError(e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _data;
    if (d == null) {
      return Scaffold(appBar: AppBar(), body: LoadingOrError(error: _error, onRetry: _load));
    }
    final book = Map<String, dynamic>.from(d['book'] as Map);
    final copies = (d['copies'] as List).cast<Map<String, dynamic>>();
    final hold = d['my_hold'] as Map?;
    final signedIn = context.watch<AuthProvider>().user != null;
    final available = copies.where((c) => c['status'] == 'available').length;
    final scheme = Theme.of(context).colorScheme;

    final details = <(String, String?)>[
      (context.l('المؤلف', 'Author'), book['authors']?.toString()),
      (context.l('الناشر', 'Publisher'), book['publisher']?.toString()),
      (context.l('السنة', 'Year'), book['year']?.toString()),
      (context.l('الطبعة', 'Edition'), book['edition']?.toString()),
      (context.l('الصفحات', 'Pages'), book['pages']?.toString()),
      (context.l('اللغة', 'Language'), book['language']?.toString()),
      ('ISBN', book['isbn']?.toString()),
      (context.l('القسم', 'Section'), book['section_path']?.toString()),
    ].where((e) => e.$2 != null && e.$2!.isNotEmpty).toList();

    final (stateText, stateSub, stateColor, stateIcon) = copies.isEmpty
        ? (context.l('غير موجود في المكتبة حالياً', 'Not in the library'), context.l('تقدر تطلبه من «تواصل معنا».', 'You can request it from Contact us.'), Colors.grey, Icons.block_rounded)
        : available > 0
            ? (context.l('متوفر الآن', 'Available now'), context.l('$available من ${copies.length} نسخة على الرف', '$available of ${copies.length} copies on the shelf'), AppColors.success, Icons.check_circle_rounded)
            : (context.l('غير متوفر الآن', 'Not available now'), context.l('كل النسخ (${copies.length}) مُعارة حالياً', 'All ${copies.length} copies are on loan'), AppColors.warning, Icons.schedule_rounded);

    return Scaffold(
      appBar: AppBar(title: Text(book['title'] ?? '', maxLines: 1, overflow: TextOverflow.ellipsis)),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(padding: const EdgeInsets.only(bottom: 32), children: [
          if (d['_offline'] == true)
            Container(
              margin: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(color: AppColors.warning.withOpacity(0.15), borderRadius: BorderRadius.circular(10)),
              child: Text(context.l('بدون إنترنت: المعلومات المحفوظة على الجهاز، وقد تكون تغيّرت.', 'Offline: the details saved on this device; they may have changed.'),
                  style: const TextStyle(fontSize: 12.5)),
            ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              BookCover(title: book['title'] ?? '', url: book['cover_url'] as String?, width: 120, radius: 12),
              const SizedBox(width: 16),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(book['title'] ?? '', style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w700, height: 1.3)),
                  if ((book['subtitle'] ?? '').toString().isNotEmpty)
                    Padding(padding: const EdgeInsets.only(top: 4), child: Text(book['subtitle'], style: TextStyle(color: scheme.onSurfaceVariant))),
                  if ((book['authors'] ?? '').toString().isNotEmpty)
                    Padding(padding: const EdgeInsets.only(top: 8), child: Text(book['authors'], style: const TextStyle(fontWeight: FontWeight.w500))),
                  if (book['reference_only'] == true)
                    Padding(padding: const EdgeInsets.only(top: 8), child: Pill(text: context.l('للقراءة داخل المكتبة', 'In-library reading'), color: Colors.blueGrey, icon: Icons.chair_alt_rounded)),
                ]),
              ),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(color: stateColor.withOpacity(0.12), borderRadius: BorderRadius.circular(16)),
              child: Row(children: [
                Icon(stateIcon, color: stateColor, size: 30),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(stateText, style: TextStyle(fontWeight: FontWeight.w700, color: stateColor, fontSize: 15)),
                    Text(stateSub, style: const TextStyle(fontSize: 13)),
                  ]),
                ),
              ]),
            ),
          ),
          // «مكانه على الخريطة»: its bookcase (and level), and the walk to it in 3D.
          if (d['map_spot'] is Map)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              child: FilledButton.tonalIcon(
                onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => LibraryMapScreen(bookId: widget.bookId))),
                icon: const Icon(Icons.location_on_outlined),
                label: Text(context.l(
                  'مكانه على الخريطة · الرف ${(d['map_spot'] as Map)['code']}${(d['map_spot'] as Map)['level'] != null ? ' · الطبقة ${(d['map_spot'] as Map)['level']}' : ''}',
                  'On the map · bookcase ${(d['map_spot'] as Map)['code']}${(d['map_spot'] as Map)['level'] != null ? ', level ${(d['map_spot'] as Map)['level']}' : ''}',
                )),
              ),
            ),
          const SizedBox(height: 12),
          if (book['reference_only'] != true && copies.isNotEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: !signedIn
                  ? OutlinedButton.icon(
                      onPressed: () => Navigator.of(context).popUntil((r) => r.isFirst),
                      icon: const Icon(Icons.login_rounded),
                      label: Text(context.l('سجّل الدخول حتى تحجز الكتاب', 'Sign in to hold this book')),
                    )
                  : hold != null
                      ? Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                          Text(
                            hold['status'] == 'ready'
                                ? context.l('كتابك المحجوز جاهز للاستلام حتى ${shortDate(hold['expires_at'])}', 'Your hold is ready until ${shortDate(hold['expires_at'])}')
                                : context.l('أنت في قائمة الانتظار لهذا الكتاب.', 'You are in the queue for this book.'),
                            style: TextStyle(fontWeight: FontWeight.w600, color: hold['status'] == 'ready' ? AppColors.success : null),
                          ),
                          const SizedBox(height: 8),
                          OutlinedButton.icon(
                            onPressed: _busy ? null : () => _cancel(hold['id'] as int),
                            icon: const Icon(Icons.close_rounded),
                            label: Text(context.l('إلغاء الحجز', 'Cancel hold')),
                          ),
                        ])
                      : FilledButton.icon(
                          onPressed: _busy ? null : _hold,
                          icon: const Icon(Icons.bookmark_add_rounded),
                          label: Text(available > 0
                              ? context.l('احجزه ليُحفظ لك في المكتبة', 'Hold it at the desk')
                              : context.l('احجزه وننبهك عند عودة نسخة${(d['queue_length'] ?? 0) > 0 ? ' (بالانتظار ${d['queue_length']})' : ''}', 'Join the queue')),
                        ),
            ),
          if (d['contact_enabled'] == true)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
              child: TextButton.icon(
                onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) => ContactScreen(aboutType: 'book', aboutId: widget.bookId, aboutTitle: book['title'], kind: 'book_request'),
                )),
                icon: const Icon(Icons.forum_outlined),
                label: Text(copies.isEmpty ? context.l('اطلب هذا الكتاب', 'Request this book') : context.l('استفسر عن الكتاب', 'Ask about this book')),
              ),
            ),
          if (details.isNotEmpty)
            GroupCard(
              title: context.l('المعلومات', 'Details'),
              children: details
                  .map((e) => ListTile(
                        dense: true,
                        title: Text(e.$1, style: TextStyle(color: scheme.onSurfaceVariant, fontSize: 13)),
                        trailing: ConstrainedBox(
                          constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.55),
                          child: Text(e.$2!, textAlign: TextAlign.end, style: const TextStyle(fontWeight: FontWeight.w500)),
                        ),
                      ))
                  .toList(),
            ),
          if ((book['description'] ?? '').toString().isNotEmpty)
            GroupCard(
              title: context.l('نبذة', 'About'),
              children: [Padding(padding: const EdgeInsets.all(14), child: Text(book['description'], style: const TextStyle(height: 1.6)))],
            ),
          GroupCard(
            title: context.l('النسخ (${copies.length})', 'Copies (${copies.length})'),
            children: copies.isEmpty
                ? [ListTile(title: Text(context.l('لا توجد نسخ', 'No copies')))]
                : copies
                    .map((c) => ListTile(
                          leading: Icon(Icons.qr_code_2_rounded, color: scheme.onSurfaceVariant),
                          title: Text(c['barcode'] ?? '', textDirection: TextDirection.ltr),
                          subtitle: (c['shelf'] ?? '').toString().isEmpty ? null : Text(context.l('الرف: ${c['shelf']}', 'Shelf: ${c['shelf']}')),
                          trailing: Pill(text: copyStatusLabel(context, c['status']), color: copyStatusColor(c['status'])),
                        ))
                    .toList(),
          ),
        ]),
      ),
    );
  }
}
