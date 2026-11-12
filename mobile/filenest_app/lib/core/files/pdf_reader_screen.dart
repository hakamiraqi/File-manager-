import 'dart:async';
import 'dart:io';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_tts/flutter_tts.dart';
import 'package:open_filex/open_filex.dart';
import 'package:pdfrx/pdfrx.dart';
import 'package:share_plus/share_plus.dart';

import '../widgets/ui_kit.dart';
import 'reader_store.dart';

/// «قارئ PDF» in the app (2026-11-12, rebuilt on PDFium/pdfrx): Android, iPhone and Windows.
/// Select and copy text, search the whole file, highlight, underline and sticky notes,
/// bookmarks, resume where you stopped, night colours and reading aloud. Everything works
/// without internet; for a library file ([fileId]) the notes are the same ones the website's
/// reader shows, synced whenever there is a connection.
class PdfReaderScreen extends StatefulWidget {
  const PdfReaderScreen({super.key, required this.path, required this.title, this.fileId});

  final String path;
  final String title;
  final int? fileId;

  static bool get supported => Platform.isAndroid || Platform.isIOS || Platform.isWindows;

  @override
  State<PdfReaderScreen> createState() => _PdfReaderScreenState();
}

class _PdfReaderScreenState extends State<PdfReaderScreen> {
  final _controller = PdfViewerController();
  late final PdfTextSearcher _searcher = PdfTextSearcher(_controller)..addListener(_changed);
  late final ReaderStore _store = ReaderStore(path: widget.path, fileId: widget.fileId);
  ReaderData _data = ReaderData();
  bool _loaded = false;

  final _hasSelection = ValueNotifier<bool>(false);
  PdfTextSelection? _selection;

  bool _searching = false;
  final _searchCtrl = TextEditingController();
  bool _night = false;
  int _page = 1;
  int _pages = 0;
  Timer? _saveTimer;

  FlutterTts? _tts;
  bool _speaking = false;
  String _sentence = '';

