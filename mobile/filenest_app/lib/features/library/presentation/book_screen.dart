import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/network/api_client.dart';
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
      if (mounted) setState(() => d != null ? _data = d : _error = ApiClient.messageFor(e));
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
                  if ((book['journal'] ?? '').toString().isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: Text(
                        [
                          book['journal'],
                          '${book['volume'] ?? ''}${(book['issue'] ?? '').toString().isNotEmpty ? '(${book['issue']})' : ''}',
                          (book['page_range'] ?? '').toString(),
                        ].where((s) => s.toString().isNotEmpty).join(', '),
                        textDirection: TextDirection.ltr,
                        style: TextStyle(fontStyle: FontStyle.italic, color: scheme.onSurfaceVariant, fontSize: 13),
                      ),
                    ),
                  if ((book['doi'] ?? '').toString().isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 10),
                      child: InkWell(
                        borderRadius: BorderRadius.circular(20),
                        onTap: () => launchUrl(Uri.parse('https://doi.org/${book['doi']}'), mode: LaunchMode.externalApplication),
                        child: Container(
                          padding: const EdgeInsetsDirectional.fromSTEB(4, 4, 10, 4),
                          decoration: BoxDecoration(color: scheme.primary.withOpacity(0.08), borderRadius: BorderRadius.circular(20)),
                          child: Row(mainAxisSize: MainAxisSize.min, textDirection: TextDirection.ltr, children: [
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                              decoration: BoxDecoration(color: scheme.primary, borderRadius: BorderRadius.circular(12)),
                              child: const Text('DOI', style: TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w800, letterSpacing: 0.5)),
                            ),
                            const SizedBox(width: 6),
                            Flexible(
                              child: Text(book['doi'], overflow: TextOverflow.ellipsis, style: TextStyle(fontFamily: 'monospace', fontSize: 11.5, color: scheme.primary)),
                            ),
                            const SizedBox(width: 4),
                            Icon(Icons.open_in_new_rounded, size: 14, color: scheme.primary),
                          ]),
                        ),
                      ),
                    ),
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
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 0),
            child: OutlinedButton.icon(
              onPressed: () => showModalBottomSheet(
                context: context,
                isScrollControlled: true,
                showDragHandle: true,
                builder: (_) => _CiteSheet(bookId: widget.bookId, title: book['title'] ?? ''),
              ),
              icon: const Icon(Icons.format_quote_rounded),
              label: Text(context.l('استشهاد (APA · Vancouver)', 'Cite (APA · Vancouver)')),
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

/// «استشهاد»: the book as a reference, to copy into a thesis or send to EndNote / Zotero.
class _CiteSheet extends StatefulWidget {
  const _CiteSheet({required this.bookId, required this.title});
  final int bookId;
  final String title;

  @override
  State<_CiteSheet> createState() => _CiteSheetState();
}

class _CiteSheetState extends State<_CiteSheet> {
  late final Future<Json> _cite = SiteApi.instance.cite(widget.bookId);
  String _style = 'apa';
  bool _copied = false;

  static const _styles = {'apa': 'APA 7', 'vancouver': 'Vancouver', 'bibtex': 'BibTeX', 'ris': 'RIS'};

  String _text(Json d) {
    final v = d[_style];
    return v is Map ? (v['text'] ?? '').toString() : (v ?? '').toString();
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        child: FutureBuilder<Json>(
          future: _cite,
          builder: (context, snap) {
            if (snap.hasError) {
              return Padding(padding: const EdgeInsets.all(24), child: Text(ApiClient.messageFor(snap.error!)));
            }
            if (!snap.hasData) {
              return const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator()));
            }
            final d = snap.data!;
            final text = _text(d);
            final code = _style == 'bibtex' || _style == 'ris';
            return Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text(context.l('استشهاد', 'Cite'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
              Text(widget.title, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: scheme.onSurfaceVariant)),
              const SizedBox(height: 12),
              Directionality(
                textDirection: TextDirection.ltr,
                child: SegmentedButton<String>(
                  showSelectedIcon: false,
                  segments: [for (final e in _styles.entries) ButtonSegment(value: e.key, label: Text(e.value, style: const TextStyle(fontSize: 12.5)))],
                  selected: {_style},
                  onSelectionChanged: (s) => setState(() {
                    _style = s.first;
                    _copied = false;
                  }),
                ),
              ),
              const SizedBox(height: 6),
              Text(
                switch (_style) {
                  'apa' => context.l('الأكثر استخداماً في الرسائل والبحوث', 'Most used in theses and papers'),
                  'vancouver' => context.l('أسلوب المجلات الطبية وطب الأسنان', 'The style of medical and dental journals'),
                  'bibtex' => context.l('لـ LaTeX و Overleaf', 'For LaTeX and Overleaf'),
                  _ => context.l('لـ EndNote و Zotero و Mendeley', 'For EndNote, Zotero and Mendeley'),
                },
                style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
              ),
              const SizedBox(height: 10),
              Container(
                constraints: const BoxConstraints(maxHeight: 260),
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(color: scheme.surfaceContainerHighest.withOpacity(0.5), borderRadius: BorderRadius.circular(14)),
                child: SingleChildScrollView(
                  child: SelectableText(
                    text,
                    textDirection: !code && RegExp(r'[؀-ۿ]').hasMatch(text) ? TextDirection.rtl : TextDirection.ltr,
                    style: code
                        ? const TextStyle(fontFamily: 'monospace', fontSize: 12, height: 1.5)
                        : const TextStyle(fontFamily: 'serif', fontSize: 15, height: 1.6),
                  ),
                ),
              ),
              const SizedBox(height: 12),
              Row(children: [
                Expanded(
                  child: FilledButton.icon(
                    style: _copied ? FilledButton.styleFrom(backgroundColor: Colors.green.shade600) : null,
                    onPressed: () async {
                      await Clipboard.setData(ClipboardData(text: text));
                      if (mounted) setState(() => _copied = true);
                    },
                    icon: Icon(_copied ? Icons.check_rounded : Icons.copy_rounded),
                    label: Text(_copied ? context.l('تم النسخ', 'Copied') : context.l('نسخ', 'Copy')),
                  ),
                ),
                const SizedBox(width: 8),
                IconButton.outlined(
                  tooltip: context.l('مشاركة', 'Share'),
                  onPressed: () => Share.share(text, subject: widget.title),
                  icon: const Icon(Icons.ios_share_rounded),
                ),
                if (d['doi_url'] != null) ...[
                  const SizedBox(width: 8),
                  IconButton.outlined(
                    tooltip: context.l('المصدر الأصلي', 'Original source'),
                    onPressed: () => launchUrl(Uri.parse(d['doi_url'] as String), mode: LaunchMode.externalApplication),
                    icon: const Icon(Icons.open_in_new_rounded),
                  ),
                ],
              ]),
            ]);
          },
        ),
      ),
    );
  }
}