  static const _colors = ['#facc15', '#4ade80', '#60a5fa', '#f472b6'];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final d = await _store.load();
    if (!mounted) return;
    setState(() {
      _data = d;
      _loaded = true;
    });
    // In the background: the server's copy, when reachable.
    final before = d.lastPage;
    if (await _store.sync(d) && mounted) {
      setState(() {});
      if (_controller.isReady) {
        if (d.lastPage != before && _page <= 1) _controller.goToPage(pageNumber: d.lastPage);
        _controller.invalidate();
      }
    }
  }

  void _changed() {
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    _saveTimer?.cancel();
    _persist(now: true);
    _searcher.removeListener(_changed);
    _searcher.dispose();
    _searchCtrl.dispose();
    _hasSelection.dispose();
    _tts?.stop();
    super.dispose();
  }

  // ---------------------------------------------------------------- saving

  void _touch() {
    _data.dirty = true;
    _persist();
    if (_controller.isReady) _controller.invalidate();
    if (mounted) setState(() {});
  }

  void _persist({bool now = false}) {
    _saveTimer?.cancel();
    Future<void> run() async {
      await _store.save(_data);
      if (_data.dirty) await _store.sync(_data);
    }

    if (now) {
      run();
    } else {
      _saveTimer = Timer(const Duration(seconds: 2), run);
    }
  }

  String _id() => '${DateTime.now().millisecondsSinceEpoch.toRadixString(36)}${math.Random().nextInt(1 << 20).toRadixString(36)}';

  // ---------------------------------------------------------------- bookmarks

  bool get _bookmarked => _data.bookmarks.any((b) => (b['page'] as num).toInt() == _page);

  void _toggleBookmark() {
    if (_bookmarked) {
      _data.bookmarks.removeWhere((b) => (b['page'] as num).toInt() == _page);
      _data.deleted.add('bookmark-$_page');
    } else {
      _data.deleted.remove('bookmark-$_page');
      _data.bookmarks
        ..add({'page': _page, 'title': ''})
        ..sort((a, b) => (a['page'] as num).compareTo(b['page'] as num));
    }
    _touch();
  }

  // ---------------------------------------------------------------- notes on selected text

  Future<void> _mark(String type, String color, {bool withNote = false}) async {
    final sel = _selection;
    if (sel == null) return;
    final ranges = await sel.getSelectedTextRanges();
    if (ranges.isEmpty || !_controller.isReady) return;
    String? note;
    if (withNote) {
      if (!mounted) return;
      note = await _askText(context.l('ملاحظة', 'Note'));
      if (note == null || note.trim().isEmpty) return;
    }
    for (final range in ranges) {
      final page = _controller.pages[range.pageNumber - 1];
      final k = 1000 / page.width;
      final rects = <List<double>>[];
      for (final f in range.enumerateFragmentBoundingRects()) {
        final r = f.bounds.toRect(page: page);
        if (r.width < 0.5 || r.height < 0.5) continue;
        rects.add([_r(r.left * k), _r(r.top * k), _r(r.width * k), _r(r.height * k)]);
      }
      if (rects.isEmpty) continue;
      final text = range.text;
      _data.annotations.add({
        'id': _id(),
        'type': type,
        'page': range.pageNumber,
        'color': color,
        'rects': rects,
        'text': text.length > 500 ? text.substring(0, 500) : text,
      });
      if (note != null) {
        final first = rects.first;
        _data.annotations.add({'id': _id(), 'type': 'note', 'page': range.pageNumber, 'x': first[0] + first[2], 'y': first[1], 'text': note.trim(), 'color': '#f59e0b'});
        note = null;
      }
    }
    await sel.clearTextSelection();
    _hasSelection.value = false;
    _touch();
  }

  static double _r(double v) => (v * 10).roundToDouble() / 10;

  Future<String?> _askText(String title) async {
    final c = TextEditingController();
    return showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(title),
        content: TextField(controller: c, autofocus: true, minLines: 3, maxLines: 6),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(ctx.l('إلغاء', 'Cancel'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, c.text), child: Text(ctx.l('حفظ', 'Save'))),
        ],
      ),
    );
  }

  Future<void> _addPageNote() async {
    final text = await _askText(context.l('ملاحظة على الصفحة $_page', 'Note on page $_page'));
    if (text == null || text.trim().isEmpty) return;
    _data.annotations.add({'id': _id(), 'type': 'note', 'page': _page, 'x': 40, 'y': 40, 'text': text.trim(), 'color': '#f59e0b'});
    _touch();
  }

  Future<void> _openNote(Map<String, dynamic> a) async {
    final c = TextEditingController(text: '${a['text'] ?? ''}');
    final text = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(ctx.l('ملاحظة · صفحة ${a['page']}', 'Note · page ${a['page']}')),
        content: TextField(controller: c, minLines: 3, maxLines: 8),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, ''), child: Text(ctx.l('حذف', 'Delete'), style: const TextStyle(color: Colors.red))),
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(ctx.l('إغلاق', 'Close'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, c.text), child: Text(ctx.l('حفظ', 'Save'))),
        ],
      ),
    );
    if (text == null) return;
    if (text.trim().isEmpty) {
      _remove(a);
    } else {
      a['text'] = text.trim();
      _touch();
    }
  }

  void _remove(Map<String, dynamic> a) {
    _data.annotations.removeWhere((x) => x['id'] == a['id']);
    _data.deleted.add('${a['id']}');
    _touch();
  }

  // ---------------------------------------------------------------- painting the notes

  static Color _color(Object? hex, [double opacity = 1]) {
    final s = '${hex ?? '#facc15'}'.replaceAll('#', '');
    final v = int.tryParse(s.length == 6 ? s : 'facc15', radix: 16) ?? 0xfacc15;
    return Color(0xFF000000 | v).withOpacity(opacity);
  }

  static double _n(Object? v) => v is num ? v.toDouble() : 0;

  void _paintNotes(Canvas canvas, Rect pageRect, PdfPage page) {
    final k = pageRect.width / 1000;
    Offset pt(Object? x, Object? y) => Offset(pageRect.left + _n(x) * k, pageRect.top + _n(y) * k);
    Rect box(Map<String, dynamic> a) => Rect.fromLTWH(pageRect.left + _n(a['x']) * k, pageRect.top + _n(a['y']) * k, _n(a['w']) * k, _n(a['h']) * k);
    for (final a in _data.annotations) {
      if ((a['page'] as num?)?.toInt() != page.pageNumber) continue;
      final type = '${a['type']}';
      try {
        if (type == 'highlight' || type == 'underline' || type == 'strike' || type == 'squiggly') {
          for (final r in (a['rects'] as List? ?? const [])) {
            final q = r as List;
            final rect = Rect.fromLTWH(pageRect.left + _n(q[0]) * k, pageRect.top + _n(q[1]) * k, _n(q[2]) * k, _n(q[3]) * k);
            if (type == 'highlight') {
              canvas.drawRect(rect, Paint()..color = _color(a['color'], 0.4));
            } else {
              final y = type == 'strike' ? rect.top + rect.height * 0.55 : rect.bottom - 0.5;
              final line = Paint()
                ..color = _color(a['color'])
                ..strokeWidth = math.max(1.2, rect.height * 0.09);
              canvas.drawLine(Offset(rect.left, y), Offset(rect.right, y), line);
            }
          }
        } else if (type == 'ink') {
          final pts = (a['points'] as List? ?? const []);
          if (pts.length < 2) continue;
          final first = pt((pts[0] as List)[0], (pts[0] as List)[1]);
          final path = Path()..moveTo(first.dx, first.dy);
          for (final p in pts.skip(1)) {
            final o = pt((p as List)[0], p[1]);
            path.lineTo(o.dx, o.dy);
          }
          final size = _n(a['size']);
          final pen = Paint()
            ..style = PaintingStyle.stroke
            ..strokeCap = StrokeCap.round
            ..strokeJoin = StrokeJoin.round
            ..strokeWidth = math.max(1.0, size * k)
            ..color = _color(a['color'], size >= 20 ? 0.4 : 1);
          canvas.drawPath(path, pen);
        } else if (type == 'redact') {
          canvas.drawRect(box(a), Paint()..color = Colors.black.withOpacity(0.8));
        } else if (type == 'rect' || type == 'ellipse') {
          final p = Paint()
            ..style = PaintingStyle.stroke
            ..strokeWidth = math.max(1.0, _n(a['size']) * k)
            ..color = _color(a['color']);
          if (type == 'rect') {
            canvas.drawRect(box(a), p);
          } else {
            canvas.drawOval(box(a), p);
          }
        } else if (type == 'line' || type == 'arrow') {
          final p = Paint()
            ..strokeCap = StrokeCap.round
            ..strokeWidth = math.max(1.0, _n(a['size']) * k)
            ..color = _color(a['color']);
          canvas.drawLine(pt(a['x1'], a['y1']), pt(a['x2'], a['y2']), p);
        } else if (type == 'text' || type == 'stamp') {
          final size = type == 'stamp' ? _n(a['h']) * 0.5 : (_n(a['size']) == 0 ? 22.0 : _n(a['size']));
          final tp = TextPainter(
            text: TextSpan(
              text: '${a['text'] ?? ''}',
              style: TextStyle(color: _color(a['color']), fontSize: size * k, fontWeight: type == 'stamp' ? FontWeight.w800 : FontWeight.w400),
            ),
            textDirection: TextDirection.ltr,
          )..layout();
          if (type == 'stamp') {
            final rect = box(a);
            final border = Paint()
              ..style = PaintingStyle.stroke
              ..strokeWidth = 4 * k
              ..color = _color(a['color']);
            canvas.drawRRect(RRect.fromRectAndRadius(rect, Radius.circular(10 * k)), border);
            tp.paint(canvas, rect.center - Offset(tp.width / 2, tp.height / 2));
          } else {
            tp.paint(canvas, pt(a['x'], a['y']));
          }
        } else if (type == 'sign') {
          final sx = _n(a['w']) * k / 600;
          final sy = _n(a['h']) * k / 240;
          final origin = pt(a['x'], a['y']);
          final pen = Paint()
            ..style = PaintingStyle.stroke
            ..strokeCap = StrokeCap.round
            ..strokeWidth = math.max(1.0, 5 * sx)
            ..color = const Color(0xFF1E3A8A);
          for (final s in (a['strokes'] as List? ?? const [])) {
            final pts = s as List;
            if (pts.length < 2) continue;
            final path = Path()..moveTo(origin.dx + _n((pts[0] as List)[0]) * sx, origin.dy + _n((pts[0] as List)[1]) * sy);
            for (final p in pts.skip(1)) {
              path.lineTo(origin.dx + _n((p as List)[0]) * sx, origin.dy + _n(p[1]) * sy);
            }
            canvas.drawPath(path, pen);
          }
        }
      } catch (_) {
        // A note this version does not understand is simply not drawn.
      }
    }
  }

  List<Widget> _noteIcons(BuildContext context, Rect pageRect, PdfPage page) {
    final k = pageRect.width / 1000;
    final size = math.max(18.0, math.min(30.0, 52 * k));
    return [
      for (final a in _data.annotations)
        if (a['type'] == 'note' && (a['page'] as num?)?.toInt() == page.pageNumber)
          Positioned(
            left: math.max(0.0, math.min(pageRect.width - size, _n(a['x']) * k)),
            top: math.max(0.0, math.min(pageRect.height - size, _n(a['y']) * k)),
            child: GestureDetector(
              onTap: () => _openNote(a),
              child: Container(
                width: size,
                height: size,
                decoration: BoxDecoration(
                  color: _color(a['color'] ?? '#f59e0b'),
                  borderRadius: BorderRadius.circular(5),
                  boxShadow: const [BoxShadow(blurRadius: 2, color: Colors.black38)],
                ),
                child: Icon(Icons.sticky_note_2_outlined, size: size * 0.7, color: const Color(0xFF78350F)),
              ),
            ),
          ),
    ];
  }

  // ---------------------------------------------------------------- lists

  String _label(BuildContext ctx, Map<String, dynamic> a) {
    switch ('${a['type']}') {
      case 'highlight':
        return ctx.l('تظليل', 'Highlight');
      case 'underline':
        return ctx.l('تسطير', 'Underline');
      case 'strike':
        return ctx.l('شطب', 'Strike');
      case 'note':
        return ctx.l('ملاحظة', 'Note');
      case 'ink':
        return ctx.l('قلم', 'Pen');
      case 'sign':
        return ctx.l('توقيع', 'Signature');
      default:
        return ctx.l('تعليق', 'Mark');
    }
  }

  void _showLists() {
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (ctx) => DraggableScrollableSheet(
        expand: false,
        initialChildSize: 0.6,
        maxChildSize: 0.9,
        builder: (ctx, scroll) => StatefulBuilder(builder: (ctx, set) {
          final notes = [..._data.annotations]..sort((a, b) => (a['page'] as num).compareTo(b['page'] as num));
          return ListView(controller: scroll, children: [
            ListTile(dense: true, title: Text(ctx.l('العلامات المرجعية', 'Bookmarks'), style: const TextStyle(fontWeight: FontWeight.w700))),
            if (_data.bookmarks.isEmpty) ListTile(dense: true, title: Text(ctx.l('لا علامات بعد.', 'No bookmarks yet.'), style: Theme.of(ctx).textTheme.bodySmall)),
            for (final b in _data.bookmarks)
              ListTile(
                leading: const Icon(Icons.bookmark_rounded),
                title: Text('${'${b['title'] ?? ''}'.isEmpty ? ctx.l('صفحة', 'Page') : b['title']} ${b['page']}'),
                onTap: () {
                  Navigator.pop(ctx);
                  _controller.goToPage(pageNumber: (b['page'] as num).toInt());
                },
              ),
            const Divider(),
            ListTile(dense: true, title: Text(ctx.l('ملاحظاتي', 'My notes'), style: const TextStyle(fontWeight: FontWeight.w700))),
            if (notes.isEmpty)
              ListTile(dense: true, title: Text(ctx.l('حدّد نصاً لتظليله أو لإضافة ملاحظة.', 'Select text to highlight it or add a note.'), style: Theme.of(ctx).textTheme.bodySmall)),
            for (final a in notes)
              ListTile(
                leading: Container(width: 14, height: 14, decoration: BoxDecoration(color: _color(a['color'] ?? '#f59e0b'), borderRadius: BorderRadius.circular(3))),
                title: Text('${_label(ctx, a)} · ${ctx.l('صفحة', 'page')} ${a['page']}', style: Theme.of(ctx).textTheme.bodySmall),
                subtitle: '${a['text'] ?? ''}'.isEmpty ? null : Text('${a['text']}', maxLines: 3, overflow: TextOverflow.ellipsis),
                trailing: IconButton(
                  icon: const Icon(Icons.delete_outline_rounded),
                  onPressed: () {
                    _remove(a);
                    set(() {});
                  },
                ),
                onTap: () {
                  Navigator.pop(ctx);
                  _controller.goToPage(pageNumber: (a['page'] as num).toInt());
                },
              ),
          ]);
        }),
      ),
    );
  }

  Future<void> _goTo() async {
    final c = TextEditingController();
    final n = await showDialog<int>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(ctx.l('اذهب إلى صفحة', 'Go to page')),
        content: TextField(
          controller: c,
          autofocus: true,
          keyboardType: TextInputType.number,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          decoration: InputDecoration(hintText: '1 – $_pages'),
          onSubmitted: (v) => Navigator.pop(ctx, int.tryParse(v)),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(ctx.l('إلغاء', 'Cancel'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, int.tryParse(c.text)), child: Text(ctx.l('اذهب', 'Go'))),
        ],
      ),
    );
    if (n == null || n < 1 || n > _pages) return;
    _controller.goToPage(pageNumber: n);
  }

  // ---------------------------------------------------------------- reading aloud

  Future<void> _listen() async {
    if (_speaking) {
      await _stopListening();
      return;
    }
    final tts = _tts ??= FlutterTts();
    setState(() => _speaking = true);
    try {
      await tts.awaitSpeakCompletion(true);
      var spoke = false;
      for (var n = _page; n <= _pages && _speaking; n++) {
        final raw = await _controller.pages[n - 1].loadText();
        final text = (raw?.fullText ?? '').replaceAll(RegExp(r'\s+'), ' ').trim();
        if (text.isEmpty) continue;
        if (n != _page) await _controller.goToPage(pageNumber: n);
        await tts.setLanguage(RegExp(r'[؀-ۿ]').hasMatch(text) ? 'ar' : 'en-US');
        for (final part in _sentences(text)) {
          if (!_speaking) break;
          if (mounted) setState(() => _sentence = part);
          await tts.speak(part);
          spoke = true;
        }
      }
      if (!spoke && mounted) context.toast(context.l('لا يوجد نص لقراءته في هذا الملف.', 'There is no text to read in this file.'));
    } catch (_) {
      if (mounted) context.toast(context.l('القراءة بالصوت غير متاحة على هذا الجهاز.', 'Reading aloud is not available on this device.'), error: true);
    }
    if (mounted) {
      setState(() {
        _speaking = false;
        _sentence = '';
      });
    }
  }

  Future<void> _stopListening() async {
    setState(() {
      _speaking = false;
      _sentence = '';
    });
    await _tts?.stop();
  }

  static List<String> _sentences(String text) {
    final out = <String>[];
    for (final s in text.split(RegExp(r'(?<=[.!?؟؛:])\s+'))) {
      var rest = s.trim();
      while (rest.length > 300) {
        var cut = rest.lastIndexOf(' ', 300);
        if (cut < 100) cut = 300;
        out.add(rest.substring(0, cut));
        rest = rest.substring(cut).trim();
      }
      if (rest.isNotEmpty) out.add(rest);
    }
    return out;
  }

  // ---------------------------------------------------------------- UI

  Future<void> _openElsewhere() async {
    final r = await OpenFilex.open(widget.path);
    if (r.type != ResultType.done && mounted) {
      context.toast(context.l('لا يوجد برنامج لفتح الملف على هذا الجهاز.', 'No app on this device can open the file.'), error: true);
    }
  }

  void _menu(String v) {
    if (v == 'lists') {
      _showLists();
    } else if (v == 'goto') {
      _goTo();
    } else if (v == 'note') {
      _addPageNote();
    } else if (v == 'night') {
      setState(() => _night = !_night);
    } else if (v == 'share') {
      Share.shareXFiles([XFile(widget.path)]);
    } else if (v == 'open') {
      _openElsewhere();
    }
  }

  static const _invert = ColorFilter.matrix(<double>[-0.92, 0, 0, 0, 245, 0, -0.92, 0, 0, 245, 0, 0, -0.92, 0, 245, 0, 0, 0, 1, 0]);

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    final background = _night || dark ? const Color(0xFF111111) : const Color(0xFFE5E7EB);
    return Scaffold(
      backgroundColor: background,
      appBar: AppBar(
        title: _searching
            ? TextField(
                controller: _searchCtrl,
                autofocus: true,
                textInputAction: TextInputAction.search,
                decoration: InputDecoration(hintText: context.l('ابحث داخل الملف', 'Search in the file'), border: InputBorder.none),
                onSubmitted: (q) {
                  if (q.trim().isEmpty) {
                    _searcher.resetTextSearch();
                  } else {
                    _searcher.startTextSearch(q.trim(), caseInsensitive: true);
                  }
                },
              )
            : Text(widget.title, maxLines: 1, overflow: TextOverflow.ellipsis),
        actions: [
          if (_searching && _searcher.matches.isNotEmpty) ...[
            Center(child: Text('${(_searcher.currentIndex ?? 0) + 1}/${_searcher.matches.length}', style: Theme.of(context).textTheme.bodySmall)),
            IconButton(icon: const Icon(Icons.keyboard_arrow_up_rounded), onPressed: () => _searcher.goToPrevMatch()),
            IconButton(icon: const Icon(Icons.keyboard_arrow_down_rounded), onPressed: () => _searcher.goToNextMatch()),
          ],
          if (_searching && _searcher.isSearching)
            const Padding(padding: EdgeInsets.all(14), child: SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))),
          IconButton(
            tooltip: context.l('بحث', 'Search'),
            icon: Icon(_searching ? Icons.close_rounded : Icons.search_rounded),
            onPressed: () => setState(() {
              _searching = !_searching;
              if (!_searching) {
                _searchCtrl.clear();
                _searcher.resetTextSearch();
              }
            }),
          ),
          if (!_searching) ...[
            IconButton(
              tooltip: context.l('علامة مرجعية', 'Bookmark'),
              icon: Icon(_bookmarked ? Icons.bookmark_rounded : Icons.bookmark_border_rounded),
              onPressed: _pages == 0 ? null : _toggleBookmark,
            ),
            IconButton(
              tooltip: context.l('استماع', 'Listen'),
              icon: Icon(_speaking ? Icons.stop_circle_outlined : Icons.volume_up_outlined),
              onPressed: _pages == 0 ? null : _listen,
            ),
            PopupMenuButton<String>(
              onSelected: _menu,
              itemBuilder: (_) => [
                PopupMenuItem(value: 'lists', child: Text(context.l('العلامات والملاحظات', 'Bookmarks & notes'))),
                PopupMenuItem(value: 'goto', child: Text(context.l('اذهب إلى صفحة', 'Go to page'))),
                PopupMenuItem(value: 'note', child: Text(context.l('ملاحظة على هذه الصفحة', 'Note on this page'))),
                PopupMenuItem(value: 'night', child: Text(_night ? context.l('الوضع العادي', 'Normal colours') : context.l('الوضع الليلي', 'Night colours'))),
                PopupMenuItem(value: 'share', child: Text(context.l('مشاركة الملف', 'Share the file'))),
                PopupMenuItem(value: 'open', child: Text(context.l('فتح ببرنامج آخر', 'Open in another app'))),
              ],
            ),
          ],
        ],
      ),
      body: !_loaded
          ? const Center(child: CircularProgressIndicator())
          : Stack(children: [
              PdfViewer.file(
                widget.path,
                controller: _controller,
                initialPageNumber: _data.lastPage,
                params: PdfViewerParams(
                  backgroundColor: background,
                  pageImageColorFilter: _night ? _invert : null,
                  textSelectionParams: PdfTextSelectionParams(
                    enabled: true,
                    onTextSelectionChange: (sel) {
                      _selection = sel;
                      final has = sel.hasSelectedText;
                      scheduleMicrotask(() {
                        if (mounted) _hasSelection.value = has;
                      });
                    },
                  ),
                  pagePaintCallbacks: [_searcher.pageTextMatchPaintCallback, _paintNotes],
                  pageOverlaysBuilder: _noteIcons,
                  onViewerReady: (document, controller) {
                    if (!mounted) return;
                    setState(() {
                      _pages = document.pages.length;
                      _page = controller.pageNumber ?? 1;
                    });
                    if (_data.lastPage > 1 && _data.lastPage <= _pages) {
                      context.toast(context.l('تابعتَ من الصفحة ${_data.lastPage}', 'Resumed at page ${_data.lastPage}'));
                    }
                  },
                  onPageChanged: (n) {
                    if (n == null || !mounted) return;
                    setState(() => _page = n);
                    if (_data.lastPage != n) {
                      _data.lastPage = n;
                      _data.dirty = true;
                      _persist();
                    }
                  },
                ),
              ),
              if (_pages > 0)
                Positioned(
                  bottom: 12,
                  left: 0,
                  right: 0,
                  child: IgnorePointer(
                    child: Center(
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                        decoration: BoxDecoration(color: Colors.black.withOpacity(0.6), borderRadius: BorderRadius.circular(20)),
                        child: Text('$_page / $_pages', style: const TextStyle(color: Colors.white, fontSize: 12)),
                      ),
                    ),
                  ),
                ),
              if (_speaking)
                Positioned(
                  left: 12,
                  right: 12,
                  bottom: 44,
                  child: Material(
                    elevation: 6,
                    borderRadius: BorderRadius.circular(16),
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(14, 8, 6, 8),
                      child: Row(children: [
                        const Icon(Icons.volume_up_rounded, size: 20),
                        const SizedBox(width: 10),
                        Expanded(child: Text(_sentence, maxLines: 2, overflow: TextOverflow.ellipsis, style: Theme.of(context).textTheme.bodySmall)),
                        IconButton(icon: const Icon(Icons.stop_rounded), onPressed: _stopListening),
                      ]),
                    ),
                  ),
                ),
              // Selected text: highlight, underline, note.
              Positioned(
                left: 12,
                right: 12,
                top: 8,
                child: ValueListenableBuilder<bool>(
                  valueListenable: _hasSelection,
                  builder: (context, has, _) => !has
                      ? const SizedBox.shrink()
                      : Center(
                          child: Material(
                            elevation: 6,
                            borderRadius: BorderRadius.circular(28),
                            child: Padding(
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                              child: Row(mainAxisSize: MainAxisSize.min, children: [
                                for (final c in _colors)
                                  InkWell(
                                    customBorder: const CircleBorder(),
                                    onTap: () => _mark('highlight', c),
                                    child: Padding(
                                      padding: const EdgeInsets.all(6),
                                      child: Container(
                                        width: 26,
                                        height: 26,
                                        decoration: BoxDecoration(color: _color(c), shape: BoxShape.circle, border: Border.all(color: Colors.black12)),
                                      ),
                                    ),
                                  ),
                                IconButton(tooltip: context.l('تسطير', 'Underline'), icon: const Icon(Icons.format_underlined_rounded), onPressed: () => _mark('underline', '#2563eb')),
                                IconButton(tooltip: context.l('ملاحظة', 'Note'), icon: const Icon(Icons.sticky_note_2_outlined), onPressed: () => _mark('highlight', '#fde68a', withNote: true)),
                              ]),
                            ),
                          ),
                        ),
                ),
              ),
            ]),
    );
  }
}
